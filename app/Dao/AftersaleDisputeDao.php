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

use App\Model\AftersaleDispute;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Collection;

class AftersaleDisputeDao extends AbstractDao
{
    protected string $model = AftersaleDispute::class;

    public function find(int $id): ?AftersaleDispute
    {
        return $this->newQuery()->find($id);
    }

    /**
     * 限定 merchant_id，商户只能看到自己的争议。
     */
    public function findForMerchant(int $merchantId, int $id): ?AftersaleDispute
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->where('id', $id)->first();
    }

    public function findByOrderId(int $orderId): ?AftersaleDispute
    {
        return $this->newQuery()->where('order_id', $orderId)->first();
    }

    /**
     * 调用方必须已经在事务里。
     */
    public function lockForUpdate(int $id): ?AftersaleDispute
    {
        return $this->newQuery()->where('id', $id)->lockForUpdate()->first();
    }

    /**
     * @param array{merchant_id?: int, status?: string, order_id?: int} $filters
     * @return Collection<int, AftersaleDispute>
     */
    public function paginateFiltered(array $filters, int $page, int $perPage): Collection
    {
        return $this->filterQuery($filters)->orderByDesc('id')->forPage($page, $perPage)->get();
    }

    /**
     * @param array<string, mixed> $filters 同 paginateFiltered()
     */
    public function countFiltered(array $filters): int
    {
        return $this->filterQuery($filters)->count();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function filterQuery(array $filters): Builder
    {
        $query = $this->newQuery();
        foreach (['merchant_id', 'status', 'order_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        return $query;
    }
}
