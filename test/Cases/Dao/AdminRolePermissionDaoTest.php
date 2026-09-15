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

namespace HyperfTest\Cases\Dao;

use App\Dao\AdminRolePermissionDao;
use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use Hyperf\Testing\TestCase;

/**
 * RBAC 权限校验的核心方法：App\Dao\AdminRolePermissionDao::roleHasPermission()，
 * App\Middleware\AdminPermissionMiddleware 直接依赖这个方法的结果做 403 判断。
 *
 * @internal
 * @coversNothing
 */
class AdminRolePermissionDaoTest extends TestCase
{
    private array $roleIds = [];

    private array $permissionIds = [];

    protected function tearDown(): void
    {
        foreach ($this->roleIds as $id) {
            AdminRolePermission::where('role_id', $id)->delete();
            AdminRole::destroy($id);
        }
        foreach ($this->permissionIds as $id) {
            AdminPermission::destroy($id);
        }
        $this->roleIds = [];
        $this->permissionIds = [];

        parent::tearDown();
    }

    public function testRoleHasPermissionReturnsTrueWhenGranted()
    {
        $dao = $this->getContainer()->get(AdminRolePermissionDao::class);
        $role = $this->createRole();
        $code = $this->uniqueCode('merchant.view');
        $permission = $this->createPermission($code);
        $this->grant($role->id, $permission->id);

        $this->assertTrue($dao->roleHasPermission($role->id, $code));
    }

    public function testRoleHasPermissionReturnsFalseWhenNotGranted()
    {
        $dao = $this->getContainer()->get(AdminRolePermissionDao::class);
        $role = $this->createRole();
        $code = $this->uniqueCode('merchant.approve');
        $this->createPermission($code);
        // 故意不 grant，验证「权限存在，但这个角色没有」的情况。

        $this->assertFalse($dao->roleHasPermission($role->id, $code));
    }

    public function testRoleHasPermissionReturnsFalseForUnknownCode()
    {
        $dao = $this->getContainer()->get(AdminRolePermissionDao::class);
        $role = $this->createRole();

        $this->assertFalse($dao->roleHasPermission($role->id, 'does-not-exist-' . uniqid('', true)));
    }

    public function testRoleHasPermissionDoesNotLeakToOtherRoles()
    {
        $dao = $this->getContainer()->get(AdminRolePermissionDao::class);
        $roleWithGrant = $this->createRole();
        $roleWithoutGrant = $this->createRole();
        $code = $this->uniqueCode('merchant.view');
        $permission = $this->createPermission($code);
        $this->grant($roleWithGrant->id, $permission->id);

        $this->assertTrue($dao->roleHasPermission($roleWithGrant->id, $code));
        $this->assertFalse($dao->roleHasPermission($roleWithoutGrant->id, $code));
    }

    private function uniqueCode(string $base): string
    {
        return $base . '_' . uniqid('', true);
    }

    private function createRole(): AdminRole
    {
        $role = AdminRole::create([
            'name' => 'role_' . uniqid('', true),
            'is_system' => false,
        ]);

        $this->roleIds[] = $role->id;

        return $role;
    }

    private function createPermission(string $code): AdminPermission
    {
        $permission = AdminPermission::create([
            'code' => $code,
            'module' => 'merchant',
            'name' => $code,
            'type' => 'action',
        ]);

        $this->permissionIds[] = $permission->id;

        return $permission;
    }

    private function grant(int $roleId, int $permissionId): void
    {
        AdminRolePermission::create([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);
    }
}
