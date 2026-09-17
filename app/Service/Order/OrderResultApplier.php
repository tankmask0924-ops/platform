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

use App\Crypto\Encryptor;
use App\Dao\MerchantDao;
use App\Dao\MerchantRebateDao;
use App\Dao\OrderDao;
use App\Dao\OrderRechargeDao;
use App\Dao\ProductDao;
use App\Dao\SystemSettingDao;
use App\Model\Order;
use App\Model\Product;
use App\OpenApi\ErrorCode;
use App\Service\AbstractService;
use App\Service\Merchant\BalanceService;
use App\Service\MerchantNotifyService;
use App\Service\Product\RebateCalculator;
use App\Supplier\DriverResult;
use App\Supplier\UnifiedResult;
use Carbon\Carbon;
use Hyperf\Database\Exception\QueryException;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;

/**
 * 把一次驱动调用/查询得到的 `DriverResult` 应用到一笔已存在的 `Order` 上——
 * 从 `App\Service\Order\RechargeOrderPlacementService` 抽出来的共享逻辑
 * （docs/modules.md 第 1 节"供应商回调入口与验签框架"任务）。调用方是
 * `App\Service\Order\SupplierRouter`（同步下单和异步切换选出最终结果后收尾）和
 * `App\Service\Order\SupplierCallbackService`（回调给出成功/处理中/未知时直接应用）。
 *
 * 【只做"状态转换 + 余额 + 通知"这一段，不含失败换供应商的循环】明确失败之后要不要
 * 换下一家（包括受理后异步回调失败，requirements.md 6.5）由 SupplierRouter 决定；
 * 到了这里的 `DefiniteFailure` 已经是"不再切换"的最终结果，直接失败、解冻。
 *
 * 【`cost_price` 的"预置值"约定，调用方必须遵守】`apply()` 的入参故意没有"这次
 * 尝试对应的映射行成本价"这个字段（只有 `supplierId`，没有 `SupplierProduct`）——
 * `App\Service\Order\SupplierCallbackService` 场景下压根没有一个"当前尝试对应的
 * 映射行"概念可传（回调到达时，供应商侧成本价一切都以驱动这次返回的权威结果为准，
 * 不存在"预估成本价"这个中间态）。所以约定是：调用 `apply()` 之前，如果调用方
 * 手上有一个更合适的"预估成本价"（比如 `SupplierRouter` 用这次
 * 尝试对应映射行的 `supplier_products.cost_price`），应该自己先
 * `$order->fill(['cost_price' => $estimate])`（只改内存属性，不 `save()`），
 * `apply()` 内部对 `Success`/`Processing`/`Unknown` 三种分支都是"驱动这次给了
 * `actualCost` 就用它，没给就保留 `$order->cost_price` 现有值（可能是调用方刚刚
 * 预置的，也可能是订单上次持久化的旧值）"，`DefiniteFailure` 分支完全不碰
 * `cost_price`（保留现有值）——`fill()` 是内存合并，`apply()` 最终统一
 * `save()` 一次，两次 `fill()` 会合并成一条 UPDATE，不会多打一次 DB 写入。
 * `SupplierCallbackService` 不预置任何东西，直接把从 DB 读到的、订单上次持久化
 * 的 `cost_price` 当基准，符合"没有更权威的估算值就不瞎改"的原则。
 *
 * 【幂等】成功/明确失败两个终态分支用 `OrderDao::finishIfProcessing()` 条件更新落库，
 * 订单已经不是 `processing`（回调和定时查询同时推进同一笔订单时后到的一方）就直接
 * 返回，不扣款、不解冻、不通知。`BalanceService::deduct()`/`unfreeze()` 的
 * `dedupe_order_key` 唯一索引和返佣的 `order_id` 唯一索引仍然是资金层面的兜底。
 * 调用方在调用前做的终态检查（`SupplierCallbackService::handle()`）只是省掉一次
 * 无用的查询，不再是唯一的保护。
 *
 * 【返佣待到账记录生成，requirements.md 5.4】订单成功且拿到返佣基数时，在
 * `BalanceService::deduct()` + 通知之后，紧接着按当前（"下单成功这一刻"，不是
 * 下单发起时那一刻——两者之间商户等级可能被后台改过）的商户等级、
 * `App\Service\Product\RebateCalculator::calculateDetailed()` 算出的比例/来源/
 * 金额，生成一条 `merchant_rebates` 待到账记录（`status = pending`）。这个任务
 * 只做"生成"和"结算"两半（结算见 `App\Service\Merchant\BalanceService::
 * settleRebate()` + `App\Crontab\RebateSettlementCrontab`），**不做**作废/扣回——
 * 触发它们的售后争议处理、人工改判订单状态都还没建，`merchant_rebates.status`
 * 到 `voided`/`clawed_back` 的转换完全不在本类职责内。
 *
 * 金额为 0（商品没配返佣，或商户当前等级在商品维度、业务线维度都没有比例）时，
 * 按 5.3/5.4 的规则**不生成任何记录**，不是"生成一条 amount=0 的记录"。
 *
 * 【`Product` 从哪来】`SupplierRouter` 手上现成有 `Product`（同步下单时下单服务
 * 已经查过，异步切换时路由自己从 `order_recharges` 查出），直接原样传进来，不重复
 * 查询；`SupplierCallbackService::handle()` 直接应用回调结果时手上没有 `Product`，
 * `$product` 传 `null`，
 * `generatePendingRebate()` 这时才按 `order_id` 反查 `order_recharges.product_id`
 * 再查一次 `Product`（`order_recharges` 在下单成功进入路由之前就已经建好一行，
 * 见 `RechargeOrderPlacementService::place()`，回调到达时这行必然已经存在）。
 * 这条本类目前只覆盖话费业务线（唯一有 `OrderRecharge` 行的订单类型），
 * 卡券/电影票/快递不适用（返佣基数/取法都不一样，见
 * `App\Service\Product\RebateCalculator` 类注释）。
 *
 * 【重复调用防护】`merchant_rebates.order_id` 唯一索引兜底：`apply()` 的幂等本
 * 依赖调用方保证只在订单仍是 `processing` 时调用一次，但万一真的被重复调用，
 * 插入返佣记录撞上唯一索引会抛 `QueryException`，这里捕获当幂等 no-op，
 * 跟 `BalanceService::deduct()`/`unfreeze()` 是同一个"插入撞车即已处理过"套路，
 * 不是重新发明。
 *
 * 【卡密写入，docs/modules.md 6 节"卡券下单（二期）"任务补上】`DriverResult::
 * $cardList` 存在的目的就是让驱动把卡密带出来（见该字段类注释），但在这个任务
 * 之前 `applySuccess()` 完全没用过它——卡密从驱动一路拿到，却从没被写进
 * `order_recharges.card_no`/`card_pwd`。`Success` 且 `$cardList` 非空时，取
 * **第一条**（requirements.md 7.1"一单一个"：这条业务线数量恒为 1，理论上
 * `cardList` 只会有一条），用 `App\Crypto\Encryptor` 加密后写入这笔订单已有的
 * `order_recharges` 行（下单时就已经建好，见
 * `RechargeOrderPlacementService::place()`/`CardOrderPlacementService::
 * place()`）。`card_no`/`card_password` 缺一个就只写有的那个，两个都没有就什么
 * 都不写——不强行拼出一个假的空字符串密文。
 *
 * `cardList` 为空/缺失时的防御性分支：卡速售的状态映射规则（`KasushouStatusMapper`）
 * 本身就规定卡类商品必须等 `card_list` 真的到了才判定 `Success`，所以这里理论上
 * 不应该出现"卡类商品 Success 但没有 cardList"——但订单仍然应该正常走完成功流程
 * （不能因为这个防御性判断让整笔已经成功的订单卷进异常），只是卡号卡密字段保留
 * `null`，不额外记日志（这个类目前没有引入 LoggerFactory，为了这一个边界分支
 * 单独引入日志依赖不划算；真的发生时商户查订单会发现卡号卡密缺失，比静默写错数据
 * 更容易在客服侧被发现）。
 *
 * 这段逻辑加在共享的 `OrderResultApplier` 而不是任一下单 Service 里，
 * `SupplierCallbackService` 异步回调推进 `processing` 订单到 `Success` 时
 * 自动获得同样的能力，不需要在回调路径上再重复实现一遍。
 */
