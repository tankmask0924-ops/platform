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

namespace App\Service\Admin;

use App\Dao\AdminOperationLogDao;
use App\Dao\AdminRoleDao;
use App\Dao\AdminRolePermissionDao;
use App\Dao\AdminUserDao;
use App\Model\AdminRole;
use App\Model\AdminUser;
use App\Service\AbstractService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统设置 - 角色权限（requirements.md 8.3「自定义角色、勾选操作权限」）。
 *
 * - 超级管理员角色固定拥有全部权限（admin:sync-permissions 负责补齐），不能改、不能删；
 * - 预置角色（运营/财务/客服）可以改权限和备注，不能改名（admin:sync-permissions 按名字判断是否要补建）、不能删；
 * - 防止提权：不能改自己所在角色的权限；给角色新增的权限必须是操作人自己拥有的（超管不受限）；
 * - 还有管理员在用的角色不能删。
 */
class RoleAdminService extends AbstractService
{
    private const MODULE = 'system';

    #[Inject]
    protected AdminRoleDao $adminRoleDao;

    #[Inject]
    protected AdminRolePermissionDao $adminRolePermissionDao;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    #[Inject]
    protected AdminBootstrapService $bootstrapService;

    /**
     * @return list<array{id: int, name: string, is_super_admin: bool}>
     */
    public function options(): array
    {
        return $this->adminRoleDao->newQuery()->orderBy('id')->get()
            ->map(fn (AdminRole $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'is_super_admin' => $this->isSuperAdminRole($role),
            ])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $userCounts = $this->adminUserDao->newQuery()
            ->selectRaw('role_id, count(*) as n')
            ->groupBy('role_id')
            ->pluck('n', 'role_id');

        return $this->adminRoleDao->newQuery()->orderBy('id')->get()
            ->map(fn (AdminRole $role) => $this->format($role) + ['user_count' => (int) ($userCounts[$role->id] ?? 0)])
            ->values()->all();
    }

