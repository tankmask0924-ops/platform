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

use App\Crypto\Encryptor;
use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use App\Model\AdminUser;
use App\Model\Merchant;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderRecharge;
use App\Model\Product;
use App\Model\Supplier;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * 系统后台「供应商管理 - 统计」（requirements.md 6.8），
 * App\Service\Admin\SupplierStatsService。
 *
 * @internal
 * @coversNothing
 */
class SupplierStatsControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $supplierIds = [];

    private array $merchantIds = [];

    private array $productIds = [];

    private array $orderIds = [];

    protected function tearDown(): void
    {
        OrderAttempt::whereIn('order_id', $this->orderIds)->delete();
        OrderRecharge::whereIn('order_id', $this->orderIds)->delete();
        Order::destroy($this->orderIds);
        Product::destroy($this->productIds);
        Merchant::destroy($this->merchantIds);
        Supplier::destroy($this->supplierIds);
        AdminUser::destroy($this->adminUserIds);
        AdminRolePermission::whereIn('role_id', $this->roleIds)->delete();
        AdminRole::destroy($this->roleIds);
        AdminPermission::destroy($this->permissionIds);

        parent::tearDown();
    }

    public function testDailyStatsCountEveryAttemptAndFillEmptyDays()
    {
        $supplier = $this->createSupplier();
        $other = $this->createSupplier();
        $today = date('Y-m-d');
        $twoDaysAgo = date('Y-m-d', strtotime('-2 days'));

        // 今天：一成功（成本 8.00，到账 60 秒）、一失败、一处理中
        $this->createAttempt($supplier, 'success', $today . ' 10:00:00', $today . ' 10:01:00', '8.00');
        $this->createAttempt($supplier, 'failed', $today . ' 11:00:00', $today . ' 11:00:05', '8.00');
        $this->createAttempt($supplier, 'processing', $today . ' 12:00:00', $today . ' 12:00:00', '8.00');
        // 前天：一成功（成本 9.00，到账 20 秒）
        $this->createAttempt($supplier, 'success', $twoDaysAgo . ' 09:00:00', $twoDaysAgo . ' 09:00:20', '9.00');
        // 另一家供应商的尝试不能混进来
        $this->createAttempt($other, 'failed', $today . ' 10:30:00', $today . ' 10:30:02', '8.00');

        $body = $this->getJson('/admin/suppliers/' . $supplier->id . '/stats', $this->loginWith(['supplier.view']));

        $this->assertSame('day', $body['group_by']);
        $this->assertCount(7, $body['data'], '默认最近 7 天，没有订单的日期补 0');
        $this->assertSame($today, $body['data'][6]['label']);

        $todayRow = $body['data'][6];
        $this->assertSame(3, $todayRow['order_count']);
        $this->assertSame(1, $todayRow['success_count']);
        $this->assertSame(1, $todayRow['failed_count']);
        $this->assertSame(1, $todayRow['pending_count'], '处理中不计入成功率');
        // 用 assertEquals：整数值的 float 经 JSON 往返会变成 int（50.0 -> 50）
        $this->assertEquals(50.0, $todayRow['success_rate']);
        $this->assertEquals(60.0, $todayRow['avg_delivery_seconds']);
        $this->assertSame('8.00', $todayRow['cost_total'], '失败的尝试不算成本');

        $empty = $body['data'][5];
        $this->assertSame(0, $empty['order_count']);
        $this->assertNull($empty['success_rate']);
        $this->assertNull($empty['avg_delivery_seconds']);
        $this->assertSame('0.00', $empty['cost_total']);

        $summary = $body['summary'];
        $this->assertNull($summary['label']);
        $this->assertSame(4, $summary['order_count']);
        $this->assertSame(2, $summary['success_count']);
        $this->assertSame(1, $summary['failed_count']);
        // 成功率 = 2 / (2 + 1)
        $this->assertEquals(66.7, $summary['success_rate']);
        // 按成功笔数加权：(60 + 20) / 2
        $this->assertEquals(40.0, $summary['avg_delivery_seconds']);
        $this->assertSame('17.00', $summary['cost_total']);
        // 话费、卡券没有供应商返佣，三期电影票/快递才有
        $this->assertSame('0.00', $summary['supplier_rebate_total']);
    }

    public function testProductStatsGroupByProductOrderedByOrderCount()
    {
        $supplier = $this->createSupplier();
        $hot = $this->createProduct('移动 100 元快充');
        $cold = $this->createProduct('联通 50 元慢充');
        $today = date('Y-m-d');

        $this->createAttempt($supplier, 'success', $today . ' 10:00:00', $today . ' 10:00:30', '8.00', $hot);
        $this->createAttempt($supplier, 'failed', $today . ' 10:10:00', $today . ' 10:10:02', '8.00', $hot);
        $this->createAttempt($supplier, 'success', $today . ' 10:20:00', $today . ' 10:20:10', '4.00', $cold);
        // 没有商品行的订单（电影票/快递那种）归到"未知商品"
        $this->createAttempt($supplier, 'success', $today . ' 10:30:00', $today . ' 10:30:10', '1.00');

        $body = $this->getJson(
            '/admin/suppliers/' . $supplier->id . '/stats?group_by=product&created_from=' . $today . '&created_to=' . $today,
            $this->loginWith(['supplier.view'])
        );

        $this->assertSame('product', $body['group_by']);
        $this->assertCount(3, $body['data']);
        $this->assertSame($hot->name, $body['data'][0]['label'], '订单量多的排前面');
        $this->assertSame($hot->id, $body['data'][0]['product_id']);
        $this->assertSame(2, $body['data'][0]['order_count']);
        $this->assertEquals(50.0, $body['data'][0]['success_rate']);
        $this->assertSame('8.00', $body['data'][0]['cost_total']);

        $labels = array_column($body['data'], 'label');
        $this->assertContains($cold->name, $labels);
        $this->assertContains('未知商品', $labels);
        $this->assertNull($body['data'][array_search('未知商品', $labels, true)]['product_id']);

        $this->assertSame(4, $body['summary']['order_count']);
        $this->assertSame('13.00', $body['summary']['cost_total']);
    }

    public function testRejectsBadParamsMissingSupplierAndMissingPermission()
    {
        $supplier = $this->createSupplier();
        $token = $this->loginWith(['supplier.view']);

        $this->assertSame(422, $this->get('/admin/suppliers/' . $supplier->id . '/stats?group_by=week', $token)->getStatusCode());
        $this->assertSame(422, $this->get('/admin/suppliers/' . $supplier->id . '/stats?created_from=2026/01/01', $token)->getStatusCode());
        $this->assertSame(
            422,
            $this->get('/admin/suppliers/' . $supplier->id . '/stats?created_from=2026-03-01&created_to=2026-01-01', $token)->getStatusCode(),
            '开始晚于结束'
        );
        $this->assertSame(
            422,
            $this->get('/admin/suppliers/' . $supplier->id . '/stats?created_from=2026-01-01&created_to=2026-12-31', $token)->getStatusCode(),
            '跨度超过 92 天'
        );
        $this->assertSame(404, $this->get('/admin/suppliers/99999999/stats', $token)->getStatusCode());

        $noPermission = $this->loginWith(['supplier.manage']);
        $this->assertSame(403, $this->get('/admin/suppliers/' . $supplier->id . '/stats', $noPermission)->getStatusCode());
    }

    private function getJson(string $path, string $token): array
    {
        $response = $this->get($path, $token);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function get(string $path, string $token)
    {
        return $this->client->request('GET', $path, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
    }

    /**
     * @param list<string> $codes
     */
    private function loginWith(array $codes): string
    {
        $role = AdminRole::create(['name' => 'role_' . uniqid('', true), 'is_system' => false]);
        $this->roleIds[] = $role->id;
        foreach ($codes as $code) {
            $permission = AdminPermission::firstOrCreate(['code' => $code], ['module' => 'supplier', 'name' => $code, 'type' => 'action']);
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

    private function createSupplier(): Supplier
    {
        $supplier = Supplier::create([
            'name' => '测试供应商',
            'code' => 'sup_' . substr(md5(uniqid('', true)), 0, 20),
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => make(Encryptor::class)->encrypt(json_encode(['base_url' => 'https://api.example.com'])),
            'status' => 'active',
        ]);
        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }

    private function createProduct(string $name): Product
    {
        $product = Product::create([
            'business_line' => 'recharge',
            'name' => $name,
            'operator' => 'mobile',
            'face_value' => '100.00',
            'sale_price' => '98.00',
            'rebate_amount' => '0.00',
            'status' => 'on_shelf',
        ]);
        $this->productIds[] = $product->id;

        return $product;
    }

    /**
     * 一笔订单一次尝试。`order_attempts` 的时间戳直接写死，才能断言到账时长；
     * Model 的 datetimes 会在 create 时覆盖，所以创建后再 update 一次。
     */
    private function createAttempt(
        Supplier $supplier,
        string $result,
        string $createdAt,
        string $updatedAt,
        string $costPrice,
        ?Product $product = null
    ): OrderAttempt {
        $merchant = Merchant::create([
            'type' => 'company',
            'phone' => '186' . random_int(10000000, 99999999),
            'password' => 'hashed',
            'status' => 'active',
        ]);
        $this->merchantIds[] = $merchant->id;

        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => $result === 'success' ? 'success' : 'processing',
            'sale_price' => '10.00',
            'cost_price' => $costPrice,
            'supplier_id' => $supplier->id,
            'frozen_amount' => '10.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);
        $this->orderIds[] = $order->id;

        if ($product !== null) {
            OrderRecharge::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'recharge_account' => '13800000000',
                'rebate_amount' => '0.00',
            ]);
        }

        $attempt = OrderAttempt::create([
            'order_id' => $order->id,
            'supplier_id' => $supplier->id,
            'attempt_no' => 1,
            'result' => $result,
        ]);
        OrderAttempt::where('id', $attempt->id)->update(['created_at' => $createdAt, 'updated_at' => $updatedAt]);

        return $attempt;
    }
}
