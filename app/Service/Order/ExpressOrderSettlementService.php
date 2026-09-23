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
use App\Dao\OrderDao;
use App\Dao\OrderExpressDao;
use App\Dao\OrderExpressFeeAdjustmentDao;
use App\Dao\SupplierDao;
use App\Dao\SystemSettingDao;
use App\Model\Order;
use App\Model\OrderExpress;
use App\Model\OrderExpressFeeAdjustment;
use App\OpenApi\ErrorCode;
use App\Service\AbstractService;
use App\Service\Merchant\BalanceService;
use App\Service\MerchantNotifyService;
use App\Service\Product\PricingRuleService;
use App\Supplier\DriverResult;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use App\Supplier\Yunyang\YunyangStatusMapper;
use Carbon\Carbon;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * 快递订单的资金推进（requirements.md 7.2「快递下单与补差价」，yunyang.md 第 2 节）。
 * 同步下单结果、云洋回调（App\Service\Order\ExpressCallbackService）、定时查询
 * （App\Service\Order\SupplierResultPollingService）三条路径拿到的供应商权威结果，
 * 都交给 apply()，不各写一套——话费那边 OrderResultApplier 的定位，但快递的规则完全不同。
 *
 * 1. **冻结调整**：下单后第一次拿到云洋的冻结运费（`feeOver=0` 的 `freight`），按它重算
 *    预估售价，多退少补冻结金额（BalanceService::adjustFreeze()，可用余额不够就能冻多少冻多少，
 *    差额留到结算补扣）。只调一次：以 `order_expresses.frozen_freight` 是否已写为准。
 * 2. **结算点是 `feeOver=1`**，不是签收：按实际运费（加价）+ 保价费/耗材费/逆向费（成本）
 *    算出应收，冻结的钱先扣，多了解冻、少了从可用余额补扣。订单此时变成 `success`，
 *    `sale_price`/`deducted_amount` 改成实际应收，`cost_price` 改成实际成本——财务报表的
 *    毛利直接用这两列，不改就会把预估当实际。
 * 3. **结算之后费用还会变**（签收环节的耗材费、保价费，拒收的逆向费，重量核实退回的运费）：
 *    逐项跟上次处理过的实际费用比，差额记一条 `order_express_fee_adjustments` 并补扣/退回，
 *    回调商户。运费的差额按加价后的售价算（`freight_sale_price` 存着当时实际收的运费），
 *    其余三项按成本。
 * 4. **完成时间**：签收时间；拒收退回、一直不签收的，由 completeOverdue() 在扣费后超过兜底
 *    天数时补上（7.5）。快递暂无返佣（yunyang.md 第 5 节），完成时间只用于报表和对账。
 * 5. **取消**：只有"已取消且未扣费"（驱动映射成明确失败，且带着 `type_code=99`）才全额解冻、
 *    订单变 `cancelled`。查询接口说"查无此单"也是明确失败，但下单明明成功了，这种结果
 *    不采信，只记日志——宁可让订单走异常单转人工，也不能凭一次查询失败把钱退掉。
 *    同步下单时的明确失败是另一回事：云洋没受理，订单 `failed`、全额解冻。
 *
 * **并发**：回调和定时查询可能同时推进同一笔订单。所有"比较供应商最新数据和已处理数据、
 * 再动钱"的步骤都在同一个事务里先锁 `order_expresses` 行（OrderExpressDao::lockForUpdate()），
 * 订单状态的跳转再加一道带状态条件的更新（OrderDao::finishIfStatus()），同一笔差额不会处理两次。
 * BalanceService 的方法自己也开事务，嵌在这里是保存点，任何一步抛错整体回滚，下次回调/查询重来。
 *
 * **异常单也照常推进**：快递待揽收可能超过异常单时长（默认 24 小时）被标成异常单，之后
 * 云洋带签名的查询说已扣费，就是确定的结果，照常结算；不像话费异常单那样只能人工处理。
 */
class ExpressOrderSettlementService extends AbstractService
{
    public const BUSINESS_LINE = 'express';

    /** requirements.md 7.2「扣费后超过兜底天数（后台可配，默认 15 天）自动完成」 */
    public const COMPLETE_FALLBACK_DAYS_SETTING_KEY = 'express_complete_fallback_days';

    public const DEFAULT_COMPLETE_FALLBACK_DAYS = 15;

    private const SCALE = 2;

