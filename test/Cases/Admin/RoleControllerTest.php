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

use App\Model\AdminRole;
use App\Service\Admin\AdminBootstrapService;
use HyperfTest\HttpTestCase;

/**
 * 角色权限：增删改、防提权规则。
 *
 * @internal
 * @coversNothing
 */
class RoleControllerTest extends HttpTestCase
{
    use CreatesAdmins;

    private const PASSWORD = 'correct-password';

    protected function tearDown(): void
    {
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testCreateUpdateAndDeleteRole()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['role.view', 'role.manage', 'order.view', 'order.manage']));

        $created = $this->jsonRequest('POST', '/admin/roles', $token, [
            'name' => 'r_' . substr(uniqid(), -8),
            'remark' => '测试',
            'permissions' => ['order.view'],
        ]);
        $id = $this->body($created)['id'] ?? null;
        if ($id) {
            $this->roleIds[] = $id;
        }
        $this->assertSame(200, $created->getStatusCode());
        $this->assertSame(['order.view'], $this->body($created)['permissions']);

        $updated = $this->jsonRequest('PUT', "/admin/roles/{$id}", $token, ['permissions' => ['order.view', 'order.manage']]);
        $this->assertSame(['order.manage', 'order.view'], $this->body($updated)['permissions']);

        $roles = $this->body($this->jsonRequest('GET', '/admin/roles', $token));
        $this->assertContains($id, array_column($roles, 'id'));
        $this->assertNotEmpty($this->body($this->jsonRequest('GET', '/admin/roles/permissions', $token)));

        $this->assertSame(200, $this->jsonRequest('DELETE', "/admin/roles/{$id}", $token)->getStatusCode());
        $this->assertNull(AdminRole::find($id));
    }

    public function testCannotGrantPermissionsOperatorDoesNotHave()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['role.manage', 'order.view']));
        $role = $this->createRole([]);

        $this->assertSame(403, $this->jsonRequest('POST', '/admin/roles', $token, [
            'name' => 'r_' . substr(uniqid(), -8),
            'permissions' => ['order.view', 'merchant.balance_adjust'],
        ])->getStatusCode());
        $this->assertSame(403, $this->jsonRequest('PUT', "/admin/roles/{$role->id}", $token, ['permissions' => ['setting.manage']])->getStatusCode());
        $this->assertSame(200, $this->jsonRequest('PUT', "/admin/roles/{$role->id}", $token, ['permissions' => ['order.view']])->getStatusCode());
    }

    public function testCannotEditOwnRoleSuperAdminRoleOrUnknownCodes()
    {
        $admin = $this->createAdminWithPermissions(['role.manage']);
        $token = $this->loginAs($admin);
        $superRole = AdminRole::where('name', AdminBootstrapService::SUPER_ADMIN_ROLE_NAME)->first()
            ?? AdminRole::find($this->createSuperAdmin()->role_id);
        $other = $this->createRole([]);

        $this->assertSame(422, $this->jsonRequest('PUT', "/admin/roles/{$admin->role_id}", $token, ['permissions' => ['role.manage', 'role.view']])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('PUT', "/admin/roles/{$superRole->id}", $token, ['remark' => 'x'])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('DELETE', "/admin/roles/{$superRole->id}", $token)->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('PUT', "/admin/roles/{$other->id}", $token, ['permissions' => ['no.such_code']])->getStatusCode());
    }

    public function testCannotDeleteRoleInUse()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['role.manage']));
        $role = $this->createRole([]);
        $this->createAdmin($role->id);

        $this->assertSame(422, $this->jsonRequest('DELETE', "/admin/roles/{$role->id}", $token)->getStatusCode());
    }
}