    /**
     * 可勾选的权限（代码里已知的全部权限）。
     *
     * @return list<array{code: string, module: string, name: string}>
     */
    public function permissions(): array
    {
        return array_map(
            static fn (array $p) => ['code' => $p['code'], 'module' => $p['module'], 'name' => $p['name']],
            AdminBootstrapService::KNOWN_PERMISSIONS
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(AdminUser $operator, array $data, ?string $ip): array
    {
        $name = $this->validateName($data['name'] ?? null, null);
        $codes = $this->validateCodes($data['permissions'] ?? []);
        $this->guardGrantable($operator, $codes, []);

        return Db::transaction(function () use ($operator, $name, $data, $codes, $ip) {
            $role = $this->adminRoleDao->create([
                'name' => $name,
                'is_system' => false,
                'remark' => $this->remark($data['remark'] ?? null),
            ]);
            $this->syncPermissions($role, $codes);
            $after = $this->format($role);
            $this->operationLogDao->record($operator->id, self::MODULE, 'create_role', 'admin_role', $role->id, null, $after, $ip);

            return $after;
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(AdminUser $operator, int $id, array $data, ?string $ip): array
    {
        $role = $this->findOrFail($id);
        if ($this->isSuperAdminRole($role)) {
            throw new HttpException(422, '超级管理员角色固定拥有全部权限，不能修改');
        }

        return Db::transaction(function () use ($operator, $role, $data, $ip) {
            $before = $this->format($role);
            $attrs = [];

            if (array_key_exists('name', $data) && trim((string) $data['name']) !== $role->name) {
                if ($role->is_system) {
                    throw new HttpException(422, '预置角色不能改名');
                }
                $attrs['name'] = $this->validateName($data['name'], $role->id);
            }
            if (array_key_exists('remark', $data)) {
                $attrs['remark'] = $this->remark($data['remark']);
            }
            if (array_key_exists('permissions', $data)) {
                if ($role->id === $operator->role_id) {
                    throw new HttpException(422, '不能修改自己所在角色的权限');
                }
                $codes = $this->validateCodes($data['permissions']);
                $this->guardGrantable($operator, $codes, $before['permissions']);
                $this->syncPermissions($role, $codes);
            }
            if ($attrs !== []) {
                $this->adminRoleDao->update($role->id, $attrs);
            }

            $after = $this->format($this->findOrFail($role->id));
            $this->operationLogDao->record($operator->id, self::MODULE, 'update_role', 'admin_role', $role->id, $before, $after, $ip);

            return $after;
        });
    }

    public function delete(AdminUser $operator, int $id, ?string $ip): void
    {
        $role = $this->findOrFail($id);
        if ($role->is_system) {
            throw new HttpException(422, '预置角色不能删除');
        }
        if ($this->adminUserDao->newQuery()->where('role_id', $role->id)->exists()) {
            throw new HttpException(422, '还有管理员在使用这个角色，先把他们改到其他角色');
        }

        Db::transaction(function () use ($operator, $role, $ip) {
            $before = $this->format($role);
            $this->adminRolePermissionDao->replaceForRole($role->id, []);
            $this->adminRoleDao->delete($role->id);
            $this->operationLogDao->record($operator->id, self::MODULE, 'delete_role', 'admin_role', $role->id, $before, null, $ip);
        });
    }

    private function findOrFail(int $id): AdminRole
    {
        $role = $this->adminRoleDao->find($id);
        if (! $role) {
            throw new HttpException(404, '角色不存在');
        }

        return $role;
    }

    private function validateName(mixed $name, ?int $exceptId): string
    {
        $name = trim(is_string($name) ? $name : '');
        if ($name === '' || mb_strlen($name) > 32) {
            throw new HttpException(422, '角色名称为 1~32 个字');
        }
        $exists = $this->adminRoleDao->newQuery()
            ->where('name', $name)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
        if ($exists || $name === AdminBootstrapService::SUPER_ADMIN_ROLE_NAME) {
            throw new HttpException(422, "角色「{$name}」已存在");
        }

        return $name;
    }

    /**
     * @return list<string>
     */
    private function validateCodes(mixed $codes): array
    {
        if (! is_array($codes)) {
            throw new HttpException(422, 'permissions 必须是权限编码数组');
        }
        $known = array_column(AdminBootstrapService::KNOWN_PERMISSIONS, 'code');
        foreach ($codes as $code) {
            if (! is_string($code) || ! in_array($code, $known, true)) {
                throw new HttpException(422, '未知的权限编码：' . (is_string($code) ? $code : gettype($code)));
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * 新增的权限必须是操作人自己拥有的；去掉权限不限制。
     *
     * @param list<string> $codes
     * @param list<string> $current
     */
    private function guardGrantable(AdminUser $operator, array $codes, array $current): void
    {
        $operatorRole = $this->adminRoleDao->find($operator->role_id);
        if ($operatorRole !== null && $this->isSuperAdminRole($operatorRole)) {
            return;
        }
        $mine = $this->adminRolePermissionDao->codesForRole($operator->role_id);
        $beyond = array_diff($codes, $current, $mine);
        if ($beyond !== []) {
            throw new HttpException(403, '不能授予自己没有的权限：' . implode('、', $beyond));
        }
    }

    /**
     * @param list<string> $codes
     */
    private function syncPermissions(AdminRole $role, array $codes): void
    {
        $ids = array_map(fn (string $code) => $this->bootstrapService->ensurePermission($code)->id, $codes);
        $this->adminRolePermissionDao->replaceForRole($role->id, $ids);
    }

    private function remark(mixed $remark): ?string
    {
        $remark = trim(is_string($remark) ? $remark : '');

        return $remark === '' ? null : mb_substr($remark, 0, 255);
    }

    private function isSuperAdminRole(AdminRole $role): bool
    {
        return $role->name === AdminBootstrapService::SUPER_ADMIN_ROLE_NAME;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(AdminRole $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'remark' => $role->remark,
            'is_system' => (bool) $role->is_system,
            'is_super_admin' => $this->isSuperAdminRole($role),
            'permissions' => $this->adminRolePermissionDao->codesForRole($role->id),
        ];
    }
}
