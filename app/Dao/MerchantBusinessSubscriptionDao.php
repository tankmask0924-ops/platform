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

use App\Model\MerchantBusinessSubscription;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Collection;

class MerchantBusinessSubscriptionDao extends AbstractDao
{
    protected string $model = MerchantBusinessSubscription::class;

    /**
     * @return Collection<int, MerchantBusinessSubscription>
     */
    public function listByMerchantId(int $merchantId): Collection
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->get();
    }

    public function isApproved(int $merchantId, string $businessLine): bool
    {
        return $this->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('business_line', $businessLine)
            ->where('status', 'approved')
            ->exists();
    }

    /**
     * 调用方必须已经在事务里。
     */
    public function lockByMerchantAndLine(int $merchantId, string $businessLine): ?MerchantBusinessSubscription
    {
        return $this->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('business_line', $businessLine)
            ->lockForUpdate()
            ->first();
    }

    /**
     * 调用方必须已经在事务里。
     */
    public function lockForUpdate(int $id): ?MerchantBusinessSubscription
    {
        return $this->newQuery()->where('id', $id)->lockForUpdate()->first();
    }

    /**
     * 后台审核列表，待审核的按申请时间先到先审，其余最新在前。
     *
     * @param array{status?: string, business_line?: string, merchant_id?: int} $filters
     * @return Collection<int, MerchantBusinessSubscription>
     */
    public function paginateFiltered(array $filters, int $page, int $perPage): Collection
    {
        $query = $this->filterQuery($filters);
        if (($filters['status'] ?? null) === 'pending') {
            $query->orderBy('applied_at')->orderBy('id');
        } else {
            $query->orderByDesc('applied_at')->orderByDesc('id');
        }

        return $query->forPage($page, $perPage)->get();
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
        foreach (['status', 'business_line', 'merchant_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        return $query;
    }
}
