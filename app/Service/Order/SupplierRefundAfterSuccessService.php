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

namespace App\Service\Order;

use App\Model\Alert;
use App\Model\Order;
use App\Service\AbstractService;
use App\Service\Alert\AlertService;
use App\Supplier\DriverResult;
use App\Supplier\UnifiedResult;
use Hyperf\Di\Annotation\Inject;

/**
 * 话费、卡券"成功之后又被供应商退款"（requirements.md 7.1、7.7，kasushou.md 第 2 节"3 之后变为 5"）。
 *
 * - **全额退款**（卡速售状态 4/5 且退款金额等于订单金额，驱动映射成明确失败）：自动按"售后核实未到账"处理——
 *   订单改已退款、扣款退回商户、返佣作废或扣回（OrderRefundService::refundUndelivered()），并告警。
 * - **部分退款**（状态 4/5 但退款金额小于订单金额，驱动映射成结果未知）：不动钱，告警转人工，客服在系统后台
 *   订单详情用「部分退款」按核实的金额退给商户。
 * - 其它结果（仍是成功、查询失败、查不到这笔单）：什么都不做。查不到不等于退款，不能据此退钱。
 *
 * 信息来源是**成功订单的供应商回调**（卡速售状态变化会再推回调，App\Service\Order\SupplierCallbackService
 * 对已成功订单交给这里）和后台「查询供应商」。定时查询只查处理中的订单，不会主动发现这类变化。
 * 两个告警都挂在这笔订单上（`related_type = order`），同一笔订单未处理的告警只累加不重复插。
 */
class SupplierRefundAfterSuccessService extends AbstractService
{
    #[Inject]
    protected OrderRefundService $orderRefundService;

    #[Inject]
    protected AlertService $alertService;

    /**
     * @return string 处理结果：`refunded` 已自动全额退款 / `partial` 部分退款已告警 / `none` 没有退款
     */
    public function handle(Order $order, DriverResult $result): string
    {
        if ($order->status !== Order::STATUS_SUCCESS || ! in_array($order->business_line, ['recharge', 'card'], true)) {
            return 'none';
        }
        $refund = $result->refundAmount;
        if ($refund === null || ! is_numeric($refund) || bccomp($refund, '0', 2) <= 0) {
            return 'none';
        }

        if ($result->result === UnifiedResult::DefiniteFailure) {
            $refunded = $this->orderRefundService->refundUndelivered($order, '供应商全额退款（订单成功后）');
            $this->alertService->raise(
                Alert::TYPE_SUPPLIER_REFUND_AFTER_SUCCESS,
                Alert::LEVEL_WARNING,
                sprintf('订单 %s 成功后供应商全额退款 %s 元，%s', $order->order_no, $refund, $refunded ? '已自动退回商户并处理返佣' : '订单状态已变化，未自动处理'),
                'order',
                (int) $order->id
            );

            return $refunded ? 'refunded' : 'none';
        }

        if ($result->result === UnifiedResult::Unknown) {
            $this->alertService->raise(
                Alert::TYPE_SUPPLIER_REFUND_AFTER_SUCCESS,
                Alert::LEVEL_WARNING,
                sprintf('订单 %s 成功后供应商部分退款 %s 元，需人工核实后在订单详情执行部分退款', $order->order_no, $refund),
                'order',
                (int) $order->id
            );

            return 'partial';
        }

        return 'none';
    }
}
