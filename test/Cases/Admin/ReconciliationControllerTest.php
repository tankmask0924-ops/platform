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
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\ReconciliationDiff;
use App\Model\Supplier;
use App\Supplier\DriverResult;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
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
 * 系统管理后台「对账」（requirements.md 8.3，database-design.md 4.15）：差异列表、
 * 标记处理/忽略、手动重跑批次。差异产生逻辑本身见
 * test/Cases/Service/Reconciliation/ReconciliationServiceTest.php。
 *
 * @internal
 * @coversNothing
 */
class ReconciliationControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    /**
     * 批次日期只能是最近 90 天内的（ReconciliationAdminService::MAX_BACKFILL_DAYS），
     * 所以这里不能像 ReconciliationServiceTest 那样用固定的历史日期：批次取今天，
     * 覆盖的是昨天完成的订单。共享测试库里昨天完成的真实订单也会被扫到，但它们的
     * 供应商在替身工厂里会抛异常（只计 unreachable），断言一律按本用例的订单号过滤。
     */
    private string $batchDate;

    private string $orderDay;

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $merchantIds = [];

    private array $supplierIds = [];

    private array $orderIds = [];

    /**
     * 每个用例换一个新容器，替换进去的驱动工厂不影响别的测试
     * （同 test/Cases/Admin/SupplierMonitorControllerTest.php）。
     */
    protected function setUp(): void
    {
        $this->batchDate = date('Y-m-d');
        $this->orderDay = date('Y-m-d', strtotime('-1 day'));
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->client = make(Client::class);
    }

    protected function tearDown(): void
    {
        ReconciliationDiff::whereIn('order_id', $this->orderIds ?: [0])->delete();
        OrderAttempt::whereIn('order_id', $this->orderIds ?: [0])->delete();
        Order::destroy($this->orderIds);
        Supplier::destroy($this->supplierIds);
        Merchant::destroy($this->merchantIds);
        AdminUser::destroy($this->adminUserIds);
        AdminRolePermission::whereIn('role_id', $this->roleIds ?: [0])->delete();
        AdminRole::destroy($this->roleIds);
        AdminPermission::destroy($this->permissionIds);
        $this->orderIds = $this->supplierIds = $this->merchantIds = [];

        parent::tearDown();
    }

    /**
     * 手动重跑一个批次，跑完能在列表里按订单号查到这条差异。
     */
    public function testRunProducesDiffsThatShowUpInTheList()
    {
        $token = $this->loginWith(['reconciliation.view', 'reconciliation.handle']);
        $order = $this->createOrderWithSupplierResult('success', UnifiedResult::DefiniteFailure);

        $summary = $this->postJson('/admin/reconciliations/run', $token, ['date' => $this->batchDate]);
        $this->assertSame($this->batchDate, $summary['reconciliation_date']);
        $this->assertSame($this->orderDay, $summary['order_date']);
        $this->assertGreaterThanOrEqual(1, $summary['diff_count']);

        $list = $this->getJson('/admin/reconciliations?order_no=' . $order->order_no, $token);
        $this->assertSame(1, $list['total']);
        $this->assertSame('status', $list['data'][0]['field']);
        $this->assertSame('success', $list['data'][0]['platform_value']);
        $this->assertSame('failed', $list['data'][0]['supplier_value']);
        $this->assertSame($order->order_no, $list['data'][0]['order_no'], '列表带出平台订单号');
        $this->assertNotNull($list['data'][0]['supplier_name'], '列表带出供应商名称');
        $this->assertGreaterThanOrEqual(1, $list['open_count']);
    }

    /**
     * 标记已处理时带的备注要存下来并在列表里显示——下一个人不用重新查一遍同一笔订单。
     */
    public function testResolveRecordsOperatorAndRemark()
    {
        $token = $this->loginWith(['reconciliation.view', 'reconciliation.handle']);
        $order = $this->createOrderWithSupplierResult('success', UnifiedResult::DefiniteFailure);
        $this->postJson('/admin/reconciliations/run', $token, ['date' => $this->batchDate]);
        $diff = ReconciliationDiff::where('order_id', $order->id)->firstOrFail();

        $list = $this->postJson('/admin/reconciliations/' . $diff->id . '/resolve', $token, [
            'remark' => '供应商侧延迟同步，人工确认无误',
            'order_no' => $order->order_no,
        ]);

        $this->assertSame('resolved', $list['data'][0]['status']);
        $this->assertSame('供应商侧延迟同步，人工确认无误', $list['data'][0]['remark']);
        $this->assertSame('Test Admin', $list['data'][0]['resolved_by']);
        $this->assertNotNull($list['data'][0]['resolved_at']);
    }

    public function testIgnoreIsRecordedSeparatelyAndCannotBeHandledTwice()
    {
        $token = $this->loginWith(['reconciliation.view', 'reconciliation.handle']);
        $order = $this->createOrderWithSupplierResult('success', UnifiedResult::DefiniteFailure);
        $this->postJson('/admin/reconciliations/run', $token, ['date' => $this->batchDate]);
        $diff = ReconciliationDiff::where('order_id', $order->id)->firstOrFail();

        $this->assertSame(200, $this->post('/admin/reconciliations/' . $diff->id . '/ignore', $token, [])->getStatusCode());
        $this->assertSame(ReconciliationDiff::STATUS_IGNORED, $diff->refresh()->status);

        $this->assertSame(409, $this->post('/admin/reconciliations/' . $diff->id . '/resolve', $token, [])->getStatusCode());
        $this->assertSame(404, $this->post('/admin/reconciliations/999999999/ignore', $token, [])->getStatusCode());
    }

    public function testInvalidFiltersAndRunDatesAreRejected()
    {
        $token = $this->loginWith(['reconciliation.view', 'reconciliation.handle']);

        foreach (['type=nope', 'status=nope', 'field=nope', 'supplier_id=abc', 'date_from=not-a-date'] as $query) {
            $response = $this->client->request('GET', '/admin/reconciliations?' . $query, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);
            $this->assertSame(422, $response->getStatusCode(), $query);
        }

        foreach (['not-a-date', date('Y-m-d', strtotime('+1 day')), '2000-01-01'] as $date) {
            $this->assertSame(422, $this->post('/admin/reconciliations/run', $token, ['date' => $date])->getStatusCode(), $date);
        }
    }

    /**
     * 查不到的订单号返回空列表，而不是忽略这个条件把整张表倒出来。
     */
    public function testUnknownOrderNoReturnsEmptyList()
    {
        $token = $this->loginWith(['reconciliation.view']);

        $this->assertSame(0, $this->getJson('/admin/reconciliations?order_no=NOT-AN-ORDER', $token)['total']);
    }

    public function testViewPermissionCanNeitherHandleNorRun()
    {
        $token = $this->loginWith(['reconciliation.view', 'reconciliation.handle']);
        $order = $this->createOrderWithSupplierResult('success', UnifiedResult::DefiniteFailure);
        $this->postJson('/admin/reconciliations/run', $token, ['date' => $this->batchDate]);
        $diff = ReconciliationDiff::where('order_id', $order->id)->firstOrFail();

        $viewer = $this->loginWith(['reconciliation.view']);

        $this->assertSame(200, $this->client->request('GET', '/admin/reconciliations', [
            'headers' => ['Authorization' => 'Bearer ' . $viewer],
        ])->getStatusCode());
        $this->assertSame(403, $this->post('/admin/reconciliations/' . $diff->id . '/resolve', $viewer, [])->getStatusCode());
        $this->assertSame(403, $this->post('/admin/reconciliations/run', $viewer, ['date' => $this->batchDate])->getStatusCode());
    }

    /**
     * 建一笔在覆盖窗口内完成的订单，并把驱动换成固定返回 `$supplierResult` 的替身。
     */
    private function createOrderWithSupplierResult(string $orderStatus, UnifiedResult $supplierResult): Order
    {
        $unique = uniqid('reconciliation_admin_', true);
        $supplier = Supplier::create([
            'name' => $unique,
            'code' => substr(md5($unique), 0, 24),
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => 'unused-in-test-driver-factory-is-overridden',
            'status' => 'active',
        ]);
        $this->supplierIds[] = $supplier->id;

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

        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => $orderStatus,
            'sale_price' => '10.00',
            'cost_price' => '8.00',
            'supplier_id' => $supplier->id,
            'frozen_amount' => '10.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
            'finished_at' => $this->orderDay . ' 12:00:00',
        ]);
        $this->orderIds[] = $order->id;

        OrderAttempt::create([
            'order_id' => $order->id,
            'supplier_id' => $supplier->id,
            'attempt_no' => 1,
            'result' => 'success',
        ]);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('queryOrder')->andReturn(new DriverResult(result: $supplierResult, supplierOrderNo: 'KS123456'));
        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('build')->andReturnUsing(static fn (Supplier $s) => (int) $s->id === (int) $supplier->id
            ? $driver
            : throw new RuntimeException('not a supplier of this test'));
        ApplicationContext::getContainer()->set(SupplierDriverFactory::class, $factory);

        return $order;
    }

    private function getJson(string $path, string $token): array
    {
        $response = $this->client->request('GET', $path, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function postJson(string $path, string $token, array $data): array
    {
        $response = $this->post($path, $token, $data);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function post(string $path, string $token, array $data)
    {
        return $this->client->request('POST', $path, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'json' => $data,
        ]);
    }

    /**
     * @param list<string> $codes
     */
    private function loginWith(array $codes): string
    {
        $role = AdminRole::create(['name' => 'role_' . uniqid('', true), 'is_system' => false]);
        $this->roleIds[] = $role->id;
        foreach ($codes as $code) {
            $permission = AdminPermission::firstOrCreate(['code' => $code], ['module' => 'reconciliation', 'name' => $code, 'type' => 'action']);
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
