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

use App\Model\AdminOperationLog;
use App\Model\AdminRole;
use App\Model\AdminUser;
use App\Service\Admin\AdminBootstrapService;
use HyperfTest\HttpTestCase;

/**
 * 管理员账号、修改自己的密码、/admin/auth/me 返回权限。
 *
 * "至少保留一个启用的超级管理员"依赖整个库里超管的数量，共享开发库里有真实超管账号，这里不测。
 *
 * @internal
 * @coversNothing
 */
class AdminUserControllerTest extends HttpTestCase
{
    use CreatesAdmins;

    private const PASSWORD = 'correct-password';

    protected function tearDown(): void
    {
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testMeReturnsPermissionCodes()
    {
        $admin = $this->createAdminWithPermissions(['order.view', 'merchant.view']);
        $body = $this->body($this->jsonRequest('GET', '/admin/auth/me', $this->loginAs($admin)));

        $this->assertSame(['merchant.view', 'order.view'], $body['permissions']);
        $this->assertFalse($body['is_super_admin']);

        $super = $this->body($this->jsonRequest('GET', '/admin/auth/me', $this->loginAs($this->createSuperAdmin())));
        $this->assertTrue($super['is_super_admin']);
        $this->assertContains('setting.manage', $super['permissions']);
    }

    public function testChangeOwnPasswordInvalidatesOldToken()
    {
        $admin = $this->createAdminWithPermissions([]);
        $oldToken = $this->loginAs($admin);

        $this->assertSame(422, $this->jsonRequest('PUT', '/admin/auth/password', $oldToken, ['old_password' => 'wrong-password', 'new_password' => 'another-password'])->getStatusCode());
        $response = $this->jsonRequest('PUT', '/admin/auth/password', $oldToken, ['old_password' => self::PASSWORD, 'new_password' => 'another-password']);
        $newToken = $this->body($response)['token'] ?? null;

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(401, $this->jsonRequest('GET', '/admin/auth/me', $oldToken)->getStatusCode());
        $this->assertSame(200, $this->jsonRequest('GET', '/admin/auth/me', $newToken)->getStatusCode());
        $this->assertNotNull($this->loginAs($admin, 'another-password'));
    }

    public function testCreateListAndUpdateAdminUser()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['admin_user.view', 'admin_user.manage']));
        $role = $this->createRole(['order.view']);
        $username = 'new_' . substr(uniqid(), -8);

        $created = $this->jsonRequest('POST', '/admin/admin-users', $token, [
            'username' => $username,
            'password' => 'initial-password',
            'real_name' => '张三',
            'role_id' => $role->id,
        ]);
        $id = $this->body($created)['id'] ?? null;
        if ($id) {
            $this->adminUserIds[] = $id;
        }

        $this->assertSame(200, $created->getStatusCode());
        $this->assertSame($role->name, $this->body($created)['role_name']);
        $this->assertSame(422, $this->jsonRequest('POST', '/admin/admin-users', $token, [
            'username' => $username, 'password' => 'initial-password', 'role_id' => $role->id,
        ])->getStatusCode());

        $list = $this->body($this->jsonRequest('GET', '/admin/admin-users?keyword=' . $username, $token));
        $this->assertSame(1, $list['total']);

        $updated = $this->jsonRequest('PUT', "/admin/admin-users/{$id}", $token, ['real_name' => '李四']);
        $this->assertSame('李四', $this->body($updated)['real_name']);
        $this->assertSame(1, AdminOperationLog::where('action', 'update_admin_user')->where('target_id', $id)->count());
    }

    public function testNonSuperAdminCannotCreateOrEditSuperAdmins()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['admin_user.manage']));
        $superRole = AdminRole::where('name', AdminBootstrapService::SUPER_ADMIN_ROLE_NAME)->first()
            ?? AdminRole::find($this->createSuperAdmin()->role_id);
        $super = $this->createAdmin($superRole->id);

        $this->assertSame(403, $this->jsonRequest('POST', '/admin/admin-users', $token, [
            'username' => 'x_' . substr(uniqid(), -8), 'password' => 'initial-password', 'role_id' => $superRole->id,
        ])->getStatusCode());
        $this->assertSame(403, $this->jsonRequest('POST', "/admin/admin-users/{$super->id}/status", $token, ['status' => 'disabled'])->getStatusCode());
        $this->assertSame(403, $this->jsonRequest('POST', "/admin/admin-users/{$super->id}/password", $token, ['password' => 'hijack-password'])->getStatusCode());
    }

    public function testCannotDisableSelfOrChangeOwnRole()
    {
        $admin = $this->createAdminWithPermissions(['admin_user.manage']);
        $token = $this->loginAs($admin);
        $otherRole = $this->createRole([]);

        $this->assertSame(422, $this->jsonRequest('POST', "/admin/admin-users/{$admin->id}/status", $token, ['status' => 'disabled'])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('PUT', "/admin/admin-users/{$admin->id}", $token, ['role_id' => $otherRole->id])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('POST', "/admin/admin-users/{$admin->id}/password", $token, ['password' => 'another-password'])->getStatusCode());
    }

    public function testDisableAndResetPasswordKickOutTheTarget()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['admin_user.manage']));
        $target = $this->createAdminWithPermissions([]);
        $targetToken = $this->loginAs($target);

        $this->assertSame(200, $this->jsonRequest('POST', "/admin/admin-users/{$target->id}/password", $token, ['password' => 'reset-password'])->getStatusCode());
        $this->assertSame(401, $this->jsonRequest('GET', '/admin/auth/me', $targetToken)->getStatusCode());

        $targetToken = $this->loginAs($target, 'reset-password');
        $this->assertSame(200, $this->jsonRequest('POST', "/admin/admin-users/{$target->id}/status", $token, ['status' => 'disabled'])->getStatusCode());
        $this->assertSame(401, $this->jsonRequest('GET', '/admin/auth/me', $targetToken)->getStatusCode());
        $this->assertSame('disabled', AdminUser::find($target->id)->status);
    }

    public function testViewOnlyAdminCannotWrite()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['admin_user.view']));

        $this->assertSame(200, $this->jsonRequest('GET', '/admin/admin-users', $token)->getStatusCode());
        $this->assertSame(200, $this->jsonRequest('GET', '/admin/admin-users/role-options', $token)->getStatusCode());
        $this->assertSame(403, $this->jsonRequest('POST', '/admin/admin-users', $token, [])->getStatusCode());
    }
}
