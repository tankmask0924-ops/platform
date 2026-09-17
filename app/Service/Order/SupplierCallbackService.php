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
use App\Dao\SupplierDao;
use App\Exception\CallbackOrderNotFoundException;
use App\Exception\InvalidSupplierCallbackSignatureException;
use App\Exception\SupplierNotFoundException;
use App\Model\Order;
use App\Service\AbstractService;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;

/**
 * 供应商回调统一入口的业务逻辑（requirements.md 6.8，docs/modules.md 第 1 节
 * "供应商回调入口与验签框架"）：按 `suppliers.code` 找到供应商 -> 建对应驱动 ->
 * 验签 -> 从驱动权威结果反推出平台订单 -> 推进订单状态。`App\Controller\
 * NotifySupplierController` 只做 HTTP 层的薄封装（读 body/header、把本类抛出的
 * 几种异常映射成 HTTP 状态码），业务逻辑全部在这里，符合这个代码库
 * Controller/Service 分层的既有约定。
 *
 * 【只解决"回调正常到达"这一条路径，不是异常单兜底】kasushou.md 描述的"结果未知，
 * 按 external_orderno 定时查询"是给回调彻底丢失/从未到达的订单兜底的，那是一个
 * 独立的 `#[Crontab]` 定时扫描任务（本次任务范围明确不含，见类注释"范围外"）。
 * 本类只处理"供应商真的把回调打过来了"这一种情况，包括供应商按 kasushou.md
 * 文档重试同一个回调（5/10/15/20/25 分钟，最多 5 次）的情况——重试也是"回调正常
 * 到达"，只是到达了不止一次，靠下面的幂等检查处理，不是"回调丢失"那个更难的问题。
 *
 * 【为什么不直接信任回调 payload，而是要走 `parseCallback()` 内部的
 * `queryOrder()`】这是 `App\Supplier\Kasushou\KasushouDriver::parseCallback()`
 * 自己的既有设计决定（"回调只作为触发"，见该方法类注释），本类只是调用方，不重复
 * 这个决定的理由，照抄不重新发明。
 *
 * 【`external_orderno` -> `order_no` 的截取规则】`SupplierRouter` 生成
 * `external_orderno` 的方式是
 * `"{$order->order_no}-{$attemptNo}"`，而 `order_no` 本身（见
 * `RechargeOrderPlacementService::generateOrderNo()`：`'R' . date('YmdHis') .
 * random_int(100000, 999999)`）不含 `-`，所以从第一个 `-` 前面截出来的子串就是
 * 原始 `order_no`，不需要更复杂的解析；`-` 后面是这次尝试的 `attempt_no`。
 *
 * 【明确失败 -> 交给路由继续切换】受理后异步回调失败也要换下一家供应商
 * （requirements.md 6.5），在切换时长内由 `SupplierRouter::continueAfterDefiniteFailure()`
 * 决定换不换；成功/处理中/未知直接交给 `OrderResultApplier`。
 *
 * 【过期回调】订单已经切到后面的尝试之后，前一次尝试的回调（包括供应商按间隔重推的
 * 同一个失败回调）按 `attempt_no`/供应商跟最新一次尝试对不上来识别，只回复 `ok`，
 * 不再应用——否则同一笔订单会被重复切换，或者被旧结果覆盖。
 *
 * 【卡券订单按卡券解析】卡速售驱动需要知道是不是卡密商品（卡密没到不算成功）。
 * 验签之前先用回调里原始的 `external_orderno` 找到订单，只用来决定 `isCardProduct`；
 * 验签后驱动查询得到的权威单号必须指向同一笔订单，否则按找不到订单处理。
 *
 * 【幂等：只在订单仍是 `processing` 时才应用结果】`App\Service\Merchant\
 * BalanceService::deduct()`/`unfreeze()` 本身已经是幂等的（`merchant_balance_logs.
 * dedupe_order_key` 唯一索引兜底，见该类类注释），但那只保证"钱不会被扣/解冻
 * 两次"，不保证"不会对一笔已经是终态的订单重复调用 `MerchantNotifyService::
 * notify()`（多推一次通知任务）或者把已经落定的 `fail_reason`/`completed_at`
 * 等字段用一次旧的/重复的驱动结果覆盖掉"。所以这里显式检查 `$order->status`，
 * 已经是 `success`/`failed` 等终态就直接短路回复 `ok`，不调用
 * `OrderResultApplier::apply()`——这不是重新发明幂等，是 `BalanceService` 幂等
 * 保证覆盖范围之外、这一层必须自己补上的另一半。
 *
 * 【返回值是驱动特定的裸文本，不是这个代码库其它地方常见的 {code,message,data}
 * 信封】kasushou.md 要求回调响应体必须是字面字符串 `ok` 才算"平台已接收"，否则
 * 供应商会按文档描述的间隔重试。这是供应商单方面定义的响应契约，跟
 * `App\Controller\OpenApi\*` 那一侧 `{code,message,data}` 的信封完全不是一回事，
 * 也不应该被混着用。**这里把 `'ok'` 直接硬编码在本类里，是因为目前只有卡速售一个
 * 驱动**——等接入第二个供应商驱动时，"回调成功该回复什么内容"很可能因驱动而异
 * （不同供应商大概率有不同的响应契约），到时候需要把这一个字符串变成由驱动自己
 * 声明的驱动特定行为（类似 `parseCallback()` 现在这样每个驱动自己实现），本次
 * 任务不为一个尚不存在的第二个驱动预先设计这层抽象。
 *
 * 【范围外，见任务说明】商品变更通知 webhook（`App\Service\Supplier\
 * ProductSyncService::applyNotification()` 已有原语，路由未建，是另一个更小的
 * 后续任务，不在本类）、IP 白名单/限流、除卡速售外的其它驱动、定时重查询兜底
 * （见上）——一律不在本类职责内。
 */
