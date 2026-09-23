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

use App\Dao\OrderDao;
use App\Exception\CallbackOrderNotFoundException;
use App\Model\Order;
use App\Model\Supplier;
use App\Service\AbstractService;
use App\Supplier\DriverResult;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\Di\Annotation\Inject;
use RuntimeException;

/**
 * 云洋（快递）订单回调（requirements.md 7.2，yunyang.md 第 3 节），由
 * App\Service\Order\SupplierCallbackService 按供应商驱动分派过来。
 *
 * **回调没有签名，payload 里的任何东西都不可信**：驱动的 parseCallback() 只取单号、
 * 再调带签名的订单详情拿权威数据，这里只用权威结果推进订单（交给
 * ExpressOrderSettlementService::apply()）。认领订单也只看权威结果：
 *
 * - 权威结果里的云洋单号 `shopbill` 已经记在某笔订单上 → 就是它；
 * - 否则（下单结果未知，平台还没拿到 shopbill）→ 权威结果里原样带回的 `extendField1`
 *   （下单时放的平台订单号）指向的那笔快递订单。**不能用 payload 里的 extendField1**：
 *   任何人都能伪造一个回调，把自己那单的 shopbill 配上别人的平台订单号，让别人的订单
 *   按他那单结算或解冻。
 *
 * 认不出来按找不到订单处理（404），云洋会重试；也不能回复成功把它认下来。
 * 查询本身失败（超时、500）同理不回复成功，抛异常让云洋过一会儿重推——结算之后的费用
 * 调整和签收只有回调这一条路径能知道（定时查询只查处理中的订单）。
 */
class ExpressCallbackService extends AbstractService
{
    /** yunyang.md 第 1 节：回调成功必须返回这个 JSON */
    public const SUCCESS_REPLY = '{"code":1,"message":"推送成功"}';

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected ExpressOrderSettlementService $settlementService;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(Supplier $supplier, array $payload): string
    {
        $result = $this->supplierDriverFactory->buildYunyang($supplier)->parseCallback($payload);
        if ($result === null) {
            throw new CallbackOrderNotFoundException('ExpressCallbackService: callback carries no shopbill / waybill.');
        }
        if ($result->result === UnifiedResult::Unknown && $result->expressFees === null) {
            throw new RuntimeException('ExpressCallbackService: order detail query failed: ' . $result->failReason);
        }

        $order = $this->claimOrder((int) $supplier->id, $result);
        if ($order === null) {
            throw new CallbackOrderNotFoundException('ExpressCallbackService: no express order matches this callback.');
        }

        $this->settlementService->apply($order, $result, (int) $supplier->id);

        return self::SUCCESS_REPLY;
    }

    private function claimOrder(int $supplierId, DriverResult $result): ?Order
    {
        if ($result->supplierOrderNo !== null) {
            $order = $this->orderDao->findBySupplierOrderNo($supplierId, $result->supplierOrderNo);
            if ($order !== null) {
                return $order->business_line === ExpressOrderSettlementService::BUSINESS_LINE ? $order : null;
            }
        }

        $platformOrderNo = $result->expressFees['platform_order_no'] ?? null;
        if (! is_string($platformOrderNo) || $platformOrderNo === '') {
            return null;
        }

        $order = $this->orderDao->findByOrderNo($platformOrderNo);
        if ($order === null
            || $order->business_line !== ExpressOrderSettlementService::BUSINESS_LINE
            // 已经绑了别的 shopbill：两边对不上，不认
            || ($order->supplier_order_no !== null && $order->supplier_order_no !== $result->supplierOrderNo)) {
            return null;
        }

        return $order;
    }
}