    private const ADVANCEABLE_STATUSES = [Order::STATUS_PROCESSING, Order::STATUS_ABNORMAL];

    private const COMPLETE_BATCH_SIZE = 500;

    /** 云洋 typeCode → order_expresses.logistics_status */
    private const LOGISTICS_STATUS = [
        YunyangStatusMapper::TYPE_PENDING_PICKUP => OrderExpress::STATUS_PENDING_PICKUP,
        YunyangStatusMapper::TYPE_IN_TRANSIT => OrderExpress::STATUS_IN_TRANSIT,
        YunyangStatusMapper::TYPE_SIGNED => OrderExpress::STATUS_SIGNED,
        YunyangStatusMapper::TYPE_REJECTED => OrderExpress::STATUS_REJECTED,
        YunyangStatusMapper::TYPE_CANCELLED => OrderExpress::STATUS_CANCELLED,
    ];

    /** 实际费用四项：DriverResult::$expressFees 的键 => order_expresses 的列 */
    private const FEE_ITEMS = [
        OrderExpressFeeAdjustment::ITEM_FREIGHT => ['freight', 'actual_freight'],
        OrderExpressFeeAdjustment::ITEM_INSURED => ['freight_insured', 'actual_insured_fee'],
        OrderExpressFeeAdjustment::ITEM_MATERIAL => ['freight_haocai', 'actual_material_fee'],
        OrderExpressFeeAdjustment::ITEM_REVERSE => ['change_bill_freight', 'actual_reverse_fee'],
    ];

    private const ITEM_NAMES = [
        OrderExpressFeeAdjustment::ITEM_FREIGHT => '运费',
        OrderExpressFeeAdjustment::ITEM_INSURED => '保价费',
        OrderExpressFeeAdjustment::ITEM_MATERIAL => '耗材费',
        OrderExpressFeeAdjustment::ITEM_REVERSE => '逆向费',
    ];

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderExpressDao $orderExpressDao;

    #[Inject]
    protected OrderExpressFeeAdjustmentDao $feeAdjustmentDao;

    #[Inject]
    protected OrderAttemptDao $orderAttemptDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected BalanceService $balanceService;

    #[Inject]
    protected PricingRuleService $pricingRuleService;

    #[Inject]
    protected MerchantNotifyService $merchantNotifyService;

    #[Inject]
    protected SystemSettingDao $systemSettingDao;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * @param bool $fromPlacement 是否同步下单的结果，只影响"明确失败"怎么解读，见类注释第 5 条
     */
    public function apply(Order $order, DriverResult $result, int $supplierId, bool $fromPlacement = false): void
    {
        $this->recordAttemptResult($order, $result);

        $fees = $result->expressFees;
        if ($result->result === UnifiedResult::DefiniteFailure) {
            if ($fromPlacement) {
                $this->finishUnsettled($order, Order::STATUS_FAILED, $supplierId, ErrorCode::OrderFailed->message());
            } elseif (($fees['type_code'] ?? null) === YunyangStatusMapper::TYPE_CANCELLED) {
                $this->finishUnsettled($order, Order::STATUS_CANCELLED, $supplierId, null);
            } else {
                $this->logger()->warning('express supplier says order not found, ignored', [
                    'order_id' => $order->id,
                    'supplier_id' => $supplierId,
                    'supplier_fail_reason' => $result->failReason,
                ]);
            }

            return;
        }

        if ($fees === null) {
            // 查询本身失败（超时、500），没有任何新信息
            return;
        }

        $notify = Db::transaction(fn () => $this->advance($order, $result, $fees, $supplierId));

        if ($result->result === UnifiedResult::Unknown) {
            // 典型是"已扣费又报取消"：两边说法对不上，不动钱，留给异常单转人工
            $this->logger()->warning('express order result unknown', [
                'order_id' => $order->id,
                'fee_over' => $fees['fee_over'] ?? null,
                'type_code' => $fees['type_code'] ?? null,
            ]);
        }

        if ($notify) {
            $this->merchantNotifyService->notify((int) $order->id);
        }
    }

    /**
     * 立即向云洋查询这笔订单并推进（定时查询、后台手动查询、取消后确认共用）。
     * 还没拿到云洋单号的订单（下单结果未知）没法查，返回 null。
     */
    public function refreshFromSupplier(Order $order): ?DriverResult
    {
        if ($order->supplier_order_no === null || $order->supplier_order_no === '' || $order->supplier_id === null) {
            return null;
        }

        $supplier = $this->supplierDao->find((int) $order->supplier_id);
        if ($supplier === null) {
            throw new RuntimeException('supplier #' . $order->supplier_id . ' of express order #' . $order->id . ' not found');
        }

        $result = $this->supplierDriverFactory->buildYunyang($supplier)->queryOrder($order->supplier_order_no);
        $order->refresh();
        $this->apply($order, $result, (int) $supplier->id);

        return $result;
    }