class SupplierCallbackService extends AbstractService
{
    /**
     * kasushou.md 对回调成功响应体的字面要求。见类注释"驱动特定裸文本"一节，
     * 目前只有卡速售一个驱动，暂不为它单独建一层"驱动声明自己的成功回复文本"
     * 的抽象。
     */
    private const KASUSHOU_SUCCESS_REPLY = 'ok';

    private const TERMINAL_STATUSES = ['success', 'failed'];

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderAttemptDao $orderAttemptDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected OrderResultApplier $orderResultApplier;

    #[Inject]
    protected SupplierRouter $supplierRouter;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function handle(string $supplierCode, array $payload, array $headers): string
    {
        $supplier = $this->supplierDao->findByCode($supplierCode);
        if ($supplier === null) {
            throw new SupplierNotFoundException('SupplierCallbackService: unknown supplier code "' . $supplierCode . '".');
        }

        $driver = $this->supplierDriverFactory->build($supplier);

        // 未验签的单号只用来选择解析方式，不据此做任何状态变更
        $claimed = $this->findOrderByExternalOrderNo($payload['external_orderno'] ?? null);

        $result = $driver->parseCallback($payload, $headers, $claimed?->business_line === 'card');
        if ($result === null) {
            throw new InvalidSupplierCallbackSignatureException(
                'SupplierCallbackService: callback signature verification failed for supplier "' . $supplierCode . '".'
            );
        }

        $externalOrderNo = $result->rawRequest['external_orderno'] ?? null;
        if (! is_string($externalOrderNo) || $externalOrderNo === '') {
            throw new CallbackOrderNotFoundException(
                'SupplierCallbackService: driver result carries no resolvable external_orderno.'
            );
        }

        [$orderNo, $attemptNo] = $this->splitExternalOrderNo($externalOrderNo);

        $order = $this->orderDao->findByOrderNo($orderNo);
        if ($order === null || ($claimed !== null && $claimed->id !== $order->id)) {
            throw new CallbackOrderNotFoundException(
                'SupplierCallbackService: no order found for order_no "' . $orderNo . '" (external_orderno "' . $externalOrderNo . '").'
            );
        }

        if (in_array($order->status, self::TERMINAL_STATUSES, true)) {
            // 订单已经是终态：供应商重试同一个回调，或者别的路径已经先一步推进了。
            return self::KASUSHOU_SUCCESS_REPLY;
        }

        $latest = $this->orderAttemptDao->findLatestForOrder($order->id);
        if ($latest !== null) {
            if ((int) $latest->attempt_no !== $attemptNo || (int) $latest->supplier_id !== (int) $supplier->id) {
                $this->loggerFactory->get('order')->info('stale supplier callback ignored', [
                    'order_id' => $order->id,
                    'callback_attempt_no' => $attemptNo,
                    'callback_supplier_id' => $supplier->id,
                    'latest_attempt_no' => $latest->attempt_no,
                ]);

                return self::KASUSHOU_SUCCESS_REPLY;
            }

            $latest->fill([
                'result' => SupplierRouter::RESULT_MAP[$result->result->name],
                'fail_reason' => $result->failReason ?? $latest->fail_reason,
            ])->save();
        }

        if ($result->result === UnifiedResult::DefiniteFailure) {
            $this->supplierRouter->continueAfterDefiniteFailure($order, $result, $supplier->id);
        } else {
            $this->orderResultApplier->apply($order, $result, $supplier->id);
        }

        return self::KASUSHOU_SUCCESS_REPLY;
    }

    private function findOrderByExternalOrderNo(mixed $externalOrderNo): ?Order
    {
        if (! is_string($externalOrderNo) || ! str_contains($externalOrderNo, '-')) {
            return null;
        }

        return $this->orderDao->findByOrderNo($this->splitExternalOrderNo($externalOrderNo)[0]);
    }

    /**
     * @return array{0: string, 1: null|int} order_no 和 attempt_no（后缀不是数字时为 null）
     */
    private function splitExternalOrderNo(string $externalOrderNo): array
    {
        $dashPosition = strpos($externalOrderNo, '-');
        if ($dashPosition === false) {
            throw new CallbackOrderNotFoundException(
                'SupplierCallbackService: external_orderno "' . $externalOrderNo . '" is not in "{order_no}-{attempt}" shape.'
            );
        }

        $suffix = substr($externalOrderNo, $dashPosition + 1);

        return [
            substr($externalOrderNo, 0, $dashPosition),
            ctype_digit($suffix) ? (int) $suffix : null,
        ];
    }
}
