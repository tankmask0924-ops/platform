<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace HyperfTest\Cases\Admin;

use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use App\Model\AdminUser;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantLevel;
use App\Model\MerchantRebate;
use App\Model\Order;
use App\Model\OrderMovie;
use App\Model\Supplier;
use HyperfTest\HttpTestCase;

/**
 * 系统管理后台「财务报表」（requirements.md 8.3）：订单毛利与返佣收支分开统计、
 * 按商户/等级/业务线/供应商分组，以及资金流水汇总。
 *
 * 测试库是共享的，别的用例也会留下订单和返佣：所有断言都按本用例建的商户过滤
 * （`merchant_id=`），日期也用一段远离今天的历史区间。
 *
 * @internal
 * @coversNothing
 */
class ReportControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private const FROM = '2019-07-01';

    private const TO = '2019-07-03';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $merchantIds = [];

    private array $levelIds = [];

    private array $supplierIds = [];

    private array $orderIds = [];

    protected function tearDown(): void
    {
        MerchantRebate::whereIn('order_id', $this->orderIds ?: [0])->delete();
        OrderMovie::whereIn('order_id', $this->orderIds ?: [0])->delete();
        MerchantBalanceLog::whereIn('merchant_id', $this->merchantIds ?: [0])->delete();
        Order::destroy($this->orderIds);
        Supplier::destroy($this->supplierIds);
        Merchant::destroy($this->merchantIds);
        MerchantLevel::destroy($this->levelIds);
        AdminUser::destroy($this->adminUserIds);
        AdminRolePermission::whereIn('role_id', $this->roleIds ?: [0])->delete();
        AdminRole::destroy($this->roleIds);
        AdminPermission::destroy($this->permissionIds);
        $this->orderIds = $this->supplierIds = $this->merchantIds = $this->levelIds = [];

        parent::tearDown();
    }

    /**
     * 毛利和返佣分开统计、最后相加（requirements.md 1.2 的例子：售价 99.20、
     * 成本 98.50、商户返佣 0.50 → 毛利 0.70、返佣收支 -0.50、合计 0.20）。
     */
    public function testProfitAndRebateAreReportedSeparatelyAndSummed()
    {
        $token = $this->loginWith(['report.view']);
        $merchant = $this->createMerchant();
        $supplier = $this->createSupplier();
        $order = $this->createOrder($merchant, $supplier, 'success', '99.20', '98.50', self::FROM . ' 10:00:00');
        $this->createRebate($order, $merchant, '0.50', 'pending');

        $report = $this->getJson('/admin/reports/profit?group_by=day&from=' . self::FROM . '&to=' . self::TO . '&merchant_id=' . $merchant->id, $token);

        $this->assertSame('day', $report['group_by']);
        $this->assertCount(3, $report['data'], '没有订单的日期也补齐');

        $summary = $report['summary'];
        $this->assertSame(1, $summary['orders']);
        $this->assertSame('99.20', $summary['sale_total']);
        $this->assertSame('98.50', $summary['cost_total']);
        $this->assertSame('0.70', $summary['gross_profit']);
        $this->assertSame('0.50', $summary['merchant_rebate']);
        $this->assertSame('0.00', $summary['supplier_rebate'], '话费卡券没有供应商返佣');
        $this->assertSame('-0.50', $summary['rebate_balance'], '返佣收支只有支出，是负数');
        $this->assertSame('0.20', $summary['total_profit']);

        $day = $report['data'][0];
        $this->assertSame(self::FROM, $day['key']);
        $this->assertSame('0.70', $day['gross_profit']);
        $this->assertSame('0.00', $report['data'][1]['gross_profit'], '空日期补 0');
    }

    /**
     * 电影票的供应商返佣计入返佣收支（requirements.md 5.3 例 2：供应商返佣 3.00、商户返佣 2.40
     * → 返佣收支 0.60）；已退款的电影票订单，供应商返佣不算。
     */
    public function testMovieSupplierRebateIsCountedInRebateBalance()
    {
        $token = $this->loginWith(['report.view']);
        $merchant = $this->createMerchant();
        $supplier = $this->createSupplier();
        $order = $this->createOrder($merchant, $supplier, 'success', '40.00', '38.00', self::FROM . ' 10:00:00', 'movie');
        $this->createMovie($order, '3.00');
        $this->createRebate($order, $merchant, '2.40', 'pending');
        $refunded = $this->createOrder($merchant, $supplier, 'refunded', '40.00', '38.00', self::FROM . ' 11:00:00', 'movie');
        $this->createMovie($refunded, '3.00');

        $report = $this->getJson('/admin/reports/profit?group_by=business_line&from=' . self::FROM . '&to=' . self::TO . '&merchant_id=' . $merchant->id, $token);

        $summary = $report['summary'];
        $this->assertSame('3.00', $summary['supplier_rebate']);
        $this->assertSame('2.40', $summary['merchant_rebate']);
        $this->assertSame('0.60', $summary['rebate_balance']);
        $this->assertSame('2.60', $summary['total_profit'], '毛利 2.00 + 返佣收支 0.60');
        $this->assertSame('movie', $report['data'][0]['key']);
        $this->assertSame('3.00', $report['data'][0]['supplier_rebate']);
    }

    /**
     * 已退款订单不计毛利，但要单独成列——藏起来会让人以为这天风平浪静。
     */
    public function testRefundedOrdersAreExcludedFromProfitButReportedSeparately()
    {
        $token = $this->loginWith(['report.view']);
        $merchant = $this->createMerchant();
        $supplier = $this->createSupplier();
        $this->createOrder($merchant, $supplier, 'success', '10.00', '8.00', self::FROM . ' 10:00:00');
        $refunded = $this->createOrder($merchant, $supplier, 'refunded', '10.00', '8.00', self::FROM . ' 11:00:00');
        Order::where('id', $refunded->id)->update(['refunded_amount' => '10.00']);

        $summary = $this->getJson('/admin/reports/profit?from=' . self::FROM . '&to=' . self::TO . '&merchant_id=' . $merchant->id, $token)['summary'];

        $this->assertSame(1, $summary['orders'], '已退款的不算成功笔数');
        $this->assertSame('2.00', $summary['gross_profit'], '只有成功那笔的毛利');
        $this->assertSame(1, $summary['refunded_count']);
        $this->assertSame('10.00', $summary['refunded_amount']);
    }

    /**
     * 作废和已扣回的返佣不算支出：钱没出去。
     */
    public function testVoidedAndClawedBackRebatesAreNotCountedAsExpense()
    {
        $token = $this->loginWith(['report.view']);
        $merchant = $this->createMerchant();
        $supplier = $this->createSupplier();
        $settled = $this->createOrder($merchant, $supplier, 'success', '10.00', '8.00', self::FROM . ' 10:00:00');
        $voided = $this->createOrder($merchant, $supplier, 'success', '10.00', '8.00', self::FROM . ' 11:00:00');
        $clawed = $this->createOrder($merchant, $supplier, 'success', '10.00', '8.00', self::FROM . ' 12:00:00');
        $this->createRebate($settled, $merchant, '0.50', 'settled');
        $this->createRebate($voided, $merchant, '0.50', 'voided');
        $this->createRebate($clawed, $merchant, '0.50', 'clawed_back');

        $summary = $this->getJson('/admin/reports/profit?from=' . self::FROM . '&to=' . self::TO . '&merchant_id=' . $merchant->id, $token)['summary'];

        $this->assertSame('0.50', $summary['merchant_rebate'], '只算 pending + settled');
        $this->assertSame('6.00', $summary['gross_profit']);
        $this->assertSame('5.50', $summary['total_profit']);
    }

    /**
     * 按商户/等级/业务线/供应商分组，分组键带上可读名称。
     */
    public function testGroupingByMerchantLevelBusinessLineAndSupplier()
    {
        $token = $this->loginWith(['report.view']);
        $level = MerchantLevel::create(['name' => 'lv_' . uniqid('', true)]);
        $this->levelIds[] = $level->id;
        $merchant = $this->createMerchant($level->id);
        $supplier = $this->createSupplier();
        $recharge = $this->createOrder($merchant, $supplier, 'success', '10.00', '8.00', self::FROM . ' 10:00:00');
        $this->createOrder($merchant, $supplier, 'success', '20.00', '15.00', self::FROM . ' 11:00:00', 'card');
        $this->createRebate($recharge, $merchant, '0.30', 'pending');

        $byMerchant = $this->getJson('/admin/reports/profit?group_by=merchant&from=' . self::FROM . '&to=' . self::TO . '&merchant_id=' . $merchant->id, $token);
        $this->assertCount(1, $byMerchant['data']);
        $this->assertSame((string) $merchant->id, $byMerchant['data'][0]['key']);
        $this->assertSame($merchant->phone, $byMerchant['data'][0]['label'], '商户按手机号/邮箱显示');
        $this->assertSame('7.00', $byMerchant['data'][0]['gross_profit']);

        $byLevel = $this->getJson('/admin/reports/profit?group_by=level&from=' . self::FROM . '&to=' . self::TO . '&merchant_id=' . $merchant->id, $token);
        $this->assertSame((string) $level->id, $byLevel['data'][0]['key']);
        $this->assertSame($level->name, $byLevel['data'][0]['label']);

        $bySupplier = $this->getJson('/admin/reports/profit?group_by=supplier&from=' . self::FROM . '&to=' . self::TO . '&merchant_id=' . $merchant->id, $token);
        $this->assertSame((string) $supplier->id, $bySupplier['data'][0]['key']);
        $this->assertSame($supplier->name, $bySupplier['data'][0]['label']);

        $byLine = $this->getJson('/admin/reports/profit?group_by=business_line&from=' . self::FROM . '&to=' . self::TO . '&merchant_id=' . $merchant->id, $token);
        $lines = array_column($byLine['data'], 'gross_profit', 'key');
        $this->assertSame('2.00', $lines['recharge']);
        $this->assertSame('5.00', $lines['card']);
        $this->assertSame('0.30', array_column($byLine['data'], 'merchant_rebate', 'key')['recharge']);
        $this->assertSame('0.00', array_column($byLine['data'], 'merchant_rebate', 'key')['card'], '没返佣的业务线补 0');
    }

    /**
     * 资金流水汇总：按类型给笔数和金额合计，调账那一行是带符号的净额。
     */
    public function testBalanceFlowSummaryGroupsByType()
    {
        $token = $this->loginWith(['report.view']);
        $merchant = $this->createMerchant();
        $this->createBalanceLog($merchant, 'recharge', '100.00', self::FROM . ' 09:00:00');
        $this->createBalanceLog($merchant, 'recharge', '50.00', self::FROM . ' 09:30:00');
        $this->createBalanceLog($merchant, 'adjustment', '20.00', self::FROM . ' 10:00:00');
        $this->createBalanceLog($merchant, 'adjustment', '-5.00', self::FROM . ' 10:30:00');
        // 区间外的不算
        $this->createBalanceLog($merchant, 'recharge', '999.00', '2019-06-01 09:00:00');

        $report = $this->getJson('/admin/reports/balance-flows?from=' . self::FROM . '&to=' . self::TO . '&merchant_id=' . $merchant->id, $token);

        $byType = array_column($report['data'], null, 'type');
        $this->assertSame(2, $byType['recharge']['count']);
        $this->assertSame('150.00', $byType['recharge']['amount']);
        $this->assertSame(2, $byType['adjustment']['count']);
        $this->assertSame('15.00', $byType['adjustment']['amount'], '调账是带符号的净额');
        $this->assertSame(4, $report['total_count']);
    }

    public function testInvalidParametersAreRejected()
    {
        $token = $this->loginWith(['report.view']);

        foreach ([
            '/admin/reports/profit?group_by=nope',
            '/admin/reports/profit?from=not-a-date',
            '/admin/reports/profit?from=2019-07-10&to=2019-07-01',
            '/admin/reports/profit?from=2019-01-01&to=2019-12-31',
            '/admin/reports/profit?merchant_id=abc',
            '/admin/reports/balance-flows?from=not-a-date',
        ] as $path) {
            $response = $this->client->request('GET', $path, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
            $this->assertSame(422, $response->getStatusCode(), $path);
        }
    }

    public function testReportsRequireTheReportPermission()
    {
        $other = $this->loginWith(['order.view']);

        foreach (['/admin/reports/profit', '/admin/reports/balance-flows'] as $path) {
            $response = $this->client->request('GET', $path, ['headers' => ['Authorization' => 'Bearer ' . $other]]);
            $this->assertSame(403, $response->getStatusCode(), $path);
        }
    }

    private function createMerchant(?int $levelId = null): Merchant
    {
        $unique = uniqid('report_test_', true);
        $merchant = Merchant::create([
            'type' => 'company',
            'phone' => substr('13' . preg_replace('/\D/', '', $unique), 0, 11),
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'level_id' => $levelId,
            'app_key' => 'app_key_' . $unique,
            'app_secret' => 'encrypted-secret-placeholder',
            'available_balance' => '100.00',
            'frozen_balance' => '0.00',
        ]);
        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createSupplier(): Supplier
    {
        $unique = uniqid('report_test_supplier_', true);
        $supplier = Supplier::create([
            'name' => $unique,
            'code' => substr(md5($unique), 0, 24),
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => 'unused-in-report',
            'status' => 'active',
        ]);
        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }

    private function createOrder(
        Merchant $merchant,
        Supplier $supplier,
        string $status,
        string $salePrice,
        string $costPrice,
        string $completedAt,
        string $businessLine = 'recharge'
    ): Order {
        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => $businessLine,
            'status' => $status,
            'sale_price' => $salePrice,
            'cost_price' => $costPrice,
            'supplier_id' => $supplier->id,
            'frozen_amount' => $salePrice,
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
            'completed_at' => $completedAt,
            'finished_at' => $completedAt,
        ]);
        $this->orderIds[] = $order->id;

        return $order;
    }

    private function createMovie(Order $order, ?string $supplierRebate): void
    {
        OrderMovie::create([
            'order_id' => $order->id, 'cinema_id' => 'C1', 'film_id' => 'F1', 'show_id' => 'S1',
            'show_time' => '2026-09-30 19:30:00', 'seats' => [['seat_code' => '1-3']], 'seat_count' => 1,
            'unit_price' => '40.00', 'unit_cost' => '38.00', 'mobile' => '13800000000',
            'lock_expire_at' => date('Y-m-d H:i:s'), 'supplier_rebate' => $supplierRebate,
        ]);
    }

    private function createRebate(Order $order, Merchant $merchant, string $amount, string $status): void
    {
        MerchantRebate::create([
            'order_id' => $order->id,
            'merchant_id' => $merchant->id,
            'business_line' => $order->business_line,
            'level_id' => $merchant->level_id ?? 0,
            'rebate_base' => '1.00',
            'rebate_base_source' => 'product',
            'rebate_rate' => '0.5000',
            'rebate_rate_source' => 'level',
            'amount' => $amount,
            'status' => $status,
            'order_completed_at' => $order->completed_at?->toDateTimeString(),
        ]);
    }

    private function createBalanceLog(Merchant $merchant, string $type, string $amount, string $createdAt): void
    {
        MerchantBalanceLog::create([
            'merchant_id' => $merchant->id,
            'type' => $type,
            'amount' => $amount,
            'available_before' => '100.00',
            'available_after' => '100.00',
            'frozen_before' => '0.00',
            'frozen_after' => '0.00',
            'created_at' => $createdAt,
        ]);
    }

    private function getJson(string $path, string $token): array
    {
        $response = $this->client->request('GET', $path, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * @param list<string> $codes
     */
    private function loginWith(array $codes): string
    {
        $role = AdminRole::create(['name' => 'role_' . uniqid('', true), 'is_system' => false]);
        $this->roleIds[] = $role->id;
        foreach ($codes as $code) {
            $permission = AdminPermission::firstOrCreate(['code' => $code], ['module' => 'report', 'name' => $code, 'type' => 'action']);
            if ($permission->wasRecentlyCreated) {
                $this->permissionIds[] = $permission->id;
            }
            AdminRolePermission::create(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }

        $admin = AdminUser::create([
            'username' => 'admin_' . uniqid('', true),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'real_name' => 'Test Admin',
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $this->adminUserIds[] = $admin->id;

        $login = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => ['username' => $admin->username, 'password' => self::PASSWORD],
        ]);

        return json_decode((string) $login->getBody(), true)['token'];
    }
}
