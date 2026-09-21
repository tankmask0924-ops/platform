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

use App\Model\Order;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Collection;

class OrderDao extends AbstractDao
{
    protected string $model = Order::class;

    /**
     * 按平台订单号查订单，且必须限定 merchant_id（requirements.md 8.1 订单查询）。
     *
     * 安全关键：开放 API 允许商户传任意 order_no，如果先按 order_no 全局查出订单
     * 再事后比对 merchant_id，等于把「查到了但不是你的」这个中间状态暴露给了调用方
     * （容易在重构时被误删掉后面的比对，变成越权读取其他商户的订单和卡密）。
     * 必须把 merchant_id 一起写进 where 条件里，查不到就是查不到，不做「先查后核对」。
     */
    public function findByOrderNoForMerchant(int $merchantId, string $orderNo): ?Order
    {
        return $this->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('order_no', $orderNo)
            ->first();
    }

    /**
     * 按商户自己的订单号查订单，同样必须限定 merchant_id，理由同上。
     * merchant_order_no 只在同一个 merchant_id 下唯一，不加这个条件会查到别的商户的订单。
     */
    public function findByMerchantOrderNoForMerchant(int $merchantId, string $merchantOrderNo): ?Order
    {
        return $this->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('merchant_order_no', $merchantOrderNo)
            ->first();
    }

    /**
     * 按平台订单号全局查询，不限定 merchant_id——跟上面两个方法刻意不同：
     * `order_no` 本身在 `orders` 表上是唯一列（`orders_order_no_unique`，见
     * migrations/2026_09_14_091800_create_orders_table.php），不像
     * `merchant_order_no` 那样只在单个商户下唯一，所以全局查询不存在"越权读到
     * 别的商户订单"的问题。这个方法只给平台内部、不透传给商户的调用方使用——
     * 目前唯一调用方是 `App\Service\Order\SupplierCallbackService`：供应商回调
     * 携带的是平台自己生成的 `external_orderno`（截出 `order_no` 部分），
     * 回调到达时平台还不知道这笔订单属于哪个商户，没有 merchant_id 可供限定。
     * 绝不能把这个方法暴露给开放 API 那一侧（商户查询订单必须用上面两个
     * 限定 merchant_id 的方法）。
     */
    public function findByOrderNo(string $orderNo): ?Order
    {
        return $this->newQuery()
            ->where('order_no', $orderNo)
            ->first();
    }

    /**
     * 把一笔 `processing` 订单推进到终态，见 finishIfStatus()。
     *
     * @param array<string, mixed> $attributes
     */
    public function finishIfProcessing(Order $order, array $attributes): bool
    {
        return $this->finishIfStatus($order, $attributes, Order::STATUS_PROCESSING);
    }

    /**
     * 带 `status = $expectedStatus` 条件更新订单：同一笔订单被回调、定时查询、人工处理
     * 同时推进时只有一方返回 true。返回 false 说明订单已经不是调用方读到的状态，调用方
     * 不能再做扣款/解冻/通知。成功时 `$order` 的内存属性同步成更新后的值。
     *
     * @param array<string, mixed> $attributes
     */
    public function finishIfStatus(Order $order, array $attributes, string $expectedStatus): bool
    {
        $attributes['updated_at'] = date('Y-m-d H:i:s');

        $affected = $this->newQuery()
            ->where('id', $order->id)
            ->where('status', $expectedStatus)
            ->update($attributes);

        if ($affected !== 1) {
            return false;
        }

        $order->forceFill($attributes)->syncOriginal();

        return true;
    }

    /**
     * 把下单时间早于 `$createdBefore` 仍在处理中的订单标成异常单，每次最多 `$limit` 笔。
     * 带 `status = processing` 条件更新，跟同时到达的回调/定时查询竞争时只有一方生效。
     *
     * @return list<int> 实际被标记的订单 id
     */
    public function markAbnormalCreatedBefore(string $createdBefore, int $limit): array
    {
        $ids = $this->newQuery()
            ->where('status', Order::STATUS_PROCESSING)
            ->where('created_at', '<=', $createdBefore)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $marked = [];
        foreach ($ids as $id) {
            $affected = $this->newQuery()
                ->where('id', $id)
                ->where('status', Order::STATUS_PROCESSING)
                ->update(['status' => Order::STATUS_ABNORMAL, 'updated_at' => date('Y-m-d H:i:s')]);
            if ($affected === 1) {
                $marked[] = $id;
            }
        }

        return $marked;
    }

    /**
     * 订单列表（系统管理后台、商户后台共用），按 id 倒序（最新的在前）。商户后台调用时
     * 必须带上 merchant_id。
     *
     * @param array{status?: list<string>|string, business_line?: string, merchant_id?: int, order_no?: string,
     *     merchant_order_no?: string, created_from?: string, created_to?: string} $filters 已校验过的筛选条件
     * @return Collection<int, Order>
     */
    public function paginateFiltered(array $filters, int $page, int $perPage): Collection
    {
        return $this->filterQuery($filters)
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters 同 paginateFiltered()
     */
    public function countFiltered(array $filters): int
    {
        return $this->filterQuery($filters)->count();
    }

    /**
     * 某商户从 $from 起下的订单，按下单日期和状态汇总（商户后台首页统计）。
     * 金额是 DECIMAL 求和后的字符串；没有扣款的订单 deducted_amount 为 NULL，按 0 算。
     *
     * @return list<array{day: string, status: string, count: int, deducted: string, refunded: string}>
     */
    public function dailySummaryForMerchant(int $merchantId, string $from): array
    {
        return $this->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, status, count(*) as n, COALESCE(SUM(deducted_amount), 0) as deducted, SUM(refunded_amount) as refunded')
            ->groupBy('day', 'status')
            ->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'status' => (string) $row->status,
                'count' => (int) $row->n,
                'deducted' => (string) $row->deducted,
                'refunded' => (string) $row->refunded,
            ])
            ->values()
            ->all();
    }

    /**
     * 对账用：某个自然日内进入终态、且真的下给过供应商的订单，按 id 升序分页取
     * （App\Service\Reconciliation\ReconciliationService）。
     *
     * 按 `finished_at` 而不是 `created_at` 取：对账比的是"这笔订单最后算成什么样"，
     * 跨日完成的订单应该落在完成那天的批次里，否则昨天创建、今天才出结果的订单会在
     * 昨天的批次里被对成"平台处理中 vs 供应商成功"的假差异。
     *
     * 处理中、异常单不参与对账：前者还没有结论可对，后者本来就在等人工处理
     * （requirements.md 7.4），对账再报一遍只是把同一件事说两次。
     *
     * @param list<string> $statuses 参与对账的终态
     * @return Collection<int, Order>
     */
    public function listFinishedBetween(array $statuses, string $from, string $to, int $afterId, int $limit): Collection
    {
        return $this->newQuery()
            ->whereIn('status', $statuses)
            ->whereNotNull('supplier_id')
            ->whereBetween('finished_at', [$from, $to])
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function filterQuery(array $filters): Builder
    {
        $query = $this->newQuery();

        foreach (['status', 'business_line', 'merchant_id', 'order_no', 'merchant_order_no'] as $column) {
            if (! isset($filters[$column])) {
                continue;
            }
            is_array($filters[$column])
                ? $query->whereIn($column, $filters[$column])
                : $query->where($column, $filters[$column]);
        }
        if (isset($filters['created_from'])) {
            $query->where('created_at', '>=', $filters['created_from']);
        }
        if (isset($filters['created_to'])) {
            $query->where('created_at', '<=', $filters['created_to']);
        }

        return $query;
    }
}
