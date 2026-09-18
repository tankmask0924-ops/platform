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
use App\Job\SyncSupplierProductsJob;
use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use App\Model\AdminUser;
use App\Model\Merchant;
use App\Model\Order;
use App\Model\Supplier;
use App\Model\SupplierCallLog;
use App\Service\Supplier\SupplierCallLogService;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\SupplierDriverFactory;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\AsyncQueue\JobInterface;
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
 * 系统后台「供应商管理」的回调地址、余额监控（手动刷新）、商品同步（手动触发）、调用日志
 * （requirements.md 6.3 / 6.7 / 6.8）。
 *
 * @internal
 * @coversNothing
 */
class SupplierMonitorControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $supplierIds = [];

    private array $merchantIds = [];

    private array $orderIds = [];

    /** @var list<JobInterface> */
    private array $pushedJobs = [];

    /**
     * 每个用例换一个新容器，替换进去的驱动工厂、队列不影响别的测试。
     */
    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->client = make(Client::class);
    }

    protected function tearDown(): void
    {
        SupplierCallLog::whereIn('supplier_id', $this->supplierIds)->delete();
        Order::destroy($this->orderIds);
        Merchant::destroy($this->merchantIds);
        Supplier::destroy($this->supplierIds);
        AdminUser::destroy($this->adminUserIds);
        AdminRolePermission::whereIn('role_id', $this->roleIds)->delete();
        AdminRole::destroy($this->roleIds);
        AdminPermission::destroy($this->permissionIds);

        parent::tearDown();
    }

    public function testDetailShowsNotifyUrlsWithToken()
    {
        $supplier = $this->createSupplier();
        $token = $this->loginWith(['supplier.view']);

        $body = $this->getJson('/admin/suppliers/' . $supplier->id, $token);

        $this->assertStringEndsWith('/notify/' . $supplier->code . '/' . $supplier->notify_token, $body['order_notify_url']);
        $this->assertSame($body['order_notify_url'] . '/goods', $body['goods_notify_url']);
        $this->assertIsBool($body['notify_base_url_configured']);
    }

    public function testRefreshBalanceWritesBalanceOrReports422()
    {
        $supplier = $this->createSupplier();
        $broken = $this->createSupplier();
        $token = $this->loginWith(['supplier.manage']);

        $ok = Mockery::mock(KasushouDriver::class);
        $ok->shouldReceive('queryBalance')->once()->andReturn('321.5');
        $failing = Mockery::mock(KasushouDriver::class);
        $failing->shouldReceive('queryBalance')->once()->andThrow(new RuntimeException('http 500'));
        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('build')->andReturnUsing(static fn (Supplier $s) => $s->id === $supplier->id ? $ok : $failing);
        ApplicationContext::getContainer()->set(SupplierDriverFactory::class, $factory);

        $response = $this->post('/admin/suppliers/' . $supplier->id . '/balance/refresh', $token);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('321.50', $body['balance']);
        $this->assertNotNull($body['balance_synced_at']);

        $this->assertSame(422, $this->post('/admin/suppliers/' . $broken->id . '/balance/refresh', $token)->getStatusCode());
        $this->assertNull($broken->refresh()->balance);
    }

    public function testProductSyncIsQueuedOnlyForActiveSupplier()
    {
        $active = $this->createSupplier();
        $disabled = $this->createSupplier(['status' => 'disabled']);
        $this->captureQueue();

        $viewer = $this->loginWith(['supplier.view']);
        $this->assertSame(403, $this->post('/admin/suppliers/' . $active->id . '/product-sync', $viewer)->getStatusCode());

        $token = $this->loginWith(['supplier.manage']);
        $this->assertSame(200, $this->post('/admin/suppliers/' . $active->id . '/product-sync', $token)->getStatusCode());
        $this->assertSame(422, $this->post('/admin/suppliers/' . $disabled->id . '/product-sync', $token)->getStatusCode());

        $this->assertCount(1, $this->pushedJobs);
        $this->assertInstanceOf(SyncSupplierProductsJob::class, $this->pushedJobs[0]);
        $this->assertSame($active->id, $this->pushedJobs[0]->supplierId);
    }

    public function testCallLogsLinkOrderMaskCardSecretsAndFilter()
    {
        $supplier = $this->createSupplier();
        $order = $this->createOrder($supplier);
        $service = make(SupplierCallLogService::class);

        $service->record($supplier->id, 'place_order', ['path' => '/api/v1/order/create', 'body' => ['external_orderno' => $order->order_no . '-1']], ['http_status' => 200, 'body' => ['code' => 200]], 120);
        $service->record($supplier->id, 'query', ['path' => '/api/v1/order/query', 'body' => ['external_orderno' => $order->order_no . '-1']], [
            'http_status' => 200,
            'body' => ['code' => 200, 'data' => ['status' => 3, 'card_list' => [['card_no' => '8800123', 'card_password' => 'SECRET-PWD']]]],
        ], 80);
        $service->record($supplier->id, 'query_balance', ['path' => '/api/v1/user/info', 'body' => []], ['exception' => 'timeout'], 10000);

        // 落库前已经打码
        $stored = SupplierCallLog::where('supplier_id', $supplier->id)->where('action', 'query')->first();
        $this->assertSame('******', $stored->response['body']['data']['card_list'][0]['card_password']);
        $this->assertSame($order->id, $stored->order_id);

        $token = $this->loginWith(['supplier.view']);
        $all = $this->getJson('/admin/suppliers/' . $supplier->id . '/call-logs', $token);
        $this->assertSame(3, $all['total']);
        $this->assertSame('query_balance', $all['data'][0]['action'], '最新在前');
        $this->assertNull($all['data'][0]['order_no']);
        $this->assertNull($all['data'][0]['http_status']);
        $this->assertSame($order->order_no, $all['data'][1]['order_no']);
        $this->assertStringNotContainsString('SECRET-PWD', json_encode($all));

        $byOrder = $this->getJson('/admin/suppliers/' . $supplier->id . '/call-logs?order_no=' . $order->order_no, $token);
        $this->assertSame(2, $byOrder['total']);
        $byAction = $this->getJson('/admin/suppliers/' . $supplier->id . '/call-logs?action=place_order', $token);
        $this->assertSame(1, $byAction['total']);
        $this->assertSame(0, $this->getJson('/admin/suppliers/' . $supplier->id . '/call-logs?order_no=NO-SUCH', $token)['total']);

        $bad = $this->client->request('GET', '/admin/suppliers/' . $supplier->id . '/call-logs', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'query' => ['action' => 'nope'],
        ]);
        $this->assertSame(422, $bad->getStatusCode());
    }

    public function testHistoricalSnapshotWithJsonStringBodyIsMaskedOnDisplay()
    {
        $supplier = $this->createSupplier();
        // 改动前写进去的日志：响应体是原始 JSON 字符串
        SupplierCallLog::create([
            'supplier_id' => $supplier->id,
            'action' => 'query',
            'request' => ['path' => '/api/v1/order/query', 'body' => []],
            'response' => ['http_status' => 200, 'body' => json_encode(['data' => ['card_list' => [['card_no' => '8800999', 'card_password' => 'OLD-SECRET']]]])],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $body = $this->getJson('/admin/suppliers/' . $supplier->id . '/call-logs', $this->loginWith(['supplier.view']));

        $this->assertStringNotContainsString('OLD-SECRET', json_encode($body));
        $this->assertStringNotContainsString('8800999', json_encode($body));
    }

    private function getJson(string $path, string $token): array
    {
        $response = $this->client->request('GET', $path, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function post(string $path, string $token)
    {
        return $this->client->request('POST', $path, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'json' => [],
        ]);
    }

    private function captureQueue(): void
    {
        $queue = Mockery::mock(DriverInterface::class);
        $queue->shouldReceive('push')->andReturnUsing(function (JobInterface $job) {
            $this->pushedJobs[] = $job;

            return true;
        });
        $factory = Mockery::mock(DriverFactory::class);
        $factory->shouldReceive('get')->with('default')->andReturn($queue);
        ApplicationContext::getContainer()->set(DriverFactory::class, $factory);
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function createSupplier(array $overrides = []): Supplier
    {
        $supplier = Supplier::create(array_merge([
            'name' => '测试供应商',
            'code' => 'sup_' . substr(md5(uniqid('', true)), 0, 20),
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => make(Encryptor::class)->encrypt(json_encode(['base_url' => 'https://api.example.com', 'user_id' => 'u', 'api_key' => 'k'])),
            'status' => 'active',
        ], $overrides));
        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }

    private function createOrder(Supplier $supplier): Order
    {
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
            'status' => 'processing',
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
}