class OrderResultApplier extends AbstractService
{
    private const REBATE_DUE_PERIOD_SETTING_KEY = 'rebate_due_period_days';

    /**
     * requirements.md 5.4："固定期限在返佣管理后台设置，全平台统一，默认 7 天"——
     * 这个默认值保证 `system_settings` 一行都没有时返佣到账逻辑仍然正确。
     */
    private const DEFAULT_REBATE_DUE_PERIOD_DAYS = 7;

    #[Inject]
    protected BalanceService $balanceService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    #[Inject]
    protected MerchantNotifyService $merchantNotifyService;

    #[Inject]
    protected RebateCalculator $rebateCalculator;

    #[Inject]
    protected MerchantRebateDao $merchantRebateDao;

    #[Inject]
    protected SystemSettingDao $systemSettingDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderRechargeDao $orderRechargeDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected Encryptor $encryptor;

    /**
     * @param null|Product $product 调用方已经手上有的商品行，见类注释"`Product`
     *                              从哪来"一节；只有 `Success` 分支会用到
     */
    public function apply(Order $order, DriverResult $result, int $supplierId, ?Product $product = null): void
    {
        match ($result->result) {
            UnifiedResult::Success => $this->applySuccess($order, $result, $supplierId, $product),
            UnifiedResult::DefiniteFailure => $this->applyDefiniteFailure($order, $result, $supplierId),
            UnifiedResult::Processing, UnifiedResult::Unknown => $this->applyNonTerminal($order, $result, $supplierId),
        };
    }

