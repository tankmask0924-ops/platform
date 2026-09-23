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

namespace App\Service\OpenApi;

use App\Dao\OrderDao;
use App\Dao\OrderExpressDao;
use App\Dao\SupplierDao;
use App\Exception\OpenApiException;
use App\Model\Merchant;
use App\Model\Order;
use App\Model\OrderExpress;
use App\OpenApi\ErrorCode;
use App\Service\AbstractService;
use App\Service\Order\ExpressOrderPresenter;
use App\Service\Order\ExpressOrderSettlementService;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\Yunyang\YunyangDriver;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 开放 API「快递取消」「快递轨迹查询」（requirements.md 7.2、8.1）。
 *
 * **取消以云洋的查询结果为准**：云洋说"取消成功"之后，仍然再调一次带签名的订单详情，
 * 交给 ExpressOrderSettlementService 按"已取消且未扣费"全额解冻——解冻只有这一条路，
 * 跟云洋主动取消后回调过来走的是同一段代码。云洋取消接口成功但查询还没反映出来时，
 * 订单先保持处理中，等回调/定时查询。
 *
 * 只有待揽收能取消，部分快递公司不支持接口取消（yunyang.md 第 1 节）：这两种云洋都返回
 * 失败 + 原因文案，文案可能带供应商信息（8.1「对商户不暴露供应商信息」），只记日志，
 * 对商户统一返回 OrderNotCancellable。
 */
class ExpressOrderService extends AbstractService
{
    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderExpressDao $orderExpressDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected ExpressOrderSettlementService $settlementService;

    #[Inject]
    protected ExpressOrderPresenter $presenter;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * @return array<string, mixed> 同订单查询的形状（基础字段 + express 明细）
     */
    public function cancel(Merchant $merchant, ?string $orderNo, ?string $merchantOrderNo): array
    {
        $order = $this->findOrder($merchant, $orderNo, $merchantOrderNo);
        $express = $this->orderExpressDao->findByOrderId((int) $order->id);

        if (! in_array($order->status, [Order::STATUS_PROCESSING, Order::STATUS_ABNORMAL], true)
            || $express === null
            || $express->logistics_status !== OrderExpress::STATUS_PENDING_PICKUP
            || $order->supplier_order_no === null) {
            // 没有云洋单号的（下单结果未知）也取消不了：不知道该取消哪一单
            throw new OpenApiException(ErrorCode::OrderNotCancellable);
        }

        $cancelled = $this->driverFor($order)->cancelOrder($order->supplier_order_no);
        if (! $cancelled['cancelled']) {
            $this->loggerFactory->get('express')->info('express cancel rejected by supplier', [
                'order_id' => $order->id,
                'supplier_message' => $cancelled['message'],
            ]);
            throw new OpenApiException(ErrorCode::OrderNotCancellable);
        }

        try {
            $this->settlementService->refreshFromSupplier($order);
        } catch (Throwable $e) {
            // 取消已经在云洋生效，确认查询失败不影响结果，回调/定时查询会补上
            $this->loggerFactory->get('express')->warning('express cancel confirm query failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
        $order->refresh();

        return $this->present($order);
    }

    /**
     * @return array{order_no: string, waybill_no: null|string, logistics_status: null|string,
     *     traces: list<array{time: null|string, description: null|string}>}
     */
    public function trace(Merchant $merchant, ?string $orderNo, ?string $merchantOrderNo): array
    {
        $order = $this->findOrder($merchant, $orderNo, $merchantOrderNo);
        $express = $this->orderExpressDao->findByOrderId((int) $order->id);

        // 轨迹只是展示，还没有云洋单号时返回空列表，不报错
        $traces = $order->supplier_order_no === null ? [] : $this->driverFor($order)->queryTrace($order->supplier_order_no);

        return [
            'order_no' => $order->order_no,
            'waybill_no' => $express?->waybill_no,
            'logistics_status' => $express?->logistics_status,
            'traces' => $traces,
        ];
    }

    private function findOrder(Merchant $merchant, ?string $orderNo, ?string $merchantOrderNo): Order
    {
        $order = null;
        if ($orderNo !== null && $orderNo !== '') {
            $order = $this->orderDao->findByOrderNoForMerchant((int) $merchant->id, $orderNo);
        } elseif ($merchantOrderNo !== null && $merchantOrderNo !== '') {
            $order = $this->orderDao->findByMerchantOrderNoForMerchant((int) $merchant->id, $merchantOrderNo);
        }

        // 别的业务线的订单也按"不存在"处理，不区分细节（同订单查询）
        if ($order === null || $order->business_line !== ExpressOrderSettlementService::BUSINESS_LINE) {
            throw new OpenApiException(ErrorCode::OrderNotFound);
        }

        return $order;
    }

    private function driverFor(Order $order): YunyangDriver
    {
        $supplier = $order->supplier_id === null ? null : $this->supplierDao->find((int) $order->supplier_id);
        if ($supplier === null) {
            throw new OpenApiException(ErrorCode::ExpressChannelUnavailable);
        }

        return $this->supplierDriverFactory->buildYunyang($supplier);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Order $order): array
    {
        return [
            'order_no' => $order->order_no,
            'merchant_order_no' => $order->merchant_order_no,
            'business_line' => $order->business_line,
            'status' => $order->merchantFacingStatus(),
            'sale_price' => $order->sale_price,
            'frozen_amount' => $order->frozen_amount,
            'deducted_amount' => $order->deducted_amount,
            'refunded_amount' => $order->refunded_amount,
            'completed_at' => $order->completed_at?->toDateTimeString(),
        ] + ErrorCode::presentOrderFailure($order->fail_reason) + [
            'express' => $this->presenter->present((int) $order->id),
        ];
    }
}