    /**
     * 扣费后超过兜底天数仍未签收（拒收退回、丢件、云洋不推签收）的订单补上完成时间
     * （requirements.md 7.2、7.5）。
     *
     * @return int 本次补上完成时间的订单数
     */
    public function completeOverdue(): int
    {
        $days = (int) $this->systemSettingDao->getValue(self::COMPLETE_FALLBACK_DAYS_SETTING_KEY, self::DEFAULT_COMPLETE_FALLBACK_DAYS);
        if ($days <= 0) {
            $days = self::DEFAULT_COMPLETE_FALLBACK_DAYS;
        }

        $orderIds = $this->orderExpressDao->listSettledUncompletedBefore(
            Carbon::now()->subDays($days)->toDateTimeString(),
            self::COMPLETE_BATCH_SIZE
        );

        $completed = 0;
        foreach ($orderIds as $orderId) {
            $completed += $this->orderDao->newQuery()
                ->where('id', $orderId)
                ->where('status', Order::STATUS_SUCCESS)
                ->whereNull('completed_at')
                ->update(['completed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        }

        return $completed;
    }

    /**
     * 在锁住明细行的事务里推进订单，返回是否需要回调商户。
     *
     * @param array<string, mixed> $fees
     */
    private function advance(Order $order, DriverResult $result, array $fees, int $supplierId): bool
    {
        $express = $this->orderExpressDao->lockForUpdate((int) $order->id);
        if ($express === null) {
            throw new RuntimeException('order_expresses row of order #' . $order->id . ' not found');
        }
        // 拿到锁之后重读：别的请求可能刚改过状态、冻结金额
        $order->refresh();

        $orderUpdates = [];
        if ($order->supplier_order_no === null && $result->supplierOrderNo !== null) {
            $orderUpdates['supplier_id'] = $supplierId;
            $orderUpdates['supplier_order_no'] = $result->supplierOrderNo;
        }
        if ($orderUpdates !== []) {
            $order->fill($orderUpdates)->save();
        }

        $this->syncLogistics($express, $fees);

        $notify = false;
        $advanceable = in_array($order->status, self::ADVANCEABLE_STATUSES, true);

        if ($advanceable && $express->frozen_freight === null
            && ($fees['fee_over'] ?? null) === YunyangStatusMapper::FEE_FROZEN
            && is_string($fees['freight'] ?? null)) {
            $this->adjustFreeze($order, $express, $fees['freight']);
        }

        if ($result->result === UnifiedResult::Success) {
            if ($express->fee_over_at === null) {
                $notify = $advanceable && $this->settle($order, $express, $fees, $supplierId);
            } elseif ($order->status === Order::STATUS_SUCCESS) {
                $notify = $this->adjustAfterSettlement($order, $express, $fees);
            }
        }

        // 签收时间就是完成时间；签收先于扣费到达时，结算那一步会补上
        if ($express->signed_at !== null && $order->status === Order::STATUS_SUCCESS && $order->completed_at === null) {
            $order->fill(['completed_at' => $express->signed_at->toDateTimeString()])->save();
        }

        $express->save();

        return $notify;
    }

    /**
     * @param array<string, mixed> $fees
     */
    private function syncLogistics(OrderExpress $express, array $fees): void
    {
        $waybill = $fees['waybill'] ?? null;
        if (is_string($waybill) && $waybill !== '') {
            $express->waybill_no = $waybill;
        }

        $status = self::LOGISTICS_STATUS[$fees['type_code'] ?? -1] ?? null;
        if ($status !== null) {
            $express->logistics_status = $status;
        }
        if ($status === OrderExpress::STATUS_SIGNED && $express->signed_at === null) {
            $express->signed_at = Carbon::now();
        }
    }

    /**
     * 冻结调整，见类注释第 1 条。保价费、耗材费的预估不变（下单时的 cost_price 减去预估运费），
     * 只按云洋冻结的运费重算运费那一项。
     */
    private function adjustFreeze(Order $order, OrderExpress $express, string $frozenFreight): void
    {
        $extras = bcsub($order->cost_price, $express->estimated_freight, self::SCALE);
        $newSalePrice = bcadd($this->pricingRuleService->salePriceFor(self::BUSINESS_LINE, $frozenFreight), $extras, self::SCALE);
        $delta = bcsub($newSalePrice, $order->frozen_amount, self::SCALE);

        $applied = '0.00';
        if (bccomp($delta, '0', self::SCALE) !== 0) {
            $applied = $this->balanceService->adjustFreeze(
                (int) $order->merchant_id,
                (int) $order->id,
                $delta,
                '快递冻结调整：按供应商冻结运费重算预估售价'
            );
        }

        $order->fill([
            'sale_price' => $newSalePrice,
            'cost_price' => bcadd($frozenFreight, $extras, self::SCALE),
            'frozen_amount' => bcadd($order->frozen_amount, $applied, self::SCALE),
        ])->save();
        $express->frozen_freight = $frozenFreight;
    }

    /**
     * 结算，见类注释第 2 条。返回是否真的由这次调用完成了结算。
     *
     * @param array<string, mixed> $fees
     */
    private function settle(Order $order, OrderExpress $express, array $fees, int $supplierId): bool
    {
        $actual = [];
        foreach (self::FEE_ITEMS as $item => [$feeKey]) {
            $actual[$item] = $this->money($fees[$feeKey] ?? null);
        }
        $freightSalePrice = $this->pricingRuleService->salePriceFor(self::BUSINESS_LINE, $actual[OrderExpressFeeAdjustment::ITEM_FREIGHT]);
        $cost = $this->sum($actual);
        $charge = bcadd($freightSalePrice, bcsub($cost, $actual[OrderExpressFeeAdjustment::ITEM_FREIGHT], self::SCALE), self::SCALE);

        $now = date('Y-m-d H:i:s');
        $frozen = $order->frozen_amount;
        $finished = $this->orderDao->finishIfStatus($order, [
            'status' => Order::STATUS_SUCCESS,
            'supplier_id' => $supplierId,
            'sale_price' => $charge,
            'cost_price' => $cost,
            'deducted_amount' => $charge,
            'finished_at' => $now,
            'completed_at' => $express->signed_at?->toDateTimeString(),
        ], $order->status);
        if (! $finished) {
            return false;
        }

        $merchantId = (int) $order->merchant_id;
        $orderId = (int) $order->id;
        if (bccomp($charge, $frozen, self::SCALE) >= 0) {
            $this->balanceService->deduct($merchantId, $orderId, $frozen);
            $shortfall = bcsub($charge, $frozen, self::SCALE);
            if (bccomp($shortfall, '0', self::SCALE) > 0) {
                $this->balanceService->supplementDeduct($merchantId, $orderId, $shortfall, '快递结算：实际费用高于冻结金额');
            }
        } else {
            $this->balanceService->deduct($merchantId, $orderId, $charge);
            $this->balanceService->unfreeze($merchantId, $orderId, bcsub($frozen, $charge, self::SCALE));
        }

        foreach (self::FEE_ITEMS as $item => [, $column]) {
            $express->{$column} = $actual[$item];
        }
        $express->freight_sale_price = $freightSalePrice;
        $express->fee_over_at = Carbon::parse($now);

        return true;
    }

    /**
     * 结算之后的费用调整，见类注释第 3 条。返回是否产生了调整。
     *
     * @param array<string, mixed> $fees
     */
    private function adjustAfterSettlement(Order $order, OrderExpress $express, array $fees): bool
    {
        $totalDiff = '0.00';
        foreach (self::FEE_ITEMS as $item => [$feeKey, $column]) {
            $new = $fees[$feeKey] ?? null;
            if (! is_string($new) || ! is_numeric($new)) {
                // 这次查询没带这一项：不当成 0，保持上次处理过的值
                continue;
            }
            $new = bcadd($new, '0', self::SCALE);
            $old = $express->{$column} ?? '0.00';
            if (bccomp($new, $old, self::SCALE) === 0) {
                continue;
            }

            if ($item === OrderExpressFeeAdjustment::ITEM_FREIGHT) {
                $newSalePrice = $this->pricingRuleService->salePriceFor(self::BUSINESS_LINE, $new);
                $diff = bcsub($newSalePrice, $express->freight_sale_price ?? '0.00', self::SCALE);
                $express->freight_sale_price = $newSalePrice;
            } else {
                $diff = bcsub($new, $old, self::SCALE);
            }
            $express->{$column} = $new;

            if (bccomp($diff, '0', self::SCALE) === 0) {
                continue;
            }
            $this->applyFeeAdjustment($order, $item, $diff);
            $totalDiff = bcadd($totalDiff, $diff, self::SCALE);
        }

        if (bccomp($totalDiff, '0', self::SCALE) === 0) {
            // 可能只是运费成本变了、加价后的售价没变，成本仍然要同步
            $order->fill(['cost_price' => $this->actualCost($express)])->save();

            return false;
        }

        $order->fill([
            'sale_price' => bcadd($order->sale_price, $totalDiff, self::SCALE),
            'deducted_amount' => bcadd($order->deducted_amount ?? '0.00', $totalDiff, self::SCALE),
            'cost_price' => $this->actualCost($express),
        ])->save();

        return true;
    }

    private function applyFeeAdjustment(Order $order, string $item, string $diff): void
    {
        $supplement = bccomp($diff, '0', self::SCALE) > 0;
        $amount = $supplement ? $diff : bcmul($diff, '-1', self::SCALE);
        $reason = '快递费用调整：' . self::ITEM_NAMES[$item] . ($supplement ? '补扣' : '退回');

        $this->feeAdjustmentDao->record(
            (int) $order->id,
            $supplement ? OrderExpressFeeAdjustment::TYPE_SUPPLEMENT : OrderExpressFeeAdjustment::TYPE_REFUND,
            $item,
            $amount,
            $reason
        );

        if ($supplement) {
            $this->balanceService->supplementDeduct((int) $order->merchant_id, (int) $order->id, $amount, $reason);
        } else {
            $this->balanceService->refundOrder((int) $order->merchant_id, (int) $order->id, $amount, $reason);
        }
    }

    /**
     * 没扣费就结束：同步下单被拒（failed）或者云洋确认已取消（cancelled），全额解冻。
     * 同样在明细行锁里做：解冻金额取的是锁内重读的 frozen_amount，不会跟同时进行的
     * 冻结调整错开。
     */
    private function finishUnsettled(Order $order, string $status, int $supplierId, ?string $failReason): void
    {
        $finished = Db::transaction(function () use ($order, $status, $supplierId, $failReason) {
            $express = $this->orderExpressDao->lockForUpdate((int) $order->id);
            $order->refresh();
            if (! in_array($order->status, self::ADVANCEABLE_STATUSES, true)) {
                return false;
            }

            $finished = $this->orderDao->finishIfStatus($order, [
                'status' => $status,
                'supplier_id' => $supplierId,
                'fail_reason' => $failReason,
                'finished_at' => date('Y-m-d H:i:s'),
            ], $order->status);
            if (! $finished) {
                return false;
            }

            $this->balanceService->unfreeze((int) $order->merchant_id, (int) $order->id, $order->frozen_amount);
            if ($express !== null && $status === Order::STATUS_CANCELLED) {
                $express->fill(['logistics_status' => OrderExpress::STATUS_CANCELLED])->save();
            }

            return true;
        });

        if ($finished) {
            $this->merchantNotifyService->notify((int) $order->id);
        }
    }

    private function recordAttemptResult(Order $order, DriverResult $result): void
    {
        $attempt = $this->orderAttemptDao->findLatestForOrder((int) $order->id);
        if ($attempt === null) {
            return;
        }

        $attempt->fill([
            'result' => SupplierRouter::RESULT_MAP[$result->result->name],
            'fail_reason' => $result->failReason ?? $attempt->fail_reason,
        ]);
        // 结果没变也刷新 updated_at，定时查询靠它控制查询间隔
        $attempt->isDirty() ? $attempt->save() : $attempt->touch();
    }

    private function actualCost(OrderExpress $express): string
    {
        $values = [];
        foreach (self::FEE_ITEMS as [, $column]) {
            $values[] = $express->{$column} ?? '0.00';
        }

        return $this->sum($values);
    }

    /**
     * @param array<array-key, string> $values
     */
    private function sum(array $values): string
    {
        $total = '0.00';
        foreach ($values as $value) {
            $total = bcadd($total, $value, self::SCALE);
        }

        return $total;
    }

    private function money(mixed $value): string
    {
        return is_string($value) && is_numeric($value) ? bcadd($value, '0', self::SCALE) : '0.00';
    }

    private function logger(): LoggerInterface
    {
        return $this->loggerFactory->get('express');
    }
}
