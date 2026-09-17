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

use App\Model\OrderAttempt;
use Hyperf\Database\Exception\QueryException;
use Hyperf\Database\Model\Collection;

class OrderAttemptDao extends AbstractDao
{
    protected string $model = OrderAttempt::class;

    public function find(int $id): ?OrderAttempt
    {
        return OrderAttempt::find($id);
    }

    /**
     * 某个订单的全部尝试记录，按 attempt_no 升序（跟发生顺序一致）。目前主要给
     * 测试和将来的订单详情页/排障用，不做分页——一个订单的供应商数量有限，
     * 不会有分页需求。
     */
    public function listForOrder(int $orderId): Collection
    {
        return $this->newQuery()
            ->where('order_id', $orderId)
            ->orderBy('attempt_no')
            ->get();
    }

    /**
     * 调用供应商之前先占住这一次尝试：插入 `result = processing` 的行，靠
     * `(order_id, attempt_no)` 唯一索引保证同一个序号只有一个调用方能拿到。
     * 撞上唯一索引返回 null，说明别的请求（比如并发到达的重复回调）已经在为这笔订单
     * 做同一次切换，调用方必须放弃，不能再去调用供应商。
     */
    public function claim(int $orderId, int $supplierId, int $attemptNo): ?OrderAttempt
    {
        try {
            return $this->newQuery()->create([
                'order_id' => $orderId,
                'supplier_id' => $supplierId,
                'attempt_no' => $attemptNo,
                'result' => 'processing',
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'order_attempts_order_id_attempt_no_unique')) {
                return null;
            }
            throw $e;
        }
    }

    public function findLatestForOrder(int $orderId): ?OrderAttempt
    {
        return $this->newQuery()
            ->where('order_id', $orderId)
            ->orderByDesc('attempt_no')
            ->first();
    }

    public function findByOrderAndAttemptNo(int $orderId, int $attemptNo): ?OrderAttempt
    {
        return $this->newQuery()
            ->where('order_id', $orderId)
            ->where('attempt_no', $attemptNo)
            ->first();
    }
}
