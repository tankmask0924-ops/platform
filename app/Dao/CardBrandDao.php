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

use App\Model\CardBrand;
use Hyperf\Database\Model\Collection;

class CardBrandDao extends AbstractDao
{
    protected string $model = CardBrand::class;

    public function find(int $id): ?CardBrand
    {
        return CardBrand::find($id);
    }

    /**
     * 全部品牌，按 sort、id 排。品牌是少量配置行，不分页。
     */
    public function listAll(?string $status = null): Collection
    {
        $query = $this->newQuery()->orderBy('sort')->orderBy('id');
        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    /**
     * 按 id 批量取，按 id 作键。
     *
     * @param array<int, int> $ids
     */
    public function findMany(array $ids): Collection
    {
        return $this->newQuery()->whereIn('id', $ids)->get()->keyBy('id');
    }

    public function nameTaken(string $name, ?int $exceptId = null): bool
    {
        $query = $this->newQuery()->where('name', $name);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }
}
