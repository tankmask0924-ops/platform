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
 * 【只解决"回调正常到达"这一条路径】回调丢失/从未到达的订单由定时查询兜底
 * （`App\Service\Order\SupplierResultPollingService`）。
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
 * 【结果交给路由】验签、找订单、过期检查之后，结果交给
 * `SupplierRouter::applyAttemptResult()`：明确失败在切换时长内换下一家
 * （requirements.md 6.5），成功/处理中/未知直接落到订单上。定时查询走同一个方法。
 *
 * 【过期回调】订单已经切到后面的尝试之后，前一次尝试的回调（包括供应商按间隔重推的
 * 同一个失败回调）按 `attempt_no`/供应商跟最新一次尝试对不上来识别，只回复 `ok`，
 * 不再应用——否则同一笔订单会被重复切换，或者被旧结果覆盖。
 *
 * 【卡券订单按卡券解析】卡速售驱动需要知道是不是卡密商品（卡密没到不算成功）。
 * 验签之前先用回调里原始的 `external_orderno` 找到订单，只用来决定 `isCardProduct`；
 * 验签后驱动查询得到的权威单号必须指向同一笔订单，否则按找不到订单处理。
 *
 * 【幂等】订单已经是终态就直接回复 `ok`，不再往下处理（省掉旧结果覆盖已落定字段的
 * 可能）。例外是**已成功**的订单：交给 SupplierRefundAfterSuccessService 看是不是成功后被退款了。跟定时查询同时推进同一笔订单的竞争由 `OrderResultApplier` 的条件更新兜住，
 * 资金层面还有 `BalanceService` 的唯一索引。
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
 * 【快递（云洋）、电影票（芒果）另走一条】按 `suppliers.driver` 分派给 App\Service\Order\ExpressCallbackService
 * / MovieCallbackService：
 * 云洋回调没有签名、没有尝试序号、要求回复 JSON，订单成功之后还会因为费用调整和签收继续回调，
 * 上面这套"验签 → 按 external_orderno 找订单 → 终态直接回 ok"对它都不成立。
 * 【范围外，见任务说明】商品变更通知 webhook（`App\Service\Supplier\
 * ProductSyncService::applyNotification()` 已有原语，路由未建，是另一个更小的
 * 后续任务，不在本类）、IP 白名单/限流、除卡速售外的其它驱动——一律不在本类职责内。
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
    protected SupplierRouter $supplierRouter;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    #[Inject]
    protected ExpressCallbackService $expressCallbackService;

    #[Inject]
    protected MovieCallbackService $movieCallbackService;

    #[Inject]
    protected SupplierRefundAfterSuccessService $refundAfterSuccessService;

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

        if ($supplier->driver === 'yunyang') {
            // 快递：没有签名、没有尝试序号、终态之后还有费用调整，跟话费卡券不是一套流程
            return $this->expressCallbackService->handle($supplier, $payload);
        }
        if ($supplier->driver === 'mango') {
            // 电影票：同样没有签名，出票后改票根还会再回调
            return $this->movieCallbackService->handle($supplier, $payload);
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

        if ($order->status === Order::STATUS_SUCCESS) {
            // 成功之后供应商又推回调：多半是售后、运营商冲正导致的退款（kasushou.md 第 2 节"3 之后变为 5"），
            // 全额退款自动处理、部分退款告警转人工；仍是成功的（重推同一个回调）什么都不做
            $this->refundAfterSuccessService->handle($order, $result);

            return self::KASUSHOU_SUCCESS_REPLY;
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
        }

        $this->supplierRouter->applyAttemptResult($order, $latest, $result, (int) $supplier->id);

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
