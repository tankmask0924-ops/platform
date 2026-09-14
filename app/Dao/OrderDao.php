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
}
