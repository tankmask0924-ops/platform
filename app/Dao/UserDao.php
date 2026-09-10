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

use App\Model\User;
use Hyperf\Database\Model\Collection;

class UserDao extends AbstractDao
{
    protected string $model = User::class;

    public function find(int $id): ?User
    {
        return User::findFromCache($id);
    }

    public function paginate(int $page, int $perPage): Collection
    {
        return $this->newQuery()
            ->forPage($page, $perPage)
            ->get();
    }
}
