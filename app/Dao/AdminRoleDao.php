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

use App\Model\AdminRole;

class AdminRoleDao extends AbstractDao
{
    protected string $model = AdminRole::class;

    public function find(int $id): ?AdminRole
    {
        return AdminRole::find($id);
    }
}
