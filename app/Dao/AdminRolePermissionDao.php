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

namespace App\Dao;

use App\Model\AdminPermission;
use App\Model\AdminRolePermission;

/**
 * RBAC 权限校验的落点：`App\Middleware\AdminPermissionMiddleware` 需要回答
 * 「某个 role_id 是否拥有某个权限 code」，这个问题天然是 admin_role_permissions
 * 这张 pivot 表的查询，放在这个 Dao 而不是 AdminPermissionDao/AdminRoleDao 里，
 * 因为它本质是「pivot 表 join 一次 admin_permissions 按 code 反查」，
 * pivot 表自己的 Dao 最适合承担这个 join。
 */
class AdminRolePermissionDao extends AbstractDao
{
    protected string $model = AdminRolePermission::class;

    public function find(int $id): ?AdminRolePermission
    {
        return AdminRolePermission::find($id);
    }

    public function roleHasPermission(int $roleId, string $code): bool
    {
        return $this->newQuery()
            ->where('admin_role_permissions.role_id', $roleId)
            ->join(
                (new AdminPermission())->getTable(),
                'admin_role_permissions.permission_id',
                '=',
                'admin_permissions.id'
            )
            ->where('admin_permissions.code', $code)
            ->exists();
    }
}
