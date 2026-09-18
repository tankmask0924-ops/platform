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
use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use App\Model\AdminUser;
use App\Service\Admin\AdminBootstrapService;

use function Hyperf\Support\make;

/**
 * 建测试管理员 / 角色、登录、发 JSON 请求，tearDown 时调用 cleanUpAdmins() 清理。
 */
trait CreatesAdmins
{
    private array $adminUserIds = [];

    private array $roleIds = [];

    private function cleanUpAdmins(): void
    {
        if ($this->adminUserIds !== []) {
            AdminOperationLog::whereIn('admin_user_id', $this->adminUserIds)->delete();
        }
        AdminUser::destroy($this->adminUserIds);
        foreach ($this->roleIds as $id) {
            AdminRolePermission::where('role_id', $id)->delete();
            AdminRole::destroy($id);
        }
        $this->adminUserIds = [];
        $this->roleIds = [];
    }

    /**
     * @param list<string> $codes
     */
    private function createRole(array $codes, string $prefix = 'role_'): AdminRole
    {
        $role = AdminRole::create(['name' => $prefix . substr(uniqid('', true), -12), 'is_system' => false]);
        $this->roleIds[] = $role->id;
        foreach ($codes as $code) {
            $definition = array_values(array_filter(AdminBootstrapService::KNOWN_PERMISSIONS, fn ($p) => $p['code'] === $code))[0];
            $permission = AdminPermission::firstOrCreate(['code' => $code], $definition);
            AdminRolePermission::create(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }

        return $role;
    }

    /**
     * @param list<string> $codes
     */
    private function createAdminWithPermissions(array $codes): AdminUser
    {
        return $this->createAdmin($this->createRole($codes)->id);
    }

    private function createSuperAdmin(): AdminUser
    {
        $role = AdminRole::where('name', AdminBootstrapService::SUPER_ADMIN_ROLE_NAME)->first();
        if ($role === null) {
            // 全新的库还没有超管角色：借 bootstrap 建出来，这个角色本身不清理
            $admin = make(AdminBootstrapService::class)->createSuperAdmin('bootstrap_' . uniqid(), self::PASSWORD);
            $this->adminUserIds[] = $admin->id;
            $role = AdminRole::find($admin->role_id);
        }

        return $this->createAdmin($role->id);
    }

    private function createAdmin(int $roleId, string $status = 'active'): AdminUser
    {
        $admin = AdminUser::create([
            'username' => 'admin_' . substr(uniqid('', true), -14),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'real_name' => 'Test Admin',
            'role_id' => $roleId,
            'status' => $status,
        ]);
        $this->adminUserIds[] = $admin->id;

        return $admin;
    }

    private function loginAs(AdminUser $admin, string $password = self::PASSWORD): ?string
    {
        $response = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => ['username' => $admin->username, 'password' => $password],
        ]);

        return json_decode((string) $response->getBody(), true)['token'] ?? null;
    }

    private function jsonRequest(string $method, string $path, ?string $token, array $data = [])
    {
        $options = ['headers' => ['Content-Type' => 'application/json']];
        if ($token !== null) {
            $options['headers']['Authorization'] = 'Bearer ' . $token;
        }
        if ($method !== 'GET') {
            $options['json'] = $data;
        }

        return $this->client->request($method, $path, $options);
    }

    private function body($response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }
}
