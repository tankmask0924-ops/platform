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

use App\Model\OrderExpressFeeAdjustment;
use Hyperf\Database\Model\Collection;

class OrderExpressFeeAdjustmentDao extends AbstractDao
{
    protected string $model = OrderExpressFeeAdjustment::class;

    /**
     * 一笔订单的全部费用调整，按发生时间正序（商户和后台都要按时间线看"先补扣了什么、
     * 又退回了什么"）。
     *
     * @return Collection<int, OrderExpressFeeAdjustment>
     */
    public function listForOrder(int $orderId): Collection
    {
        return $this->newQuery()->where('order_id', $orderId)->orderBy('created_at')->orderBy('id')->get();
    }

    public function record(int $orderId, string $type, string $item, string $amount, ?string $reason = null): OrderExpressFeeAdjustment
    {
        return $this->create([
            'order_id' => $orderId,
            'type' => $type,
            'item' => $item,
            'amount' => $amount,
            'reason' => $reason,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
