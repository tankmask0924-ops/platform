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

use App\Model\SupplierCircuitBreaker;
use Hyperf\Database\Model\Collection;

class SupplierCircuitBreakerDao extends AbstractDao
{
    protected string $model = SupplierCircuitBreaker::class;

    /**
     * 某个供应商当前处于熔断中的行（整个供应商的那一行 + 各商品的行）。
     *
     * 过滤"是否真的还在熔断中"交给 `SupplierCircuitBreaker::isPausedNow()`，这里只按
     * `status` 取行：到期但定时任务还没写回 `normal` 的行也要取出来，由模型判断——
     * 反过来如果在 SQL 里就用 `paused_until > now()` 过滤，读到的就是"数据库此刻认为"
     * 的状态，跟模型里那套判断分成两份实现，早晚会漂。
     *
     * @return Collection<int, SupplierCircuitBreaker>
     */
    public function listPausedForSupplier(int $supplierId): Collection
    {
        return $this->newQuery()
            ->where('supplier_id', $supplierId)
            ->where('status', SupplierCircuitBreaker::STATUS_PAUSED)
            ->get();
    }

    /**
     * 某个供应商的全部熔断行（含已恢复的），后台「熔断状态」列表用。
     * 熔断中的排前面，其次按最近更新。
     *
     * @return Collection<int, SupplierCircuitBreaker>
     */
    public function listForSupplier(int $supplierId): Collection
    {
        return $this->newQuery()
            ->where('supplier_id', $supplierId)
            ->orderByRaw("status = 'paused' desc")
            ->orderByDesc('updated_at')
            ->get();
    }

    public function find(int $id): ?SupplierCircuitBreaker
    {
        return SupplierCircuitBreaker::find($id);
    }

    public function findScope(int $supplierId, int $productId): ?SupplierCircuitBreaker
    {
        return $this->newQuery()
            ->where('supplier_id', $supplierId)
            ->where('product_id', $productId)
            ->first();
    }

    /**
     * 写入/更新一个作用域的熔断状态，按 `(supplier_id, product_id)` 唯一索引原地更新
     * （跟 `MerchantLevelBusinessRateDao::upsertRate()` 同一套数据库原生 upsert）。
     * 并发的两次熔断判定不会插出两行，后到的覆盖先到的暂停时间。
     */
    public function upsertStatus(
        int $supplierId,
        int $productId,
        string $status,
        ?string $pausedUntil,
        ?string $reason
    ): SupplierCircuitBreaker {
        $now = date('Y-m-d H:i:s');

        $this->newQuery()->upsert(
            [[
                'supplier_id' => $supplierId,
                'product_id' => $productId,
                'status' => $status,
                'paused_until' => $pausedUntil,
                'triggered_reason' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['supplier_id', 'product_id'],
            ['status', 'paused_until', 'triggered_reason', 'updated_at']
        );

        return $this->findScope($supplierId, $productId);
    }

    /**
     * 把已经过期的熔断行写回 `normal`（定时任务收尾用，见
     * App\Crontab\CircuitBreakerRecoveryCrontab）。`paused_until IS NULL` 的手动
     * 无限期暂停不在范围内。
     *
     * @return Collection<int, SupplierCircuitBreaker> 这次恢复的行（恢复前的快照，供日志用）
     */
    public function listExpired(string $now): Collection
    {
        return $this->newQuery()
            ->where('status', SupplierCircuitBreaker::STATUS_PAUSED)
            ->whereNotNull('paused_until')
            ->where('paused_until', '<=', $now)
            ->get();
    }

    /**
     * 条件更新成 `normal`：只有这一行还是"已过期的 paused"时才生效，跟同时到达的
     * 手动暂停竞争时不会把刚设好的新暂停冲掉。
     */
    public function resumeIfExpired(int $id, string $now): bool
    {
        return $this->newQuery()
            ->where('id', $id)
            ->where('status', SupplierCircuitBreaker::STATUS_PAUSED)
            ->whereNotNull('paused_until')
            ->where('paused_until', '<=', $now)
            ->update([
                'status' => SupplierCircuitBreaker::STATUS_NORMAL,
                'updated_at' => date('Y-m-d H:i:s'),
            ]) === 1;
    }
}
