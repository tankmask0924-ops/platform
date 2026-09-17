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
     * 把一笔 `processing` 订单推进到终态：带 `status = processing` 条件更新，
     * 同一笔订单被回调和定时查询同时推进时只有一方返回 true。返回 false 说明别的
     * 请求已经先落了终态，调用方不能再做扣款/解冻/通知。成功时 `$order` 的内存
     * 属性同步成更新后的值。
     *
     * @param array<string, mixed> $attributes
     */
    public function finishIfProcessing(Order $order, array $attributes): bool
    {
        $attributes['updated_at'] = date('Y-m-d H:i:s');

        $affected = $this->newQuery()
            ->where('id', $order->id)
            ->where('status', 'processing')
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
}
