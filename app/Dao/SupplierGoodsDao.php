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

use App\Model\SupplierGoods;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Collection;

class SupplierGoodsDao extends AbstractDao
{
    protected string $model = SupplierGoods::class;

    public function find(int $id): ?SupplierGoods
    {
        return SupplierGoods::find($id);
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

    public function findBySupplierAndCode(int $supplierId, string $code): ?SupplierGoods
    {
        return $this->newQuery()
            ->where('supplier_id', $supplierId)
            ->where('code', $code)
            ->first();
    }

    /**
     * 按 `(supplier_id, code)` 批量取，先用两个 whereIn 缩小范围，调用方按两列组合核对。
     *
     * @param array<int, int> $supplierIds
     * @param array<int, string> $codes
     */
    public function listBySupplierCodes(array $supplierIds, array $codes): Collection
    {
        return $this->newQuery()
            ->whereIn('supplier_id', $supplierIds)
            ->whereIn('code', $codes)
            ->get();
    }

    /**
     * 同步写入一条供应商商品：`(supplier_id, code)` 已存在就更新，不存在就新建。
     * 供应商没返回名称、面值、商品类型时保留库里原来的值，不拿 null 覆盖。
     *
     * @param array{supplier_product_code: string, cost_price: string, status: string, stock: null|int, name?: null|string, face_value?: null|string, goods_type?: null|string} $detail
     */
    public function upsertFromSync(int $supplierId, array $detail): SupplierGoods
    {
        $attributes = [
            'cost_price' => $detail['cost_price'],
            'status' => $detail['status'],
            'stock' => $detail['stock'],
            'synced_at' => date('Y-m-d H:i:s'),
        ];
        foreach (['name', 'face_value', 'goods_type'] as $field) {
            if (($detail[$field] ?? null) !== null) {
                $attributes[$field] = $detail[$field];
            }
        }

        $goods = $this->findBySupplierAndCode($supplierId, $detail['supplier_product_code'])
            ?? new SupplierGoods(['supplier_id' => $supplierId, 'code' => $detail['supplier_product_code']]);
        $goods->fill($attributes)->save();

        return $goods;
    }

    /**
     * 后台选供应商商品用的分页列表，按供应商、编码排序。
     *
     * @param array<int, int> $supplierIds 限定在这些供应商里（按业务线筛选时由调用方算好）
     * @param null|string $goodsType card_secret/virtual；类型未知的也会列出来
     */
    public function paginate(int $page, int $perPage, array $supplierIds, ?string $keyword, ?string $status, ?string $goodsType = null): Collection
    {
        return $this->filteredQuery($supplierIds, $keyword, $status, $goodsType)
            ->orderBy('supplier_id')
            ->orderBy('code')
            ->forPage($page, $perPage)
            ->get();
    }

    /**
     * @param array<int, int> $supplierIds
     */
    public function count(array $supplierIds, ?string $keyword, ?string $status, ?string $goodsType = null): int
    {
        return $this->filteredQuery($supplierIds, $keyword, $status, $goodsType)->count();
    }

    /**
     * @param array<int, int> $supplierIds
     */
    private function filteredQuery(array $supplierIds, ?string $keyword, ?string $status, ?string $goodsType): Builder
    {
        $query = $this->newQuery()->whereIn('supplier_id', $supplierIds);

        if ($keyword !== null) {
            $escaped = addcslashes($keyword, '%_\\');
            $query->where(function (Builder $q) use ($keyword, $escaped) {
                $q->where('code', $keyword)->orWhere('name', 'like', '%' . $escaped . '%');
            });
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        // 按类型筛时，类型未知（供应商没给）的也列出来，让运营自己判断
        if ($goodsType !== null) {
            $query->where(function (Builder $q) use ($goodsType) {
                $q->where('goods_type', $goodsType)->orWhereNull('goods_type');
            });
        }

        return $query;
    }
}
