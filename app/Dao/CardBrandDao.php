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
    public function listAll(?string $status = null, ?int $categoryId = null): Collection
    {
        $query = $this->newQuery()->orderBy('sort')->orderBy('id');
        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($categoryId !== null) {
            $query->where('category_id', $categoryId);
        }

        return $query->get();
    }

    /**
     * 某个分类下全部品牌的 id（商品列表按分类筛选用）。
     *
     * @return array<int, int>
     */
    public function idsInCategory(int $categoryId): array
    {
        return $this->newQuery()->where('category_id', $categoryId)->pluck('id')->map(static fn ($id) => (int) $id)->all();
    }

    /**
     * 每个分类下有多少个品牌，按分类 id 作键（没有品牌的分类不在结果里）。
     *
     * @param array<int, int> $categoryIds
     * @return array<int, int>
     */
    public function countByCategory(array $categoryIds): array
    {
        return $this->newQuery()
            ->whereIn('category_id', $categoryIds)
            ->groupBy('category_id')
            ->selectRaw('category_id, count(*) as total')
            ->pluck('total', 'category_id')
            ->map(static fn ($total) => (int) $total)
            ->all();
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
