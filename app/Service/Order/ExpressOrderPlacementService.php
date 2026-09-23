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

use App\Dao\OrderAttemptDao;
use App\Dao\OrderExpressDao;
use App\Exception\OpenApiException;
use App\Model\Merchant;
use App\Model\Order;
use App\Model\OrderExpress;
use App\OpenApi\ErrorCode;
use App\Service\OpenApi\ExpressQuoteService;
use App\Service\Product\PricingRuleService;
use App\Supplier\DriverResult;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\Di\Annotation\Inject;
use Throwable;

/**
 * 快递下单编排（requirements.md 7.2、8.1，docs/modules.md 第 6 节「快递下单」）。
 *
 * 跟话费、卡券共用 AbstractOrderPlacementService 的幂等重放、欠款拦截、建单竞态、
 * 余额不足处理；不同的地方：
 *
 * 1. **没有本地商品**：售价是下单这一刻**重新查价**算出来的（7.2「下单前重新向供应商检测
 *    价格，不采用商户传入的金额」）——商户只传查价时拿到的平台渠道编号，
 *    ExpressQuoteService::resolveChannel() 用同样的参数重新查一遍、把编号换回供应商渠道。
 *    售价 = 运费成本按快递加价规则加价 + 保价费 + 耗材费（后两项按成本），冻结金额 = 售价，不上浮。
 * 2. **只有一家供应商、只下一次**：不走 SupplierRouter 的优先级切换。云洋没有防重复单号，
 *    超时绝不重试（yunyang.md 第 3 节），结果未知就保持处理中，等回调带回平台订单号认领，
 *    超过异常单时长转人工。
 * 3. **下单成功不是订单成功**：云洋受理（`feeOver=0`）后订单仍是处理中，按它返回的冻结运费
 *    调整冻结金额；扣费（`feeOver=1`）才是成功。这些都交给 ExpressOrderSettlementService，
 *    同步结果、回调、定时查询走同一个入口。
 * 4. **暂不返佣**（yunyang.md 第 5 节），不生成返佣记录。
 */
class ExpressOrderPlacementService extends AbstractOrderPlacementService
{
    private const ORDER_NO_PREFIX = 'E';

    #[Inject]
    protected ExpressQuoteService $quoteService;

    #[Inject]
    protected PricingRuleService $pricingRuleService;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected OrderExpressDao $orderExpressDao;

    #[Inject]
    protected OrderAttemptDao $orderAttemptDao;

    #[Inject]
    protected ExpressOrderSettlementService $settlementService;

    #[Inject]
    protected ExpressOrderPresenter $presenter;

