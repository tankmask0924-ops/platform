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

class AdminPermissionDao extends AbstractDao
{
    protected string $model = AdminPermission::class;

    public function find(int $id): ?AdminPermission
    {
        return AdminPermission::find($id);
    }

    public function findByCode(string $code): ?AdminPermission
    {
        return $this->newQuery()->where('code', $code)->first();
    }
}
