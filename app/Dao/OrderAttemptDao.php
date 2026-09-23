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

    /**
     * 供应商统计（requirements.md 6.8「订单量、成功率、平均到账时长、成本总额……按天/商品查看」）。
     *
     * 统计口径以 `order_attempts` 为准，不是 `orders.supplier_id`：订单只记最后一次尝试的
     * 供应商，A 家失败切到 B 家成功的订单在 `orders` 上只看得到 B，A 的失败会凭空消失，
     * 成功率就会虚高——而成功率正是这份统计要用来调整优先级的核心指标。尝试表里每家各占
     * 一行，谁失败谁记在谁头上。
     *
     * - 订单量 = 分给这家的尝试次数；
     * - 成功率的分母只算已经有结果的（success + failed），处理中/结果未知不计入；
     * - 到账时长 = 这次尝试从占号（调用供应商前）到写下最终结果之间的秒数，只算成功的尝试。
     *   同步就成功的订单是 0 秒（秒级精度，不是真的没耗时）；
     * - 成本总额只累计成功的尝试，用订单上的成本快照（`orders.cost_price` 在成功时
     *   写的就是这家的成本，见 App\Service\Order\OrderResultApplier::applySuccess()）。
     *
     * 按商品分组时联 `order_recharges`（话费、卡券的商品在这张表上），电影票、快递
     * 没有本地商品也没有这张表的行，`product_id` 为 null 归成一组。
     *
     * @return list<array{group: null|string, product_id: null|int, count: int, success: int,
     *     failed: int, cost_total: string, avg_seconds: null|float}>
     */
    public function statsForSupplier(int $supplierId, string $from, string $to, bool $byProduct): array
    {
        $query = $this->newQuery()
            ->join('orders', 'orders.id', '=', 'order_attempts.order_id')
            ->where('order_attempts.supplier_id', $supplierId)
            ->where('order_attempts.created_at', '>=', $from)
            ->where('order_attempts.created_at', '<=', $to)
            ->selectRaw('count(*) as n')
            ->selectRaw("SUM(CASE WHEN order_attempts.result = 'success' THEN 1 ELSE 0 END) as success_n")
            ->selectRaw("SUM(CASE WHEN order_attempts.result = 'failed' THEN 1 ELSE 0 END) as failed_n")
            ->selectRaw("COALESCE(SUM(CASE WHEN order_attempts.result = 'success' THEN orders.cost_price ELSE 0 END), 0) as cost_total")
            ->selectRaw("AVG(CASE WHEN order_attempts.result = 'success' THEN TIMESTAMPDIFF(SECOND, order_attempts.created_at, order_attempts.updated_at) END) as avg_seconds");

        if ($byProduct) {
            $query->leftJoin('order_recharges', 'order_recharges.order_id', '=', 'order_attempts.order_id')
                ->leftJoin('products', 'products.id', '=', 'order_recharges.product_id')
                ->selectRaw('order_recharges.product_id as product_id, products.name as g')
                ->groupBy('order_recharges.product_id', 'products.name')
                ->orderByDesc('n');
        } else {
            $query->selectRaw('DATE(order_attempts.created_at) as g')
                ->selectRaw('NULL as product_id')
                ->groupBy('g')
                ->orderBy('g');
        }

        return $query->get()
            ->map(static fn ($row) => [
                'group' => $row->g === null ? null : (string) $row->g,
                'product_id' => $row->product_id === null ? null : (int) $row->product_id,
                'count' => (int) $row->n,
                'success' => (int) $row->success_n,
                'failed' => (int) $row->failed_n,
                'cost_total' => (string) $row->cost_total,
                'avg_seconds' => $row->avg_seconds === null ? null : (float) $row->avg_seconds,
            ])
            ->values()
            ->all();
    }

    /**
     * 熔断判定用（requirements.md 6.6）：`$since` 之后分给这家供应商的尝试里，已经有
     * 结果的有多少次、其中明确失败多少次。`$productId` 非 null 时只统计这个商品
     * （"供应商 + 商品"粒度）。
     *
     * **分母只算已经有结果的（success + failed）**，处理中/结果未知不计入：一笔还在
     * 等结果的尝试既不能算这家供应商失败，也不能算它成功，把它放进分母只会在下单高峰
     * （处理中的多）时把失败率稀释掉，正好是最需要熔断的时候失灵。这跟供应商统计
     * （statsForSupplier()）的口径是同一套。
     *
     * @return array{total: int, failed: int}
     */
    public function resultCountsForSupplier(int $supplierId, string $since, ?int $productId = null): array
    {
        $query = $this->newQuery()
            ->where('order_attempts.supplier_id', $supplierId)
            ->where('order_attempts.created_at', '>=', $since)
            ->whereIn('order_attempts.result', ['success', 'failed']);

        if ($productId !== null) {
            $query->join('order_recharges', 'order_recharges.order_id', '=', 'order_attempts.order_id')
                ->where('order_recharges.product_id', $productId);
        }

        $row = $query
            ->selectRaw('count(*) as n')
            ->selectRaw("SUM(CASE WHEN order_attempts.result = 'failed' THEN 1 ELSE 0 END) as failed_n")
            ->first();

        return ['total' => (int) $row->n, 'failed' => (int) $row->failed_n];
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

    /**
     * 需要定时查询的尝试：订单仍是 processing、这次尝试是该订单最新的一次、结果还是
     * 处理中/未知，且距上次更新（下单、回调或上一次查询）已经超过 `$updatedBefore`。
     * 最久没动的排前面。
     *
     * @return Collection<int, OrderAttempt>
     */
    public function listDueForQuery(string $updatedBefore, int $limit): Collection
    {
        return $this->newQuery()
            ->select('order_attempts.*')
            ->join('orders', 'orders.id', '=', 'order_attempts.order_id')
            ->where('orders.status', 'processing')
            // 快递按云洋单号查询，下单结果未知、还没拿到单号的订单查不了（只能等回调或转人工），
            // 不排除的话它们永远是"最久没查"的那批，会一直占着每轮的名额
            ->where(static function ($query) {
                $query->where('orders.business_line', '<>', 'express')
                    ->orWhereNotNull('orders.supplier_order_no');
            })
            ->whereIn('order_attempts.result', ['processing', 'unknown'])
            ->where('order_attempts.updated_at', '<=', $updatedBefore)
            ->whereNotExists(static function ($query) {
                $query->selectRaw('1')
                    ->from('order_attempts as later')
                    ->whereColumn('later.order_id', 'order_attempts.order_id')
                    ->whereColumn('later.attempt_no', '>', 'order_attempts.attempt_no');
            })
            ->orderBy('order_attempts.updated_at')
            ->limit($limit)
            ->get();
    }
}
