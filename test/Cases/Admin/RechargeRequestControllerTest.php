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
use App\Model\MerchantRechargeRequest;
use HyperfTest\HttpTestCase;

/**
 * `POST /admin/recharge-requests/{id}/approve|reject` 是 requirements.md 4.3
 * 「充值与调账 - 充值申请审核」的落地，结构模板取自
 * test/Cases/Admin/MerchantControllerTest.php（同一套鉴权/权限测试骨架）。
 *
 * 重点用例是 testDoubleApprovalOnlyCreditsBalanceOnce()：证明并发/重复的第二次
 * 审核请求不仅仅是 HTTP 层面返回 409，而是真的没有再触碰
 * merchants.available_balance / merchant_balance_logs——见该用例内注释。
 *
 * @internal
 * @coversNothing
 */
class RechargeRequestControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private const VIEW_PERMISSION_CODE = 'recharge.view';

    private const MANAGE_PERMISSION_CODE = 'recharge.manage';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $merchantIds = [];

    private array $requestIds = [];

    protected function tearDown(): void
    {
        foreach ($this->requestIds as $id) {
            MerchantRechargeRequest::destroy($id);
        }
        foreach ($this->merchantIds as $id) {
            MerchantBalanceLog::where('merchant_id', $id)->delete();
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
        $this->requestIds = [];
        $this->merchantIds = [];
        $this->adminUserIds = [];
        $this->roleIds = [];
        $this->permissionIds = [];

        parent::tearDown();
    }

    public function testAdminWithViewPermissionSeesTheList()
    {
        $merchant = $this->createMerchant();
        $request = $this->createRechargeRequest($merchant->id);
        $token = $this->loginAs($this->createAdminWithPermissions([self::VIEW_PERMISSION_CODE]));

        $response = $this->client->request('GET', '/admin/recharge-requests', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $ids = array_column($body['data'], 'id');
        $this->assertContains($request->id, $ids);
    }

    public function testListFilteredByStatusOnlyReturnsMatchingRows()
    {
        $merchant = $this->createMerchant();
        $pending = $this->createRechargeRequest($merchant->id, ['status' => 'pending']);
        $approved = $this->createRechargeRequest($merchant->id, ['status' => 'approved']);
        $token = $this->loginAs($this->createAdminWithPermissions([self::VIEW_PERMISSION_CODE]));

        $response = $this->client->request('GET', '/admin/recharge-requests', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'query' => ['status' => 'pending'],
        ]);
        $body = json_decode((string) $response->getBody(), true);

        $ids = array_column($body['data'], 'id');
        $this->assertContains($pending->id, $ids);
        $this->assertNotContains($approved->id, $ids);
    }

    public function testApproveCreditsBalanceAndMarksApproved()
    {
        $merchant = $this->createMerchant(['available_balance' => '10.00', 'frozen_balance' => '3.00']);
        $request = $this->createRechargeRequest($merchant->id, ['amount' => '250.50']);
        $admin = $this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]);
        $token = $this->loginAs($admin);

        $response = $this->client->request('POST', '/admin/recharge-requests/' . $request->id . '/approve', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertSame(200, $response->getStatusCode());

        $merchant->refresh();
        $this->assertSame('260.50', $merchant->available_balance);
        $this->assertSame('3.00', $merchant->frozen_balance);

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($admin->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);

        $logs = MerchantBalanceLog::where('merchant_id', $merchant->id)->where('type', 'recharge')->get();
        $this->assertCount(1, $logs);
        $this->assertSame('250.50', $logs[0]->amount);
        $this->assertSame('10.00', $logs[0]->available_before);
        $this->assertSame('260.50', $logs[0]->available_after);
        $this->assertSame('3.00', $logs[0]->frozen_before);
        $this->assertSame('3.00', $logs[0]->frozen_after);
    }

    /**
     * 这是本任务最重要的一条用例：不能只证明「第二次 approve 返回 409」，必须
     * 证明第二次调用真的没有再让商户余额被多加一次、也没有再多插入一条
     * merchant_balance_logs——否则一个"看起来防住了"但实际上余额已经被
     * BalanceService::recharge() 在校验通过之后、状态更新之前重复调用过的 bug
     * 会被这条测试放过。这里显式在两次 HTTP 调用之间读一次余额快照并断言相等，
     * 而不是只看第二次响应的状态码。
     */
    public function testDoubleApprovalOnlyCreditsBalanceOnce()
    {
        $merchant = $this->createMerchant(['available_balance' => '0.00']);
        $request = $this->createRechargeRequest($merchant->id, ['amount' => '100.00']);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $first = $this->client->request('POST', '/admin/recharge-requests/' . $request->id . '/approve', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->assertSame(200, $first->getStatusCode());

        $merchant->refresh();
        $this->assertSame('100.00', $merchant->available_balance);

        $second = $this->client->request('POST', '/admin/recharge-requests/' . $request->id . '/approve', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->assertSame(409, $second->getStatusCode());

        // 核心断言：第二次调用之后余额跟第一次调用之后完全一样，没有被再加一次 100。
        $merchant->refresh();
        $this->assertSame('100.00', $merchant->available_balance);

        // 核心断言：全程只产生了一条 recharge 流水，不是两条。
        $logs = MerchantBalanceLog::where('merchant_id', $merchant->id)->where('type', 'recharge')->get();
        $this->assertCount(1, $logs);

        $request->refresh();
        $this->assertSame('approved', $request->status);
    }

    public function testApproveOnAlreadyApprovedRequestReturns409()
    {
        $merchant = $this->createMerchant();
        $request = $this->createRechargeRequest($merchant->id, ['status' => 'approved']);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/recharge-requests/' . $request->id . '/approve', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testApproveOnAlreadyRejectedRequestReturns409()
    {
        $merchant = $this->createMerchant();
        $request = $this->createRechargeRequest($merchant->id, ['status' => 'rejected']);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/recharge-requests/' . $request->id . '/approve', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testApproveOnNonexistentIdReturns404()
    {
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/recharge-requests/999999999/approve', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testApproveWithoutManagePermissionGets403EvenWithViewPermission()
    {
        $merchant = $this->createMerchant();
        $request = $this->createRechargeRequest($merchant->id);
        $token = $this->loginAs($this->createAdminWithPermissions([self::VIEW_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/recharge-requests/' . $request->id . '/approve', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testRejectStoresReasonAndDoesNotChangeBalance()
    {
        $merchant = $this->createMerchant(['available_balance' => '10.00']);
        $request = $this->createRechargeRequest($merchant->id, ['amount' => '100.00']);
        $admin = $this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]);
        $token = $this->loginAs($admin);

        $response = $this->client->request('POST', '/admin/recharge-requests/' . $request->id . '/reject', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['reason' => '未查到到账'],
        ]);

        $this->assertSame(200, $response->getStatusCode());

        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertSame('未查到到账', $request->reject_reason);
        $this->assertSame($admin->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);

        $merchant->refresh();
        $this->assertSame('10.00', $merchant->available_balance);

        $logs = MerchantBalanceLog::where('merchant_id', $merchant->id)->get();
        $this->assertCount(0, $logs);
    }

    public function testRejectWithMissingReasonReturnsCleanError()
    {
        $merchant = $this->createMerchant();
        $request = $this->createRechargeRequest($merchant->id);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/recharge-requests/' . $request->id . '/reject', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['reason' => ''],
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());

        $request->refresh();
        $this->assertSame('pending', $request->status);
    }

    public function testRejectOnAlreadyRejectedRequestReturns409()
    {
        $merchant = $this->createMerchant();
        $request = $this->createRechargeRequest($merchant->id, ['status' => 'rejected']);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/recharge-requests/' . $request->id . '/reject', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['reason' => '随便什么原因'],
        ]);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testRejectOnNonexistentIdReturns404()
    {
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/recharge-requests/999999999/reject', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['reason' => '随便什么原因'],
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRejectWithoutManagePermissionGets403EvenWithViewPermission()
    {
        $merchant = $this->createMerchant();
        $request = $this->createRechargeRequest($merchant->id);
        $token = $this->loginAs($this->createAdminWithPermissions([self::VIEW_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/recharge-requests/' . $request->id . '/reject', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['reason' => '随便什么原因'],
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testNoTokenOnAnyAdminRouteReturns401()
    {
        $merchant = $this->createMerchant();
        $request = $this->createRechargeRequest($merchant->id);

        $listResponse = $this->client->request('GET', '/admin/recharge-requests');
        $this->assertSame(401, $listResponse->getStatusCode());

        $approveResponse = $this->client->request('POST', '/admin/recharge-requests/' . $request->id . '/approve');
        $this->assertSame(401, $approveResponse->getStatusCode());

        $rejectResponse = $this->client->request('POST', '/admin/recharge-requests/' . $request->id . '/reject');
        $this->assertSame(401, $rejectResponse->getStatusCode());
    }

    private function loginAs(AdminUser $admin): string
    {
        $login = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => [
                'username' => $admin->username,
                'password' => self::PASSWORD,
            ],
        ]);

        return json_decode((string) $login->getBody(), true)['token'];
    }

    private function createAdminWithPermissions(array $codes): AdminUser
    {
        $role = AdminRole::create([
            'name' => 'role_' . uniqid('', true),
            'is_system' => false,
        ]);
        $this->roleIds[] = $role->id;

        foreach ($codes as $code) {
            $permission = AdminPermission::firstOrCreate(
                ['code' => $code],
                ['module' => 'recharge', 'name' => $code, 'type' => 'action']
            );
            if ($permission->wasRecentlyCreated) {
                $this->permissionIds[] = $permission->id;
            }

            AdminRolePermission::create([
                'role_id' => $role->id,
                'permission_id' => $permission->id,
            ]);
        }

        return $this->createAdmin($role->id);
    }

    private function createAdmin(int $roleId): AdminUser
    {
        $admin = AdminUser::create([
            'username' => 'admin_' . uniqid('', true),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'real_name' => 'Test Admin',
            'role_id' => $roleId,
            'status' => 'active',
        ]);

        $this->adminUserIds[] = $admin->id;

        return $admin;
    }

    private function createMerchant(array $overrides = []): Merchant
    {
        $merchant = Merchant::create(array_merge([
            'type' => 'company',
            'password' => password_hash('whatever', PASSWORD_BCRYPT),
            'status' => 'active',
            'phone' => '187' . random_int(10000000, 99999999),
            'available_balance' => '0.00',
            'frozen_balance' => '0.00',
        ], $overrides));
        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createRechargeRequest(int $merchantId, array $overrides = []): MerchantRechargeRequest
    {
        $request = MerchantRechargeRequest::create(array_merge([
            'merchant_id' => $merchantId,
            'amount' => '100.00',
            'proof_image' => 'https://example.com/proof.png',
            'status' => 'pending',
        ], $overrides));
        $this->requestIds[] = $request->id;

        return $request;
    }
}
