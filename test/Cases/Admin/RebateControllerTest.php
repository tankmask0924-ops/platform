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

use App\Model\Merchant;
use App\Model\MerchantLevel;
use App\Model\MerchantRebate;
use App\Model\Order;
use App\Model\OrderMovie;
use App\Model\SystemSetting;
use HyperfTest\HttpTestCase;

/**
 * 系统后台「返佣管理 - 商户返佣明细」`GET /admin/rebates`（requirements.md 8.3）：
 * 权限、按商户筛选、平台内部字段（返佣基数和比例来源、等级名）、当前返佣期限。
 *
 * @internal
 * @coversNothing
 */
class RebateControllerTest extends HttpTestCase
{
    use CreatesAdmins;

    private const PASSWORD = 'correct-password';

    private array $merchantIds = [];

    private array $orderIds = [];

    private array $levelIds = [];

    protected function tearDown(): void
    {
        MerchantRebate::whereIn('order_id', $this->orderIds)->delete();
        OrderMovie::whereIn('order_id', $this->orderIds ?: [0])->delete();
        Order::destroy($this->orderIds);
        Merchant::destroy($this->merchantIds);
        MerchantLevel::destroy($this->levelIds);
        $this->cleanUpAdmins();

        parent::tearDown();
    }

    public function testRequiresRebateViewPermission()
    {
        $this->assertSame(401, $this->jsonRequest('GET', '/admin/rebates', null)->getStatusCode());

        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        $this->assertSame(403, $this->jsonRequest('GET', '/admin/rebates', $token)->getStatusCode());
    }

    public function testFiltersByMerchantAndReturnsInternalFields()
    {
        $level = MerchantLevel::create(['name' => 'rebate_test_' . uniqid()]);
        $this->levelIds[] = $level->id;
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        $rebate = $this->createRebate($merchant, $level->id, 'clawed_back', '0.80');
        $this->createRebate($other, $level->id, 'pending', '1.00');
        $token = $this->loginAs($this->createAdminWithPermissions(['rebate.view']));

        $response = $this->jsonRequest('GET', '/admin/rebates?merchant_id=' . $merchant->id, $token);
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $body['total']);
        $this->assertSame(['count' => 1, 'amount' => '0.80'], $body['summary']['clawed_back']);
        $row = $body['data'][0];
        $this->assertSame($rebate->id, $row['id']);
        $this->assertSame($merchant->id, $row['merchant_id']);
        $this->assertSame($merchant->phone, $row['merchant_phone']);
        $this->assertSame($level->name, $row['level_name']);
        $this->assertSame('1.60', $row['rebate_base']);
        $this->assertSame('product', $row['rebate_base_source']);
        $this->assertSame('product_level', $row['rebate_rate_source']);
        $this->assertIsInt($body['due_period_days']);

