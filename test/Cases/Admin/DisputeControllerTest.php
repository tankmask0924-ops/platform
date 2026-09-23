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

use App\Job\NotifyMerchantJob;
use App\Model\AdminOperationLog;
use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use App\Model\AdminUser;
use App\Model\AftersaleDispute;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantRebate;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\Supplier;
use App\Service\Supplier\SupplierNotifyAddressService;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\SupplierDriverFactory;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\HttpTestCase;
use Mockery;

use function Hyperf\Support\make;

/**
 * 系统管理后台「售后处理」：App\Controller\Admin\DisputeController +
 * App\Service\Admin\DisputeAdminService。退款本身的细节见 OrderRefundServiceTest，
 * 这里验证权限、状态约束、凭证校验、操作日志和整条确认链路。队列换成 mock，
 * 每个用例重建容器（原因见 NotifySupplierControllerTest 类注释）。
 *
 * @internal
 * @coversNothing
 */
class DisputeControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $merchantIds = [];

    private array $orderIds = [];

    private array $supplierIds = [];

    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->client = make(Client::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $id) {
            $dispute = AftersaleDispute::where('order_id', $id)->first();
            if ($dispute !== null) {
                AdminOperationLog::where('target_type', 'aftersale_dispute')->where('target_id', $dispute->id)->delete();
                $dispute->delete();
            }
            MerchantBalanceLog::where('order_id', $id)->delete();
            MerchantRebate::where('order_id', $id)->delete();
            OrderAttempt::where('order_id', $id)->delete();
            Order::destroy($id);
        }
        foreach ($this->merchantIds as $id) {
            Merchant::destroy($id);
        }
        Supplier::destroy($this->supplierIds);
        $this->supplierIds = [];
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
        $this->orderIds = $this->merchantIds = $this->adminUserIds = $this->roleIds = $this->permissionIds = [];

        Mockery::close();
        parent::tearDown();
    }

    public function testPermissions()
    {
        $dispute = $this->createDispute();
        $viewer = $this->loginAs($this->createAdminWithPermissions(['aftersale.view']));
        $nobody = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        $this->expectNotify(0);

        $this->assertSame(200, $this->request('GET', '/admin/disputes', $viewer)->getStatusCode());
        $this->assertSame(200, $this->request('GET', '/admin/disputes/' . $dispute->id, $viewer)->getStatusCode());
        $this->assertSame(403, $this->request('GET', '/admin/disputes', $nobody)->getStatusCode());
        $this->assertSame(403, $this->request('POST', '/admin/disputes/' . $dispute->id . '/confirm', $viewer, ['remark' => 'x'])->getStatusCode());
        $this->assertSame(403, $this->request('POST', '/admin/disputes/' . $dispute->id . '/reject', $viewer, ['remark' => 'x', 'evidence' => ['e']])->getStatusCode());
        $this->assertSame('processing', $dispute->refresh()->status);
    }

    public function testRejectRequiresEvidenceAndKeepsOrderUntouched()
    {
        $dispute = $this->createDispute();
        $admin = $this->createAdminWithPermissions(['aftersale.handle']);
        $token = $this->loginAs($admin);
        $this->expectNotify(0);

        $path = '/admin/disputes/' . $dispute->id . '/reject';
        $this->assertSame(422, $this->request('POST', $path, $token, ['remark' => '已到账'])->getStatusCode());
        $this->assertSame(422, $this->request('POST', $path, $token, ['remark' => '已到账', 'evidence' => [123]])->getStatusCode());
        $this->assertSame(422, $this->request('POST', $path, $token, ['evidence' => ['凭证']])->getStatusCode());

        $response = $this->request('POST', $path, $token, ['remark' => '供应商确认已到账', 'evidence' => ['运营商流水号 123456']]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $dispute->refresh();
        $this->assertSame('rejected', $dispute->status);
        $this->assertSame($admin->id, $dispute->handler_id);
        $this->assertSame(['运营商流水号 123456'], $dispute->evidence);
        $this->assertNotNull($dispute->resolved_at);
        $this->assertSame('success', Order::find($dispute->order_id)->status);
        $this->assertSame(1, AdminOperationLog::where('target_type', 'aftersale_dispute')->where('target_id', $dispute->id)->where('action', 'reject_dispute')->count());

        $this->assertSame(409, $this->request('POST', '/admin/disputes/' . $dispute->id . '/confirm', $token, ['remark' => '再确认'])->getStatusCode(), '已处理的不能再处理');
    }

    public function testConfirmRefundsVoidsRebateNotifiesAndLogs()
    {
        $dispute = $this->createDispute();
        $order = Order::find($dispute->order_id);
        $rebate = MerchantRebate::create([
            'order_id' => $order->id,
            'merchant_id' => $order->merchant_id,
            'business_line' => 'recharge',
            'level_id' => 1,
            'rebate_base' => '0.50',
            'rebate_base_source' => 'product',
            'rebate_rate' => '1.0000',
            'rebate_rate_source' => 'level',
            'amount' => '0.50',
            'status' => 'pending',
            'order_completed_at' => $order->completed_at,
            'due_at' => date('Y-m-d H:i:s', time() + 86400),
        ]);
        $admin = $this->createAdminWithPermissions(['aftersale.handle', 'aftersale.view']);
        $token = $this->loginAs($admin);
        $this->expectNotify(1);

        $response = $this->request('POST', '/admin/disputes/' . $dispute->id . '/confirm', $token, ['remark' => '供应商确认未充值']);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('confirmed', $body['status']);
        $this->assertSame('refunded', $body['order_status']);
        $this->assertSame('10.00', $body['refunded_amount']);

        $this->assertSame('100.00', Merchant::find($order->merchant_id)->available_balance);
        $this->assertSame('voided', $rebate->refresh()->status);
        $refund = MerchantBalanceLog::where('order_id', $order->id)->where('type', 'refund')->first();
        $this->assertSame($admin->id, $refund->operator_id);

        $this->assertSame(1, AdminOperationLog::where('target_type', 'aftersale_dispute')->where('target_id', $dispute->id)->where('action', 'confirm_dispute')->count());

        $this->assertSame(409, $this->request('POST', '/admin/disputes/' . $dispute->id . '/confirm', $token, ['remark' => '重复'])->getStatusCode());
        $this->assertSame(1, MerchantBalanceLog::where('order_id', $order->id)->where('type', 'refund')->count());

        $detail = json_decode((string) $this->request('GET', '/admin/disputes/' . $dispute->id, $token)->getBody(), true);
        $this->assertSame('voided', $detail['rebate']['status']);
    }

    public function testListFiltersByStatus()
    {
        $processing = $this->createDispute();
        $rejected = $this->createDispute();
        $rejected->fill(['status' => 'rejected'])->save();
        $token = $this->loginAs($this->createAdminWithPermissions(['aftersale.view']));
        $this->expectNotify(0);

        $body = json_decode((string) $this->request('GET', '/admin/disputes?status=processing&merchant_id=' . $processing->merchant_id, $token)->getBody(), true);
        $this->assertSame([$processing->id], array_column($body['data'], 'id'));
        $this->assertSame(422, $this->request('GET', '/admin/disputes?status=bogus', $token)->getStatusCode());
    }

    /**
     * 提交卡速售售后 → 详情看到进展 → 处理中不能重复提交 → 卡速售回调（验签在驱动里，这里 mock）记下状态和说明，
     * 但不自动结案：争议仍是处理中，钱不动。
     */
    public function testSubmitToSupplierAftersaleAndReceiveCallback()
    {
        $dispute = $this->createDispute();
        $order = Order::find($dispute->order_id);
        $supplier = $this->createKasushouSupplier();
        OrderAttempt::create(['order_id' => $order->id, 'supplier_id' => $supplier->id, 'attempt_no' => 2, 'result' => 'success']);
        $token = $this->loginAs($this->createAdminWithPermissions(['aftersale.view', 'aftersale.handle']));
        $this->expectNotify(0);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('submitAftersale')->once()
            ->with($order->order_no . '-2', '商户称未到账，请核实', ['https://img.example/1.png'], Mockery::pattern('#/notify/.+/aftersale$#'))
            ->andReturn(['accepted' => true, 'unknown' => false, 'aftersale_no' => 'AS-' . $dispute->id, 'message' => '提交成功']);
        $driver->shouldReceive('parseAftersaleCallback')->once()->andReturn([
            'aftersale_no' => 'AS-' . $dispute->id, 'external_orderno' => $order->order_no . '-2', 'status' => 'completed', 'reply' => '运营商确认已到账',
        ]);
        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('build')->andReturn($driver);
        ApplicationContext::getContainer()->set(SupplierDriverFactory::class, $factory);

        $backup = getenv(SupplierNotifyAddressService::BASE_URL_ENV);
        putenv(SupplierNotifyAddressService::BASE_URL_ENV . '=https://platform.example');
        try {
            $this->assertSame(422, $this->request('POST', '/admin/disputes/' . $dispute->id . '/supplier-aftersale', $token, ['content' => ''])->getStatusCode());
            $this->assertSame(422, $this->request('POST', '/admin/disputes/' . $dispute->id . '/supplier-aftersale', $token, ['content' => 'x', 'images' => ['javascript:alert(1)']])->getStatusCode());

            $response = $this->request('POST', '/admin/disputes/' . $dispute->id . '/supplier-aftersale', $token, [
                'content' => '商户称未到账，请核实', 'images' => ['https://img.example/1.png'],
            ]);
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $body = json_decode((string) $response->getBody(), true);
            $this->assertSame('processing', $body['supplier_aftersale']['status']);
            $this->assertSame('AS-' . $dispute->id, $body['supplier_aftersale']['aftersale_no']);

            $this->assertSame(409, $this->request('POST', '/admin/disputes/' . $dispute->id . '/supplier-aftersale', $token, ['content' => '再提一次'])->getStatusCode());
        } finally {
            putenv($backup === false ? SupplierNotifyAddressService::BASE_URL_ENV : SupplierNotifyAddressService::BASE_URL_ENV . '=' . $backup);
        }

        $callback = $this->client->request('POST', '/notify/' . $supplier->code . '/' . $supplier->notify_token . '/aftersale', [
            'form_params' => ['id' => 'AS-' . $dispute->id, 'status' => 2, 'time' => '1', 'sign' => 'mocked'],
        ]);
        $this->assertSame(200, $callback->getStatusCode());
        $this->assertSame('ok', (string) $callback->getBody());

        $dispute->refresh();
        $this->assertSame('completed', $dispute->supplier_aftersale_status);
        $this->assertSame('运营商确认已到账', $dispute->supplier_aftersale_reply);
        $this->assertSame('processing', $dispute->status, '不自动结案，客服看说明后驳回或确认');
        $this->assertSame('success', $order->refresh()->status);

        $this->assertSame(404, $this->client->request('POST', '/notify/' . $supplier->code . '/wrong-token/aftersale', ['form_params' => []])->getStatusCode());
    }

    private function createKasushouSupplier(): Supplier
    {
        $unique = uniqid('dispute_aftersale_', true);
        $supplier = Supplier::create([
            'name' => $unique,
            'code' => substr(md5($unique), 0, 24),
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => 'unused-in-test-driver-factory-is-overridden',
            'status' => 'active',
        ]);
        $this->supplierIds[] = $supplier->id;

        return $supplier->refresh();
    }

    private function createDispute(): AftersaleDispute
    {
        $unique = uniqid('admin_dispute_test_', true);
        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => 'encrypted-secret-placeholder',
            'available_balance' => '90.00',
            'frozen_balance' => '0.00',
        ]);
        $this->merchantIds[] = $merchant->id;

        $completedAt = date('Y-m-d H:i:s', time() - 86400);
        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => 'success',
            'sale_price' => '10.00',
            'cost_price' => '8.00',
            'frozen_amount' => '10.00',
            'deducted_amount' => '10.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
            'completed_at' => $completedAt,
            'finished_at' => $completedAt,
        ]);
        $this->orderIds[] = $order->id;

        return AftersaleDispute::create([
            'order_id' => $order->id,
            'merchant_id' => $merchant->id,
            'status' => 'processing',
            'submitted_at' => date('Y-m-d H:i:s'),
        ]);
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
            $permission = AdminPermission::firstOrCreate(['code' => $code], ['module' => 'aftersale', 'name' => $code, 'type' => 'action']);
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
}
