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
use App\Job\NotifyMerchantJob;
use App\Model\AdminOperationLog;
use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use App\Model\AdminUser;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantRebate;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderExpress;
use App\Model\OrderExpressFeeAdjustment;
use App\Model\OrderMovie;
use App\Model\OrderRecharge;
use App\Model\Product;
use App\Model\Supplier;
use App\Supplier\DriverResult;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\HttpTestCase;
use Mockery;
use RuntimeException;

use function Hyperf\Support\make;

/**
 * 系统管理后台「订单管理」：App\Controller\Admin\OrderController +
 * App\Service\Admin\OrderAdminService，真实 HTTP + 中间件栈。供应商驱动和队列都换成
 * Mockery 双重；每个用例重建容器的原因见 test/Cases/Controller/NotifySupplierControllerTest.php
 * 类注释。
 *
 * @internal
 * @coversNothing
 */
class OrderControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $merchantIds = [];

    private array $productIds = [];

    private array $supplierIds = [];

    private array $orderIds = [];

    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->client = make(Client::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $id) {
            OrderAttempt::where('order_id', $id)->delete();
            OrderRecharge::where('order_id', $id)->delete();
            OrderExpress::where('order_id', $id)->delete();
            OrderExpressFeeAdjustment::where('order_id', $id)->delete();
            OrderMovie::where('order_id', $id)->delete();
            MerchantBalanceLog::where('order_id', $id)->delete();
            MerchantRebate::where('order_id', $id)->delete();
            AdminOperationLog::where('target_type', 'order')->where('target_id', $id)->delete();
            Order::destroy($id);
        }
        foreach ($this->supplierIds as $id) {
            Supplier::destroy($id);
        }
        foreach ($this->productIds as $id) {
            Product::destroy($id);
        }
        foreach ($this->merchantIds as $id) {
            Merchant::destroy($id);
        }
        foreach ($this->adminUserIds as $id) {
            AdminUser::destroy($id);
        }
        foreach ($this->roleIds as $id) {
            AdminRolePermission::where('role_id', $id)->delete();
            AdminRole::destroy($id);
        }
        foreach ($this->permissionIds as $id) {
            AdminPermission::destroy($id);
        }
        $this->orderIds = $this->supplierIds = $this->productIds = $this->merchantIds = [];
        $this->adminUserIds = $this->roleIds = $this->permissionIds = [];

        Mockery::close();
        parent::tearDown();
    }

    public function testListAndDetailRequireViewPermission()
    {
        [$merchant, $supplier] = $this->merchantAndSupplier();
        $order = $this->createOrder($merchant, $supplier, 'processing');

        $noPermission = $this->loginAs($this->createAdminWithPermissions(['order.manage']));
        $this->assertSame(403, $this->request('GET', '/admin/orders', $noPermission)->getStatusCode());
        $this->assertSame(403, $this->request('GET', '/admin/orders/' . $order->id, $noPermission)->getStatusCode());
        $this->assertSame(401, $this->client->request('GET', '/admin/orders')->getStatusCode());
    }

    public function testListFiltersByMerchantAndStatus()
    {
        [$merchant, $supplier] = $this->merchantAndSupplier();
        $processing = $this->createOrder($merchant, $supplier, 'processing');
        $abnormal = $this->createOrder($merchant, $supplier, 'abnormal');
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));

        $body = $this->json($this->request('GET', '/admin/orders?merchant_id=' . $merchant->id . '&status=abnormal', $token));

        $this->assertSame(1, $body['total']);
        $this->assertSame($abnormal->id, $body['data'][0]['id']);
        $this->assertSame('abnormal', $body['data'][0]['status'], '后台看到真实状态');
        $this->assertArrayHasKey('cost_price', $body['data'][0]);

        $all = $this->json($this->request('GET', '/admin/orders?merchant_id=' . $merchant->id, $token));
        $this->assertSame([$abnormal->id, $processing->id], array_column($all['data'], 'id'), '最新的在前');

        $this->assertSame(422, $this->request('GET', '/admin/orders?status=bogus', $token)->getStatusCode());
    }

    public function testDetailShowsAttemptsWithMaskedCardSecrets()
    {
        [$merchant, $supplier] = $this->merchantAndSupplier();
        $order = $this->createOrder($merchant, $supplier, 'processing', cardNo: 'enc-no', cardPwd: 'enc-pwd');
        OrderAttempt::where('order_id', $order->id)->update([
            'response_snapshot' => json_encode(['data' => ['card_list' => [['card_no' => 'PLAIN-NO', 'card_password' => 'PLAIN-PWD']]]]),
        ]);
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));

        $response = $this->request('GET', '/admin/orders/' . $order->id, $token);
        $raw = (string) $response->getBody();
        $body = json_decode($raw, true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($supplier->name, $body['supplier_name']);
        $this->assertCount(1, $body['attempts']);
        $this->assertSame('******', $body['attempts'][0]['response_snapshot']['data']['card_list'][0]['card_password']);
        $this->assertTrue($body['recharge']['has_card_secret']);
        $this->assertStringNotContainsString('PLAIN-PWD', $raw);
        $this->assertStringNotContainsString('enc-pwd', $raw);

        $this->assertSame(404, $this->request('GET', '/admin/orders/999999999', $token)->getStatusCode());
    }

    /**
     * 快递、电影票订单详情带各自的明细，后台版包括寄收件人、成本、费用调整原因、每张成本和供应商返佣。
     */
    public function testDetailIncludesExpressAndMovieDetails()
    {
        [$merchant, $supplier] = $this->merchantAndSupplier();
        $express = $this->createOrderOfLine($merchant, $supplier, 'express', 'success');
        OrderExpress::create([
            'order_id' => $express->id,
            'express_company_code' => 'EXabc',
            'express_company_name' => '顺丰',
            'sender_info' => ['name' => '张三', 'mobile' => '13900000000', 'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'address' => '科技园'],
            'receiver_info' => ['name' => '李四'],
            'item_info' => ['name' => '文件'],
            'weight' => 3,
            'estimated_freight' => '10.00',
            'frozen_freight' => '11.00',
            'actual_freight' => '12.00',
            'freight_sale_price' => '14.00',
            'logistics_status' => 'in_transit',
            'fee_over_at' => date('Y-m-d H:i:s'),
        ]);
        OrderExpressFeeAdjustment::create(['order_id' => $express->id, 'type' => 'supplement', 'item' => 'material', 'amount' => '3.00', 'reason' => '快递费用调整：耗材费补扣', 'created_at' => date('Y-m-d H:i:s')]);

        $movie = $this->createOrderOfLine($merchant, $supplier, 'movie', 'success');
        OrderMovie::create([
            'order_id' => $movie->id, 'cinema_id' => 'C1', 'cinema_name' => '万达影城', 'film_id' => 'F1', 'show_id' => 'S1',
            'show_time' => '2026-09-30 19:30:00', 'seats' => [['seat_code' => '1-3', 'row_label' => '1', 'col_label' => '3', 'love_status' => 0]],
            'seat_count' => 1, 'unit_price' => '40.00', 'unit_cost' => '38.00', 'mobile' => '13800000000',
            'lock_expire_at' => date('Y-m-d H:i:s'), 'ticket_codes' => [['code' => 'T-1']], 'supplier_rebate' => '3.00',
        ]);
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));

        $expressBody = $this->json($this->request('GET', '/admin/orders/' . $express->id, $token));
        $this->assertNull($expressBody['recharge']);
        $this->assertNull($expressBody['movie']);
        $this->assertSame('张三', $expressBody['express']['sender']['name']);
        $this->assertSame(['10.00', '11.00', '12.00', '14.00'], [
            $expressBody['express']['estimated_freight'], $expressBody['express']['frozen_freight'],
            $expressBody['express']['actual_freight'], $expressBody['express']['freight_sale_price'],
        ]);
        $this->assertSame('快递费用调整：耗材费补扣', $expressBody['express']['fee_adjustments'][0]['reason']);

        $movieBody = $this->json($this->request('GET', '/admin/orders/' . $movie->id, $token));
        $this->assertNull($movieBody['express']);
        $this->assertSame('38.00', $movieBody['movie']['unit_cost']);
        $this->assertSame('3.00', $movieBody['movie']['supplier_rebate']);
        $this->assertSame([['code' => 'T-1']], $movieBody['movie']['ticket_codes']);
    }

    /**
     * 快递、电影票的异常单不能人工置成功（快递扣多少要看云洋的费用明细，电影票成功必须带取票码），只能置失败。
     */
    public function testExpressAndMovieAbnormalOrdersCannotBeResolvedAsSuccess()
    {
        [$merchant, $supplier] = $this->merchantAndSupplier();
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.resolve']));

        foreach (['express', 'movie'] as $line) {
            $order = $this->createOrderOfLine($merchant, $supplier, $line, 'abnormal');
            $response = $this->request('POST', '/admin/orders/' . $order->id . '/resolve', $token, ['result' => 'success', 'remark' => '核实已完成']);
            $this->assertSame(409, $response->getStatusCode(), $line);
            $this->assertSame('abnormal', $order->refresh()->status);
        }
    }

    public function testResolveAbnormalAsSuccessDeductsNotifiesAndLogs()
    {
        [$merchant, $supplier] = $this->merchantAndSupplier();
        $order = $this->createOrder($merchant, $supplier, 'abnormal');
        $admin = $this->createAdminWithPermissions(['order.resolve', 'order.view']);
        $token = $this->loginAs($admin);
        $this->expectNotify(1);

        $response = $this->request('POST', '/admin/orders/' . $order->id . '/resolve', $token, [
            'result' => 'success',
            'remark' => '供应商客服确认已到账',
            'supplier_order_no' => 'SUP-MANUAL-1',
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $order->refresh();
        $this->assertSame('success', $order->status);
        $this->assertSame('SUP-MANUAL-1', $order->supplier_order_no);
        $this->assertSame('10.00', $order->deducted_amount);
        $this->assertSame('8.00', $order->cost_price);

        $merchant->refresh();
        $this->assertSame('90.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);

        $log = AdminOperationLog::where('target_type', 'order')->where('target_id', $order->id)->first();
        $this->assertSame($admin->id, $log->admin_user_id);
        $this->assertSame('resolve_abnormal', $log->action);
        $this->assertSame('abnormal', $log->before_data['status']);
        $this->assertSame('success', $log->after_data['status']);
        $this->assertSame('供应商客服确认已到账', $log->after_data['remark']);

        $detail = $this->json($this->request('GET', '/admin/orders/' . $order->id, $token));
        $this->assertSame('resolve_abnormal', $detail['operation_logs'][0]['action']);
    }

    public function testResolveAbnormalAsFailedUnfreezesWithoutSwitchingSupplier()
    {
        [$merchant, $supplier] = $this->merchantAndSupplier();
        $order = $this->createOrder($merchant, $supplier, 'abnormal');
        $token = $this->loginAs($this->createAdminWithPermissions(['order.resolve']));
        $this->expectNotify(1);
        $this->bindDriver($supplier, $this->driverThatMustNotBeCalled());

        $response = $this->request('POST', '/admin/orders/' . $order->id . '/resolve', $token, [
            'result' => 'failed',
            'remark' => '供应商确认未充值',
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('failed', $order->refresh()->status);
        $this->assertSame(1, OrderAttempt::where('order_id', $order->id)->count());

        $merchant->refresh();
        $this->assertSame('100.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);
    }

    public function testResolveIsRejectedForNonAbnormalOrBadInputOrMissingPermission()
    {
        [$merchant, $supplier] = $this->merchantAndSupplier();
        $processing = $this->createOrder($merchant, $supplier, 'processing');
        $abnormal = $this->createOrder($merchant, $supplier, 'abnormal');
        $token = $this->loginAs($this->createAdminWithPermissions(['order.resolve']));
        $viewer = $this->loginAs($this->createAdminWithPermissions(['order.view', 'order.manage']));
        $this->expectNotify(0);

        $valid = ['result' => 'success', 'remark' => 'ok'];
        $this->assertSame(409, $this->request('POST', '/admin/orders/' . $processing->id . '/resolve', $token, $valid)->getStatusCode());
        $this->assertSame(422, $this->request('POST', '/admin/orders/' . $abnormal->id . '/resolve', $token, ['result' => 'success'])->getStatusCode());
        $this->assertSame(422, $this->request('POST', '/admin/orders/' . $abnormal->id . '/resolve', $token, ['result' => 'refunded', 'remark' => 'x'])->getStatusCode());
        $this->assertSame(403, $this->request('POST', '/admin/orders/' . $abnormal->id . '/resolve', $viewer, $valid)->getStatusCode());

        $this->assertSame('processing', $processing->refresh()->status);
        $this->assertSame('abnormal', $abnormal->refresh()->status);
    }

    public function testCardSecretOrderNeedsSupplierConfirmationToResolveAsSuccess()
    {
        [$merchant, $supplier] = $this->merchantAndSupplier();
        $order = $this->createOrder($merchant, $supplier, 'abnormal', cardType: 'card_secret');
        $token = $this->loginAs($this->createAdminWithPermissions(['order.resolve']));

        // 同一个用例里服务只构造一次，换绑 mock 不生效，所以一个 mock 按顺序给两次结果
        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('queryOrder')->twice()->with($order->order_no . '-1', true)->andReturn(
            new DriverResult(result: UnifiedResult::Processing),
            new DriverResult(
                result: UnifiedResult::Success,
                supplierOrderNo: 'SUP-CARD-1',
                actualCost: '7.50',
                cardList: [['card_no' => 'CARD-NO-1', 'card_password' => 'CARD-PWD-1']],
            ),
        );
        $this->bindDriver($supplier, $driver);
        $this->expectNotify(1);

        $rejected = $this->request('POST', '/admin/orders/' . $order->id . '/resolve', $token, ['result' => 'success', 'remark' => '客服说成功了']);
        $this->assertSame(409, $rejected->getStatusCode());
        $this->assertSame('abnormal', $order->refresh()->status);

        $accepted = $this->request('POST', '/admin/orders/' . $order->id . '/resolve', $token, ['result' => 'success', 'remark' => '供应商已出卡']);
        $this->assertSame(200, $accepted->getStatusCode(), (string) $accepted->getBody());

        $order->refresh();
        $this->assertSame('success', $order->status);
        $this->assertSame('7.50', $order->cost_price);
        $recharge = OrderRecharge::find($order->id);
        $this->assertSame('CARD-PWD-1', make(Encryptor::class)->decrypt($recharge->card_pwd));
    }

    public function testQuerySupplierAdvancesProcessingOrderButOnlyRecordsForAbnormal()
    {
        [$merchant, $supplier] = $this->merchantAndSupplier();
        $processing = $this->createOrder($merchant, $supplier, 'processing');
        $abnormal = $this->createOrder($merchant, $supplier, 'abnormal');
        $token = $this->loginAs($this->createAdminWithPermissions(['order.manage']));

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('queryOrder')->twice()->andReturn(new DriverResult(result: UnifiedResult::Success, supplierOrderNo: 'SUP-Q'));
        $this->bindDriver($supplier, $driver);
        $this->expectNotify(1);

        $body = $this->json($this->request('POST', '/admin/orders/' . $processing->id . '/query-supplier', $token));
        $this->assertSame('success', $body['result']);
        $this->assertSame('success', $body['order']['status']);

        $body = $this->json($this->request('POST', '/admin/orders/' . $abnormal->id . '/query-supplier', $token));
        $this->assertSame('success', $body['result']);
        $this->assertSame('abnormal', $body['order']['status'], '异常单只记录，等人工处理');
        $this->assertSame('success', OrderAttempt::where('order_id', $abnormal->id)->value('result'));
        $this->assertSame(0, MerchantBalanceLog::where('order_id', $abnormal->id)->where('type', 'deduct')->count());

        $this->assertSame(1, AdminOperationLog::where('target_id', $abnormal->id)->where('action', 'query_supplier')->count());
    }

    public function testQuerySupplierFailureAndWrongStatus()
    {
        [$merchant, $supplier] = $this->merchantAndSupplier();
        $processing = $this->createOrder($merchant, $supplier, 'processing');
        $finished = $this->createOrder($merchant, $supplier, 'success');
        $token = $this->loginAs($this->createAdminWithPermissions(['order.manage']));

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('queryOrder')->once()->andThrow(new RuntimeException('timeout'));
        $this->bindDriver($supplier, $driver);
        $this->expectNotify(0);

        $this->assertSame(502, $this->request('POST', '/admin/orders/' . $processing->id . '/query-supplier', $token)->getStatusCode());
        $this->assertSame('processing', $processing->refresh()->status);
        $this->assertSame(409, $this->request('POST', '/admin/orders/' . $finished->id . '/query-supplier', $token)->getStatusCode());
    }

    public function testRenotifyOnlyForFinishedOrders()
    {
        [$merchant, $supplier] = $this->merchantAndSupplier();
        $processing = $this->createOrder($merchant, $supplier, 'processing');
        $finished = $this->createOrder($merchant, $supplier, 'failed');
        $token = $this->loginAs($this->createAdminWithPermissions(['order.manage']));
        $this->expectNotify(1);

        $this->assertSame(409, $this->request('POST', '/admin/orders/' . $processing->id . '/renotify', $token)->getStatusCode());
        $this->assertSame(200, $this->request('POST', '/admin/orders/' . $finished->id . '/renotify', $token)->getStatusCode());
        $this->assertSame(1, AdminOperationLog::where('target_id', $finished->id)->where('action', 'renotify')->count());
    }

    private function driverThatMustNotBeCalled(): KasushouDriver
    {
        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldNotReceive('placeOrder');
        $driver->shouldNotReceive('queryOrder');

        return $driver;
    }

    private function bindDriver(Supplier $supplier, KasushouDriver $driver): void
    {
        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('build')
            ->with(Mockery::on(static fn (Supplier $s) => $s->id === $supplier->id))
            ->andReturn($driver);
        ApplicationContext::getContainer()->set(SupplierDriverFactory::class, $factory);
    }

    private function expectNotify(int $times): void
    {
        $queue = Mockery::mock(DriverInterface::class);
        $queue->shouldReceive('push')->times($times)->with(Mockery::type(NotifyMerchantJob::class))->andReturnTrue();
        $factory = Mockery::mock(DriverFactory::class);
        $factory->shouldReceive('get')->times($times)->with('default')->andReturn($queue);
        ApplicationContext::getContainer()->set(DriverFactory::class, $factory);
    }

    private function request(string $method, string $path, string $token, array $data = [])
    {
        return $this->client->request($method, $path, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'json' => $data,
        ]);
    }

    private function json($response): array
    {
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function loginAs(AdminUser $admin): string
    {
        $login = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => ['username' => $admin->username, 'password' => self::PASSWORD],
        ]);

        return json_decode((string) $login->getBody(), true)['token'];
    }

    private function createAdminWithPermissions(array $codes): AdminUser
    {
        $role = AdminRole::create(['name' => 'role_' . uniqid('', true), 'is_system' => false]);
        $this->roleIds[] = $role->id;

        foreach ($codes as $code) {
            $permission = AdminPermission::firstOrCreate(['code' => $code], ['module' => 'order', 'name' => $code, 'type' => 'action']);
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

        return $admin;
    }

    /**
     * @return array{0: Merchant, 1: Supplier}
     */
    private function merchantAndSupplier(): array
    {
        $unique = uniqid('admin_order_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => 'encrypted-secret-placeholder',
            'available_balance' => '100.00',
            'frozen_balance' => '0.00',
        ]);
        $this->merchantIds[] = $merchant->id;

        $supplier = Supplier::create([
            'name' => '供应商 ' . $unique,
            'code' => substr(md5($unique), 0, 24),
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => 'unused-in-test-driver-factory-is-overridden',
            'status' => 'active',
        ]);
        $this->supplierIds[] = $supplier->id;

        return [$merchant, $supplier];
    }

    /**
     * 已受理的订单（冻结 10 元，第一家处理中）。`$status` 直接写入，模拟不同阶段。
     */
    /**
     * 快递 / 电影票订单（没有 order_recharges 行），冻结 10 元。
     */
    private function createOrderOfLine(Merchant $merchant, Supplier $supplier, string $businessLine, string $status): Order
    {
        $order = Order::create([
            'order_no' => strtoupper($businessLine[0]) . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => $businessLine,
            'status' => $status,
            'sale_price' => '10.00',
            'cost_price' => '8.00',
            'supplier_id' => $supplier->id,
            'frozen_amount' => '10.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);
        $this->orderIds[] = $order->id;

        return $order;
    }

    private function createOrder(
        Merchant $merchant,
        Supplier $supplier,
        string $status,
        ?string $cardType = null,
        ?string $cardNo = null,
        ?string $cardPwd = null
    ): Order {
        $businessLine = $cardType === null ? 'recharge' : 'card';
        $product = Product::create([
            'business_line' => $businessLine,
            'name' => uniqid('admin_order_test_product_', true),
            'operator' => $cardType === null ? 'mobile' : null,
            'card_type' => $cardType,
            'face_value' => '10.00',
            'sale_price' => '10.00',
            'rebate_amount' => '0.00',
            'status' => 'on_shelf',
        ]);
        $this->productIds[] = $product->id;

        $order = Order::create([
            'order_no' => ($businessLine === 'card' ? 'C' : 'R') . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => $businessLine,
            'status' => $status,
            'sale_price' => '10.00',
            'cost_price' => '8.00',
            'supplier_id' => $supplier->id,
            'frozen_amount' => '10.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);
        $this->orderIds[] = $order->id;

        OrderRecharge::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'recharge_account' => $cardType === 'card_secret' ? null : '13800000500',
            'card_no' => $cardNo,
            'card_pwd' => $cardPwd,
            'rebate_amount' => '0.00',
        ]);
        OrderAttempt::create([
            'order_id' => $order->id,
            'supplier_id' => $supplier->id,
            'attempt_no' => 1,
            'result' => 'unknown',
        ]);

        if (in_array($status, ['processing', 'abnormal'], true)) {
            Merchant::where('id', $merchant->id)->update([
                'available_balance' => bcsub((string) $merchant->refresh()->available_balance, '10.00', 2),
                'frozen_balance' => bcadd((string) $merchant->frozen_balance, '10.00', 2),
            ]);
            $merchant->refresh();
        }

        return $order;
    }
}
