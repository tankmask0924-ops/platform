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

use App\Model\SupplierCallLog;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Collection;

class SupplierCallLogDao extends AbstractDao
{
    protected string $model = SupplierCallLog::class;

    /**
     * @param array{supplier_id: int, action?: string, order_id?: int, created_from?: string, created_to?: string} $filters
     */
    public function paginateFiltered(array $filters, int $page, int $perPage): Collection
    {
        return $this->filterQuery($filters)->orderByDesc('id')->forPage($page, $perPage)->get();
    }

    /**
     * @param array{supplier_id: int, action?: string, order_id?: int, created_from?: string, created_to?: string} $filters
     */
    public function countFiltered(array $filters): int
    {
        return $this->filterQuery($filters)->count();
    }

    /**
     * 走 (supplier_id, created_at) / (order_id, created_at) 索引。
     *
     * @param array<string, mixed> $filters
     */
    private function filterQuery(array $filters): Builder
    {
        $query = $this->newQuery()->where('supplier_id', $filters['supplier_id']);
        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (isset($filters['order_id'])) {
            $query->where('order_id', $filters['order_id']);
        }
        if (isset($filters['created_from'])) {
            $query->where('created_at', '>=', $filters['created_from']);
        }
        if (isset($filters['created_to'])) {
            $query->where('created_at', '<=', $filters['created_to']);
        }

        return $query;
    }
}
