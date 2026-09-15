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

namespace App\Model;

/**
 * `admin_roles` <-> `admin_permissions` 的纯 pivot 表，没有自己的时间戳列
 * （见 migrations/2026_09_14_090300_create_admin_role_permissions_table.php，
 * 只有 role_id + permission_id 的联合唯一索引），所以关掉 Model 默认的 timestamps。
 *
 * @property int $id
 * @property int $role_id
 * @property int $permission_id
 */
class AdminRolePermission extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'admin_role_permissions';

    protected array $fillable = [
        'role_id',
        'permission_id',
    ];
}
