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

use App\Model\Order;
use App\Service\AbstractService;
use App\Service\Merchant\BalanceService;
use App\Service\MerchantNotifyService;
use App\Supplier\DriverResult;
use App\Supplier\UnifiedResult;
use Hyperf\Di\Annotation\Inject;

/**
 * 把一次驱动调用/查询得到的 `DriverResult` 应用到一笔已存在的 `Order` 上——
 * 从 `App\Service\Order\RechargeOrderPlacementService` 抽出来的共享逻辑
 * （docs/modules.md 第 1 节"供应商回调入口与验签框架"任务），现在有两个调用方：
 * 1. `RechargeOrderPlacementService::routeAndFinalize()`：同步下单路由循环选出
 *    "最终结果"（成功，或者尝试到最后一个供应商仍失败）之后，用这次结果收尾；
 * 2. `App\Service\Order\SupplierCallbackService::handle()`：供应商异步回调把一笔
 *    `processing` 订单推进到终态。
 *
 * 【被抽取出来的只是"状态转换 + 余额 + 通知"这一段，故意不包含失败换供应商的
 * 循环】这是本类存在的核心边界，必须先讲清楚：`RechargeOrderPlacementService::
 * routeAndFinalize()` 遇到 `DefiniteFailure` 时会换下一个 `supplier_products`
 * 映射行重试（requirements.md 6.2"只有明确失败才换下一个供应商"），这个"换下一
 * 家"的循环逻辑本身**没有**被搬进这个类，原因很简单：一旦订单已经进入
 * `processing`（已经确定性地绑定到某一次供应商尝试、平台也已经把订单号返回给了
 * 商户），后续任何回调/查询报告的 `DefiniteFailure` 说的是"这一笔订单definite 失败
 * 了"，不是"换个供应商重试"——商户已经拿着这个平台订单号了，不可能在背后偷偷换成
 * 另一个供应商的另一次下单去顶替它。所以本类的 `apply()` 只做"这次结果落到这笔
 * 订单上该怎么变"这一步单次判断，循环怎么走（选下一个映射行、判断要不要继续）
 * 仍然完全留在 `RechargeOrderPlacementService::routeAndFinalize()`/
 * `finalizeOrder()` 里，不属于这个类的职责。
 *
 * 【`cost_price` 的"预置值"约定，调用方必须遵守】`apply()` 的入参故意没有"这次
 * 尝试对应的映射行成本价"这个字段（只有 `supplierId`，没有 `SupplierProduct`）——
 * `App\Service\Order\SupplierCallbackService` 场景下压根没有一个"当前尝试对应的
 * 映射行"概念可传（回调到达时，供应商侧成本价一切都以驱动这次返回的权威结果为准，
 * 不存在"预估成本价"这个中间态）。所以约定是：调用 `apply()` 之前，如果调用方
 * 手上有一个更合适的"预估成本价"（比如 `RechargeOrderPlacementService` 用这次
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
 * 【幂等】依赖调用方保证：只有订单当前仍是 `processing` 时才应该调用 `apply()`——
 * 见 `SupplierCallbackService::handle()` 的显式状态检查。`apply()` 本身不检查
 * `$order->status`，也不需要检查：`BalanceService::deduct()`/`unfreeze()`
 * 各自已经靠 `merchant_balance_logs.dedupe_order_key` 唯一索引做到"同一
 * `order_id` 只处理一次"（见 `BalanceService` 类注释），真正兜底重复调用的是那
 * 一层，不需要在这里重复造轮子；但"要不要调用 `apply()`"这个更早的判断（订单是否
 * 已经是终态）必须由调用方在调用前做，`apply()` 假设"调用我就意味着这次结果应该
 * 被应用"，不会自己去反悔。
 */
class OrderResultApplier extends AbstractService
{
    #[Inject]
    protected BalanceService $balanceService;

    #[Inject]
    protected MerchantNotifyService $merchantNotifyService;

    public function apply(Order $order, DriverResult $result, int $supplierId): void
    {
        match ($result->result) {
            UnifiedResult::Success => $this->applySuccess($order, $result, $supplierId),
            UnifiedResult::DefiniteFailure => $this->applyDefiniteFailure($order, $result, $supplierId),
            UnifiedResult::Processing, UnifiedResult::Unknown => $this->applyNonTerminal($order, $result, $supplierId),
        };
    }

    private function applySuccess(Order $order, DriverResult $result, int $supplierId): void
    {
        $order->fill([
            'status' => 'success',
            'cost_price' => $result->actualCost ?? $order->cost_price,
            'supplier_id' => $supplierId,
            'supplier_order_no' => $result->supplierOrderNo,
            'deducted_amount' => $order->sale_price,
            'completed_at' => date('Y-m-d H:i:s'),
            'finished_at' => date('Y-m-d H:i:s'),
        ])->save();

        $this->balanceService->deduct($order->merchant_id, $order->id, $order->sale_price);
        $this->merchantNotifyService->notify($order->id);
    }

    private function applyDefiniteFailure(Order $order, DriverResult $result, int $supplierId): void
    {
        $order->fill([
            'status' => 'failed',
            'supplier_id' => $supplierId,
            'fail_reason' => $result->failReason ?? '供应商明确下单失败',
            'finished_at' => date('Y-m-d H:i:s'),
        ])->save();

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
}
