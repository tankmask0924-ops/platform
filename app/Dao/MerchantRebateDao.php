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

use App\Model\MerchantRebate;
use Hyperf\Database\Model\Collection;

class MerchantRebateDao extends AbstractDao
{
    protected string $model = MerchantRebate::class;

    /**
     * 按 order_id 查返佣记录（`merchant_rebates.order_id` 唯一），目前只给测试用，
     * 验证"一笔订单只生成一条返佣记录"/"零返佣订单不生成记录"。
     */
    public function findByOrderId(int $orderId): ?MerchantRebate
    {
        return $this->newQuery()->where('order_id', $orderId)->first();
    }

    /**
     * requirements.md 5.4"入账"：定时任务扫描到期的待到账记录——
     * `status = pending AND due_at <= now()`，给 `App\Crontab\RebateSettlementCrontab`
     * 用。`due_at` 为 `null` 的行（订单本身完成时间还没到，比如快递未签收，本次
     * 任务范围内的话费返佣不会出现这种情况，但字段设计上允许）不会被
     * `<=` 比较命中，天然被排除，不需要额外加 `whereNotNull`。
     */
    public function findDuePending(): Collection
    {
        return $this->newQuery()
            ->where('status', 'pending')
            ->where('due_at', '<=', date('Y-m-d H:i:s'))
            ->get();
    }

    /**
     * 汇总某个商户「待到账」（status = pending）的返佣金额。
     *
     * amount 是 decimal(10,2)，PDO MySQL 驱动对 DECIMAL 列（含 SUM 聚合后的结果，
     * 精度不变）返回的是原样字符串而不是 float，这里直接透传字符串，不转 float
     * 再格式化，避免浮点误差污染金额。没有匹配行时 Hyperf 的 sum() 会退化成 int 0，
     * 这里统一补成 '0.00' 保持返回值形状一致。
     */
    public function sumPendingAmount(int $merchantId): string
    {
        $sum = $this->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('status', 'pending')
            ->sum('amount');

        if ($sum === 0 || $sum === '0' || $sum === null) {
            return '0.00';
        }

        return (string) $sum;
    }
}