    /**
     * @param array<string, mixed> $params 商户传入的寄收件、物品参数（校验见 ExpressQuoteService::validate()）
     * @return array<string, mixed>
     */
    public function place(Merchant $merchant, string $merchantOrderNo, string $channelCode, string $callbackUrl, array $params): array
    {
        // 顺序同话费下单：幂等重放在所有拦截之前，见 RechargeOrderPlacementService::place()
        $existing = $this->findIdempotentReplay($merchant, $merchantOrderNo);
        if ($existing !== null) {
            return $existing;
        }

        $this->assertBusinessSubscribed($merchant);
        $this->assertMerchantNotSuspended($merchant);

        $request = $this->quoteService->validate($params, true);
        ['supplier' => $supplier, 'channel' => $channel] = $this->quoteService->resolveChannel($request, $channelCode);
        $content = $this->quoteService->toSupplierContent($request);
        $appointmentTime = $this->validateAppointmentTime($params['appointment_time'] ?? null, $channel);
        if ($appointmentTime !== null) {
            // 字段名是推断（同 ExpressQuoteService::toSupplierContent() 的说明），联调对不上改这里
            $content['appointmentTime'] = $appointmentTime;
        }
        // 下单前就把驱动建好：建不起来直接报错，不留下已冻结却发不出去的订单
        $driver = $this->supplierDriverFactory->buildYunyang($supplier);

        $freightCost = $channel['freight'];
        $extrasCost = bcadd($this->money($channel['freight_insured'] ?? null), $this->money($channel['freight_haocai'] ?? null), 2);
        $salePrice = bcadd($this->pricingRuleService->salePriceFor(ExpressOrderSettlementService::BUSINESS_LINE, $freightCost), $extrasCost, 2);

        $order = $this->createOrderRow($merchant, $merchantOrderNo, $salePrice, $callbackUrl, bcadd($freightCost, $extrasCost, 2));
        if ($order === null) {
            return $this->resolveReplayAfterCreateRace($merchant, $merchantOrderNo);
        }

        if (! $this->balanceService->freeze($merchant->id, $order->id, $order->sale_price)) {
            $this->handleFreezeFailure($order);
            return $this->toResponseArray($order);
        }

        $this->orderExpressDao->create([
            'order_id' => $order->id,
            'express_company_code' => $channelCode,
            'express_company_name' => (string) ($channel['channel_name'] ?? ''),
            'sender_info' => $request['sender'],
            'receiver_info' => $request['receiver'],
            'item_info' => ['name' => $request['item_name'], 'volume' => $request['volume']],
            'weight' => $request['weight'],
            'insured_amount' => $request['insured_amount'],
            'estimated_freight' => $freightCost,
            'logistics_status' => OrderExpress::STATUS_PENDING_PICKUP,
        ]);

        // 先记下供应商再调用：结果未知的订单之后靠它查询、靠它核对回调
        $order->fill(['supplier_id' => $supplier->id])->save();
        $attempt = $this->orderAttemptDao->claim((int) $order->id, (int) $supplier->id, 1);

        try {
            $result = $driver->placeOrder($order->order_no, (string) $channel['channel_id'], $content);
        } catch (Throwable $e) {
            // 驱动把传输层异常都归成了"结果未知"，这里兜的是意料之外的异常：请求可能已经发出去了，
            // 同样只能按结果未知处理，绝不能当失败解冻
            $result = new DriverResult(result: UnifiedResult::Unknown, failReason: 'express placeOrder threw: ' . $e->getMessage());
        }

        $attempt?->fill([
            'request_snapshot' => $result->rawRequest,
            'response_snapshot' => $result->rawResponse,
        ])->save();

        $this->settlementService->apply($order, $result, (int) $supplier->id, true);
        $order->refresh();

        return $this->toResponseArray($order);
    }

    /**
     * 基础订单字段 + 快递明细（运单号、物流状态、费用明细），幂等重放也返回同一个形状。
     *
     * @return array<string, mixed>
     */
    protected function toResponseArray(Order $order): array
    {
        return parent::toResponseArray($order) + ['express' => $this->presenter->present((int) $order->id)];
    }

    protected function businessLine(): string
    {
        return ExpressOrderSettlementService::BUSINESS_LINE;
    }

    protected function orderNoPrefix(): string
    {
        return self::ORDER_NO_PREFIX;
    }

    /**
     * 预约取件时间段（yunyang.md 第 4 节：菜鸟渠道必须传）。可选；传了就必须是查价返回的
     * `appointment_times` 之一，免得商户随手填一个快递公司不认的时间段。哪些渠道必传由云洋判断，
     * 没传被拒就是同步下单明确失败、全额解冻。
     *
     * @param array<string, mixed> $channel
     */
    private function validateAppointmentTime(mixed $value, array $channel): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $offered = $channel['appointment_times'] ?? [];
        if (! is_string($value) || ($offered !== [] && ! in_array($value, $offered, true))) {
            throw new OpenApiException(ErrorCode::InvalidParams, 'appointment_time 必须是查价返回的 appointment_times 之一');
        }

        return $value;
    }

    private function money(mixed $value): string
    {
        return is_string($value) && is_numeric($value) ? bcadd($value, '0', 2) : '0.00';
    }
}
