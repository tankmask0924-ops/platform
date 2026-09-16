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

use App\Model\MerchantBalanceLog;
use Hyperf\Database\Model\Collection;

class MerchantBalanceLogDao extends AbstractDao
{
    protected string $model = MerchantBalanceLog::class;

    /**
     * 按订单查资金流水，按时间正序（一笔订单最多两条：freeze + deduct 或
     * freeze + unfreeze，正序方便按发生顺序核对）。目前主要给测试和将来的
     * 订单详情页用，这里先留一个基础查询，不做分页。
     */
    public function findByOrderId(int $orderId): Collection
    {
        return $this->newQuery()
            ->where('order_id', $orderId)
            ->orderBy('created_at')
            ->get();
    }
}
