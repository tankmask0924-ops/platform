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

use App\Model\AdminUser;

class AdminUserDao extends AbstractDao
{
    protected string $model = AdminUser::class;

    public function find(int $id): ?AdminUser
    {
        return AdminUser::find($id);
    }

    public function findByUsername(string $username): ?AdminUser
    {
        return $this->newQuery()->where('username', $username)->first();
    }
}
