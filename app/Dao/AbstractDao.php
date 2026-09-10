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

use App\Model\Model;
use Hyperf\Database\Model\Builder;

abstract class AbstractDao
{
    /**
     * @var class-string<Model>
     */
    protected string $model;

    public function newQuery(): Builder
    {
        return $this->model::query();
    }

    public function find(int $id): ?Model
    {
        return $this->model::find($id);
    }

    public function findOrFail(int $id): Model
    {
        return $this->model::findOrFail($id);
    }

    public function create(array $attributes): Model
    {
        return $this->model::create($attributes);
    }

    public function update(int $id, array $attributes): bool
    {
        return (bool) $this->findOrFail($id)->fill($attributes)->save();
    }

    public function delete(int $id): bool
    {
        return (bool) $this->model::destroy($id);
    }
}
