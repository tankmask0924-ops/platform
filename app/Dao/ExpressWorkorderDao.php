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

use App\Model\ExpressWorkorder;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Collection;

class ExpressWorkorderDao extends AbstractDao
{
    protected string $model = ExpressWorkorder::class;

    /**
     * @return Collection<int, ExpressWorkorder>
     */
    public function listForOrder(int $orderId): Collection
    {
        return $this->newQuery()->where('order_id', $orderId)->orderByDesc('id')->get();
    }

    public function findBySupplierWorkorderNo(int $supplierId, string $workorderNo): ?ExpressWorkorder
    {
        return $this->newQuery()
            ->where('supplier_id', $supplierId)
            ->where('supplier_workorder_no', $workorderNo)
            ->first();
    }

    /**
     * 同一笔订单、同一类型还在处理中的工单：重复提交同一件事只会让供应商那边多一张单。
     */
    public function hasProcessing(int $orderId, string $type): bool
    {
        return $this->newQuery()
            ->where('order_id', $orderId)
            ->where('type', $type)
            ->where('status', ExpressWorkorder::STATUS_PROCESSING)
            ->exists();
    }

    /**
     * 处理中的工单只允许被结单一次：带状态条件的更新，并发时后一个拿到 0 行。
     *
     * @param array<string, mixed> $attributes
     */
    public function resolveIfProcessing(int $id, array $attributes): bool
    {
        return $this->newQuery()
            ->where('id', $id)
            ->where('status', ExpressWorkorder::STATUS_PROCESSING)
            ->update($attributes + ['updated_at' => date('Y-m-d H:i:s')]) === 1;
    }

    /**
     * 后台列表：处理中的在前（有供应商回复的更靠前，等客服核实），其次按 id 倒序。
     *
     * @param array{status?: string, type?: string, order_id?: int, replied?: bool} $filters
     * @return Collection<int, ExpressWorkorder>
     */
    public function paginateFiltered(array $filters, int $page, int $perPage): Collection
    {
        return $this->filterQuery($filters)
            ->orderByRaw("CASE WHEN status = 'processing' THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN supplier_replied_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countFiltered(array $filters): int
    {
        return $this->filterQuery($filters)->count();
    }

    public function countProcessing(): int
    {
        return $this->newQuery()->where('status', ExpressWorkorder::STATUS_PROCESSING)->count();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function filterQuery(array $filters): Builder
    {
        $query = $this->newQuery();
        foreach (['status', 'type', 'order_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (isset($filters['replied'])) {
            $filters['replied'] ? $query->whereNotNull('supplier_replied_at') : $query->whereNull('supplier_replied_at');
        }

        return $query;
    }
}