        $this->assertSame(422, $this->jsonRequest('GET', '/admin/rebates?merchant_id=abc', $token)->getStatusCode());
    }

    /**
     * 供应商返佣明细：只列电影票、快递的成功订单，供应商返佣没给的显示 null，
     * 汇总里返佣收支 = 供应商返佣 − 商户返佣（作废的商户返佣不减）。
     */
    public function testSupplierRebateListShowsMovieRebatesAndBalance()
    {
        $level = MerchantLevel::create(['name' => 'rebate_test_' . uniqid()]);
        $this->levelIds[] = $level->id;
        $merchant = $this->createMerchant();
        $withRebate = $this->createMovieOrder($merchant, '3.00', 'success');
        $this->createRebate($merchant, $level->id, 'pending', '2.40', $withRebate);
        $voided = $this->createMovieOrder($merchant, '1.00', 'success');
        $this->createRebate($merchant, $level->id, 'voided', '0.80', $voided);
        $missing = $this->createMovieOrder($merchant, null, 'success');
        $this->createMovieOrder($merchant, '5.00', 'refunded');
        $this->createRebate($merchant, $level->id, 'pending', '1.00');
        $token = $this->loginAs($this->createAdminWithPermissions(['rebate.view']));

        $body = $this->body($this->jsonRequest('GET', '/admin/rebates/supplier?merchant_id=' . $merchant->id, $token));

        $this->assertSame(3, $body['total'], '话费订单、已退款的电影票订单不列');
        $this->assertSame([
            'count' => 3, 'returned_count' => 2, 'missing_count' => 1,
            'supplier_rebate' => '4.00', 'merchant_rebate' => '2.40', 'rebate_balance' => '1.60',
        ], $body['summary']);
        $rows = array_column($body['data'], null, 'order_id');
        $this->assertSame('3.00', $rows[$withRebate->id]['supplier_rebate']);
        $this->assertSame('2.40', $rows[$withRebate->id]['merchant_rebate']);
        $this->assertSame('0.60', $rows[$withRebate->id]['rebate_balance']);
        $this->assertSame('1.00', $rows[$voided->id]['rebate_balance'], '作废的商户返佣不从供应商返佣里减');
        $this->assertNull($rows[$missing->id]['supplier_rebate']);
        $this->assertSame($merchant->phone, $rows[$missing->id]['merchant_contact']);

        $onlyMissing = $this->body($this->jsonRequest('GET', '/admin/rebates/supplier?rebate=missing&merchant_id=' . $merchant->id, $token));
        $this->assertSame([$missing->id], array_column($onlyMissing['data'], 'order_id'));

        $empty = $this->jsonRequest('GET', '/admin/rebates/supplier?order_no=NOT-EXIST', $token);
        $this->assertSame(200, $empty->getStatusCode(), '筛不出任何行时不能报错');
        $this->assertSame(0, $this->body($empty)['total']);

        $this->assertSame(422, $this->jsonRequest('GET', '/admin/rebates/supplier?business_line=recharge', $token)->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('GET', '/admin/rebates/supplier?completed_from=yesterday', $token)->getStatusCode());
        $this->assertSame(403, $this->jsonRequest('GET', '/admin/rebates/supplier', $this->loginAs($this->createAdminWithPermissions(['order.view'])))->getStatusCode());
    }

    public function testDuePeriodReflectsSystemSetting()
    {
        $backup = SystemSetting::find('rebate_due_period_days')?->getAttributes();
        SystemSetting::query()->updateOrInsert(['key' => 'rebate_due_period_days'], ['value' => '12', 'updated_at' => date('Y-m-d H:i:s')]);
        try {
            $token = $this->loginAs($this->createAdminWithPermissions(['rebate.view']));
            $this->assertSame(12, $this->body($this->jsonRequest('GET', '/admin/rebates', $token))['due_period_days']);
        } finally {
            SystemSetting::query()->where('key', 'rebate_due_period_days')->delete();
            if ($backup !== null) {
                SystemSetting::query()->insert($backup);
            }
        }
    }

    private function createMovieOrder(Merchant $merchant, ?string $supplierRebate, string $status): Order
    {
        $completedAt = date('Y-m-d H:i:s', time() - 3600);
        $order = Order::create([
            'order_no' => 'M' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'movie',
            'status' => $status,
            'sale_price' => '40.00',
            'cost_price' => '38.00',
            'frozen_amount' => '40.00',
            'deducted_amount' => '40.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
            'completed_at' => $completedAt,
            'finished_at' => $completedAt,
        ]);
        $this->orderIds[] = $order->id;
        OrderMovie::create([
            'order_id' => $order->id, 'cinema_id' => 'C1', 'film_id' => 'F1', 'show_id' => 'S1',
            'show_time' => '2026-09-30 19:30:00', 'seats' => [['seat_code' => '1-3']], 'seat_count' => 1,
            'unit_price' => '40.00', 'unit_cost' => '38.00', 'mobile' => '13800000000',
            'lock_expire_at' => date('Y-m-d H:i:s'), 'supplier_rebate' => $supplierRebate,
        ]);

        return $order;
    }

    private function createRebate(Merchant $merchant, int $levelId, string $status, string $amount, ?Order $order = null): MerchantRebate
    {
        $completedAt = date('Y-m-d H:i:s', time() - 3600);
        $order ??= Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => 'success',
            'sale_price' => '100.00',
            'cost_price' => '98.00',
            'frozen_amount' => '100.00',
            'deducted_amount' => '100.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
            'completed_at' => $completedAt,
            'finished_at' => $completedAt,
        ]);
        $this->orderIds[] = $order->id;

        return MerchantRebate::create([
            'order_id' => $order->id,
            'merchant_id' => $merchant->id,
            'business_line' => $order->business_line,
            'level_id' => $levelId,
            'rebate_base' => '1.60',
            'rebate_base_source' => 'product',
            'rebate_rate' => '0.5000',
            'rebate_rate_source' => 'product_level',
            'amount' => $amount,
            'status' => $status,
            'order_completed_at' => $completedAt,
            'due_at' => date('Y-m-d H:i:s', strtotime($completedAt) + 7 * 86400),
        ]);
    }

    private function createMerchant(): Merchant
    {
        $merchant = Merchant::create([
            'type' => 'company',
            'phone' => '189' . random_int(10000000, 99999999),
            'password' => 'hashed-password',
            'status' => 'active',
        ]);
        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }
}