    private function applySuccess(Order $order, DriverResult $result, int $supplierId, ?Product $product): void
    {
        $now = date('Y-m-d H:i:s');

        $finished = $this->finish($order, [
            'status' => 'success',
            'cost_price' => $result->actualCost ?? $order->cost_price,
            'supplier_id' => $supplierId,
            'supplier_order_no' => $result->supplierOrderNo,
            'deducted_amount' => $order->sale_price,
            'completed_at' => $now,
            'finished_at' => $now,
        ]);
        if (! $finished) {
            return;
        }

        $this->balanceService->deduct($order->merchant_id, $order->id, $order->sale_price);
        $this->merchantNotifyService->notify($order->id);

        $this->persistCardSecretsIfPresent($order, $result);
        $this->generatePendingRebate($order, $product, $now);
    }

    /**
     * 见类注释"卡密写入"一节。只在 `$result->cardList` 非空时才有动作，话费订单的
     * `DriverResult` 永远不会带 `cardList`，这个方法对它们是无操作。
     */
    private function persistCardSecretsIfPresent(Order $order, DriverResult $result): void
    {
        if ($result->cardList === null || $result->cardList === []) {
            return;
        }

        $first = $result->cardList[0];
        $cardNo = $first['card_no'] ?? null;
        $cardPassword = $first['card_password'] ?? null;

        $updates = [];
        if ($cardNo !== null) {
            $updates['card_no'] = $this->encryptor->encrypt($cardNo);
        }
        if ($cardPassword !== null) {
            $updates['card_pwd'] = $this->encryptor->encrypt($cardPassword);
        }

        if ($updates === []) {
            return;
        }

        $recharge = $this->orderRechargeDao->find($order->id);
        if ($recharge === null) {
            return;
        }

        $recharge->fill($updates)->save();
    }

