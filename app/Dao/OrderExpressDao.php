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

use App\Model\OrderExpress;

class OrderExpressDao extends AbstractDao
{
    protected string $model = OrderExpress::class;

    public function findByOrderId(int $orderId): ?OrderExpress
    {
        return $this->newQuery()->where('order_id', $orderId)->first();
    }

    /**
     * 在事务里锁住一笔快递订单的明细行（SELECT ... FOR UPDATE）。冻结调整、结算、
     * 费用调整都要先拿这把锁再比较"供应商最新费用"和"已经处理过的费用"，
     * 回调和定时查询同时到达时排队处理，同一笔差额不会被补扣/退回两次。
     */
    public function lockForUpdate(int $orderId): ?OrderExpress
    {
        return $this->newQuery()->where('order_id', $orderId)->lockForUpdate()->first();
    }

    /**
     * 已扣费（订单成功）、扣费时间早于 `$feeOverBefore`、还没有完成时间的快递订单 id，
     * 给 ExpressOrderSettlementService::completeOverdue() 补兜底完成时间用。
     *
     * @return list<int>
     */
    public function listSettledUncompletedBefore(string $feeOverBefore, int $limit): array
    {
        return $this->newQuery()
            ->join('orders', 'orders.id', '=', 'order_expresses.order_id')
            ->where('orders.status', 'success')
            ->whereNull('orders.completed_at')
            ->whereNotNull('order_expresses.fee_over_at')
            ->where('order_expresses.fee_over_at', '<=', $feeOverBefore)
            ->orderBy('order_expresses.fee_over_at')
            ->limit($limit)
            ->pluck('order_expresses.order_id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }
}
