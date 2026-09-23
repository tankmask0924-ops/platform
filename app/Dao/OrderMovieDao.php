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

use App\Model\OrderMovie;
use Hyperf\Database\Model\Collection;

class OrderMovieDao extends AbstractDao
{
    protected string $model = OrderMovie::class;

    public function findByOrderId(int $orderId): ?OrderMovie
    {
        return $this->newQuery()->where('order_id', $orderId)->first();
    }

    /**
     * 在事务里锁住明细行：出票结果、超时释放、确认出票可能同时推进同一笔订单，
     * 先拿这把锁再比较状态和动钱（同 OrderExpressDao::lockForUpdate()）。
     */
    public function lockForUpdate(int $orderId): ?OrderMovie
    {
        return $this->newQuery()->where('order_id', $orderId)->lockForUpdate()->first();
    }

    /**
     * 锁座已过期、商户还没确认出票、订单仍在处理中的订单明细（超时释放任务用），最早到期的在前。
     *
     * @return Collection<int, OrderMovie>
     */
    public function listExpiredUnconfirmed(string $now, int $limit): Collection
    {
        return $this->newQuery()
            ->select('order_movies.*')
            ->join('orders', 'orders.id', '=', 'order_movies.order_id')
            ->whereIn('orders.status', ['processing', 'abnormal'])
            ->whereNull('order_movies.confirmed_at')
            ->where('order_movies.lock_expire_at', '<=', $now)
            ->orderBy('order_movies.lock_expire_at')
            ->limit($limit)
            ->get();
    }
}