    private function generatePendingRebate(Order $order, ?Product $product, string $orderCompletedAt): void
    {
        $product ??= $this->resolveProduct($order);
        if ($product === null) {
            return;
        }

        // 用"下单成功这一刻"的商户等级，不是下单发起时的等级快照（两者之间等级
        // 可能被后台调整过）——`MerchantDao::find()` 走 model-cache，缓存靠
        // `App\Listener\DeleteCacheListener` 在等级变更 `save()` 时自动失效，
        // 这里读到的仍然是最新值，不是过期缓存。
        $merchant = $this->merchantDao->find($order->merchant_id);
        if ($merchant === null) {
            return;
        }

        $calculation = $this->rebateCalculator->calculateDetailed($product, $merchant->level_id);
        if (bccomp($calculation->amount, '0', 2) <= 0) {
            // requirements.md 5.3/5.4：没有比例可用或返佣基数为 0，不生成任何记录，
            // 不是生成一条 amount = '0.00' 的记录。
            return;
        }

        $dueDays = (int) $this->systemSettingDao->getValue(
            self::REBATE_DUE_PERIOD_SETTING_KEY,
            self::DEFAULT_REBATE_DUE_PERIOD_DAYS
        );
        $dueAt = Carbon::parse($orderCompletedAt)->addDays($dueDays)->toDateTimeString();

        try {
            $this->merchantRebateDao->create([
                'order_id' => $order->id,
                'merchant_id' => $order->merchant_id,
                'business_line' => $order->business_line,
                'level_id' => $merchant->level_id,
                'rebate_base' => $product->rebate_amount,
                'rebate_base_source' => 'product',
                'rebate_rate' => $calculation->rate,
                'rebate_rate_source' => $calculation->rateSource,
                'amount' => $calculation->amount,
                'status' => 'pending',
                'order_completed_at' => $orderCompletedAt,
                'due_at' => $dueAt,
            ]);
        } catch (QueryException $e) {
            // merchant_rebates.order_id 唯一索引命中：这笔订单已经生成过返佣记录了，
            // 幂等 no-op，见类注释"重复调用防护"一节。
        }
    }

    private function resolveProduct(Order $order): ?Product
    {
        $recharge = $this->orderRechargeDao->find($order->id);
        if ($recharge === null) {
            return null;
        }

        return $this->productDao->find($recharge->product_id);
    }

    /**
     * `orders.fail_reason` 只写平台统一文案（database-design.md「不透传供应商原始信息」），
     * 商户通过订单查询和回调看到的就是这一份。驱动给的原始原因留在内部：同步下单路径
     * 同时写进 `order_attempts.fail_reason`，这里再记一条日志。
     */
    private function applyDefiniteFailure(Order $order, DriverResult $result, int $supplierId): void
    {
        if ($result->failReason !== null) {
            $this->loggerFactory->get('order')->info('order failed by supplier', [
                'order_id' => $order->id,
                'supplier_id' => $supplierId,
                'supplier_fail_reason' => $result->failReason,
            ]);
        }

        $finished = $this->finish($order, [
            'status' => 'failed',
            'supplier_id' => $supplierId,
            'fail_reason' => ErrorCode::OrderFailed->message(),
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
        if (! $finished) {
            return;
        }

        $this->balanceService->unfreeze($order->merchant_id, $order->id, $order->frozen_amount);
        $this->merchantNotifyService->notify($order->id);
    }

    /**
     * `Processing`/`Unknown` 都不是终态：不解冻、不扣款、不通知商户，`status` 原样
     * 保持 `processing`。`supplier_order_no`/`cost_price` 只在这次结果"新给出了"
     * 才覆盖（非 null 才写），没给就保留订单上原有的值——跟 `Success`/
     * `DefiniteFailure` 分支不同，这里刻意不用"给了就用、没给就退回旧值"的
     * `?? $order->xxx` 写法，而是干脆不把这个键放进 `fill()` 数组，效果完全等价
     * 但更清楚地表达"这次没有更新"这件事本身。
     */
    private function applyNonTerminal(Order $order, DriverResult $result, int $supplierId): void
    {
        $updates = ['supplier_id' => $supplierId];

        if ($result->supplierOrderNo !== null) {
            $updates['supplier_order_no'] = $result->supplierOrderNo;
        }

        if ($result->actualCost !== null) {
            $updates['cost_price'] = $result->actualCost;
        }

        $order->fill($updates)->save();
    }

    /**
     * 终态只落一次：回调和定时查询可能同时推进同一笔订单，条件更新失败的一方
     * 直接放弃，不再扣款/解冻/通知。调用方预置在内存里、还没保存的改动（比如
     * 同步下单预置的 cost_price）一并写入。
     *
     * @param array<string, mixed> $attributes
     */
    private function finish(Order $order, array $attributes): bool
    {
        return $this->orderDao->finishIfProcessing($order, array_merge($order->getDirty(), $attributes));
    }
}
