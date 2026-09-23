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

namespace App\Service\Admin;

use App\Dao\AdminOperationLogDao;
use App\Dao\MerchantBalanceLogDao;
use App\Dao\MerchantNotifyLogDao;
use App\Dao\MerchantRebateDao;
use App\Dao\OrderAttemptDao;
use App\Dao\OrderDao;
use App\Dao\OrderExpressDao;
use App\Dao\OrderExpressFeeAdjustmentDao;
use App\Dao\OrderMovieDao;
use App\Dao\OrderRechargeDao;
use App\Dao\ProductDao;
use App\Dao\SupplierDao;
use App\Model\AdminOperationLog;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantNotifyLog;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderExpressFeeAdjustment;
use App\Service\AbstractService;
use App\Service\MerchantNotifyService;
use App\Service\Order\OrderRefundService;
use App\Service\Order\OrderResultApplier;
use App\Service\Order\SupplierRefundAfterSuccessService;
use App\Service\Order\SupplierResultPollingService;
use App\Service\Supplier\SupplierNotifyAddressService;
use App\Supplier\CardSecretMasker;
use App\Supplier\DriverResult;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Throwable;

/**
 * 系统管理后台「订单管理」（requirements.md 8.3），docs/modules.md 第 8 节。
 *
 * - 列表 / 详情：详情带供应商尝试记录（含请求/响应快照）、资金流水、商户回调记录、
 *   返佣记录、人工操作记录。卡号卡密一律不在后台明文展示，快照里的卡密字段也打码。
 * - 异常单人工处理（requirements.md 7.4 abnormal → success / failed）：只允许异常单，
 *   必须填备注，写操作日志。置成功照常扣款、生成返佣、通知商户；置失败照常解冻、通知
 *   商户，不再切换供应商。卡密类卡券置成功时必须当场向供应商查到成功且拿到卡密，
 *   否则商户会收到一笔"成功但没有卡密"的订单。
 * - 手动查询供应商：处理中订单查到结果照常推进（跟定时查询同一条路径），异常单只把
 *   结果记到尝试记录上供人工核实。
 * - 手动重推商户回调：只允许已是终态的订单，推一次新的通知任务（失败后照常按间隔重试）。
 * - 不含：部分退款处理（退款流程未建）、发起供应商撤单（驱动未实现撤单）。
 */
class OrderAdminService extends AbstractService
{
    public const MODULE = 'order';

    private const STATUSES = ['processing', 'success', 'failed', 'cancelled', 'abnormal', 'refunded'];

    private const BUSINESS_LINES = ['recharge', 'card', 'movie', 'express'];

    /**
     * 已经有最终结果、可以重推商户回调的状态（requirements.md 7.6）。
     */
    private const NOTIFIABLE_STATUSES = ['success', 'failed', 'cancelled', 'refunded'];

    private const QUERYABLE_STATUSES = [Order::STATUS_PROCESSING, Order::STATUS_ABNORMAL];

    private const RESOLUTIONS = ['success', 'failed'];

    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderAttemptDao $orderAttemptDao;

    #[Inject]
    protected OrderRechargeDao $orderRechargeDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected MerchantBalanceLogDao $balanceLogDao;

    #[Inject]
    protected MerchantNotifyLogDao $notifyLogDao;

    #[Inject]
    protected MerchantRebateDao $rebateDao;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    #[Inject]
    protected OrderResultApplier $orderResultApplier;

    #[Inject]
    protected SupplierResultPollingService $pollingService;

    #[Inject]
    protected MerchantNotifyService $merchantNotifyService;

    #[Inject]
    protected OrderExpressDao $orderExpressDao;

    #[Inject]
    protected OrderExpressFeeAdjustmentDao $feeAdjustmentDao;

    #[Inject]
    protected OrderMovieDao $orderMovieDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected SupplierNotifyAddressService $notifyAddressService;

    #[Inject]
    protected OrderRefundService $orderRefundService;

    #[Inject]
    protected SupplierRefundAfterSuccessService $refundAfterSuccessService;

    /**
     * @param array<string, mixed> $query 原始查询参数
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $query): array
    {
        $filters = $this->normalizeFilters($query);
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 15)));

        $orders = $this->orderDao->paginateFiltered($filters, $page, $perPage);

        return [
            'data' => $orders->map(fn (Order $order) => $this->formatOrder($order))->values()->all(),
            'total' => $this->orderDao->countFiltered($filters),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(int $orderId): array
    {
        $order = $this->findOrderOrFail($orderId);

        $supplierNames = [];
        $supplierName = function (?int $supplierId) use (&$supplierNames): ?string {
            if ($supplierId === null) {
                return null;
            }

            return $supplierNames[$supplierId] ??= $this->supplierDao->find($supplierId)?->name;
        };

        return $this->formatOrder($order) + [
            'supplier_name' => $supplierName($order->supplier_id),
            'recharge' => $this->formatRecharge($order),
            'express' => $this->formatExpress($order),
            'movie' => $this->formatMovie($order),
            'attempts' => $this->orderAttemptDao->listForOrder($order->id)
                ->map(fn (OrderAttempt $attempt) => [
                    'attempt_no' => $attempt->attempt_no,
                    'supplier_id' => $attempt->supplier_id,
                    'supplier_name' => $supplierName((int) $attempt->supplier_id),
                    'result' => $attempt->result,
                    'fail_reason' => $attempt->fail_reason,
                    'request_snapshot' => CardSecretMasker::mask($attempt->request_snapshot),
                    'response_snapshot' => CardSecretMasker::mask($attempt->response_snapshot),
                    'created_at' => $attempt->created_at?->toDateTimeString(),
                    'updated_at' => $attempt->updated_at?->toDateTimeString(),
                ])->values()->all(),
            'balance_logs' => $this->balanceLogDao->findByOrderId($order->id)
                ->map(static fn (MerchantBalanceLog $log) => [
                    'type' => $log->type,
                    'amount' => $log->amount,
                    'available_after' => $log->available_after,
                    'frozen_after' => $log->frozen_after,
                    'created_at' => $log->created_at?->toDateTimeString(),
                ])->values()->all(),
            'notify_logs' => $this->notifyLogDao->findByOrderId($order->id)
                ->map(static fn (MerchantNotifyLog $log) => [
                    'attempt_no' => $log->attempt_no,
                    'url' => $log->url,
                    'http_status' => $log->http_status,
                    'success' => (bool) $log->success,
                    'response_body' => $log->response_body,
                    'created_at' => $log->created_at?->toDateTimeString(),
                ])->values()->all(),
            'rebate' => $this->formatRebate($order->id),
            'operation_logs' => $this->operationLogDao->listForTarget(self::MODULE, 'order', $order->id)
                ->map(static fn (AdminOperationLog $log) => [
                    'admin_user_id' => $log->admin_user_id,
                    'action' => $log->action,
                    'before' => $log->before_data,
                    'after' => $log->after_data,
                    'created_at' => $log->created_at?->toDateTimeString(),
                ])->values()->all(),
        ];
    }

    /**
     * 异常单人工置成功 / 置失败。
     *
     * @return array<string, mixed> 处理后的订单
     */
    public function resolveAbnormal(int $orderId, mixed $result, mixed $remark, mixed $supplierOrderNo, int $adminUserId, ?string $ip): array
    {
        if (! is_string($result) || ! in_array($result, self::RESOLUTIONS, true)) {
            throw new HttpException(422, 'result 只能是 success 或 failed');
        }
        $remark = is_string($remark) ? trim($remark) : '';
        if ($remark === '' || mb_strlen($remark) > 255) {
            throw new HttpException(422, 'remark 不能为空，且不超过 255 个字符');
        }
        if ($supplierOrderNo !== null && (! is_string($supplierOrderNo) || strlen($supplierOrderNo) > 64)) {
            throw new HttpException(422, 'supplier_order_no 必须是不超过 64 个字符的字符串');
        }

        $order = $this->findOrderOrFail($orderId);
        if ($order->status !== Order::STATUS_ABNORMAL) {
            throw new HttpException(409, '只有异常单可以人工处理');
        }
        $before = $this->formatOrder($order);

        $supplierId = $order->supplier_id !== null ? (int) $order->supplier_id : null;
        if ($supplierId === null) {
            throw new HttpException(409, '订单没有关联供应商，无法人工处理');
        }
        if ($result === 'success' && $order->business_line === 'express') {
            // 快递的成功是云洋按实际费用扣费（requirements.md 7.2），扣多少、补扣还是解冻都要靠
            // 云洋的费用明细，人工置成功会按预估价扣款。只能人工置失败（云洋确认没有这一单时全额解冻）
            throw new HttpException(409, '快递订单不能人工置成功，扣费结果请用「查询供应商」同步');
        }
        if ($result === 'success' && $order->business_line === 'movie') {
            // 电影票成功必须带着取票码和供应商返佣，只有芒果的订单详情里有，人工置成功商户拿不到票
            throw new HttpException(409, '电影票订单不能人工置成功，出票结果请用「查询供应商」同步');
        }

        $driverResult = $result === 'success'
            ? $this->manualSuccessResult($order, $supplierOrderNo)
            : new DriverResult(result: UnifiedResult::DefiniteFailure, failReason: 'manual: ' . $remark);

        $this->orderResultApplier->apply($order, $driverResult, $supplierId);

        $order->refresh();
        if ($order->status !== $result) {
            // 条件更新没成功：处理期间订单已经被别的请求改掉了
            throw new HttpException(409, '订单状态已变化，请刷新后重试');
        }

        $this->operationLogDao->record($adminUserId, self::MODULE, 'resolve_abnormal', 'order', $order->id, $before, $this->formatOrder($order) + [
            'remark' => $remark,
        ], $ip);

        return $this->formatOrder($order);
    }

    /**
     * @return array{result: string, supplier_order_no: null|string, fail_reason: null|string, order: array<string, mixed>}
     */
    public function querySupplier(int $orderId, int $adminUserId, ?string $ip): array
    {
        $order = $this->findOrderOrFail($orderId);
        $checkingRefund = $order->status === Order::STATUS_SUCCESS && in_array($order->business_line, ['recharge', 'card'], true);
        // 电影票成功订单：补晚到的供应商返佣、同步改签后的取票码（返佣对账报出差异后在这里补）
        $refreshingMovie = $order->status === Order::STATUS_SUCCESS && $order->business_line === 'movie';
        if (! $checkingRefund && ! $refreshingMovie && ! in_array($order->status, self::QUERYABLE_STATUSES, true)) {
            throw new HttpException(409, '只有处理中、异常的订单，或者话费卡券、电影票的成功订单可以查询供应商');
        }
        $before = $this->formatOrder($order);

        if ($checkingRefund) {
            return $this->checkRefundAfterSuccess($order, $before, $adminUserId, $ip);
        }

        try {
            $result = $this->pollingService->queryLatestAttempt($order);
        } catch (Throwable $e) {
            throw new HttpException(502, '查询供应商失败：' . $e->getMessage(), 0, $e);
        }
        if ($result === null) {
            throw new HttpException(409, $order->business_line === 'express'
                ? '快递订单还没有云洋单号（下单结果未知），无法查询，请到云洋后台按平台订单号核实'
                : ($order->business_line === 'movie'
                    ? '电影票订单还没有芒果单号（锁座结果未知），无法查询；锁座到期会自动释放解冻'
                    : '订单没有供应商尝试记录，无法查询'));
        }

        $order->refresh();
        $response = [
            'result' => strtolower($result->result->name),
            'supplier_order_no' => $result->supplierOrderNo,
            'fail_reason' => $result->failReason,
            'order' => $this->formatOrder($order),
        ];

        $this->operationLogDao->record($adminUserId, self::MODULE, 'query_supplier', 'order', $order->id, $before, [
            'supplier_result' => $response['result'],
            'status' => $order->status,
        ], $ip);

        return $response;
    }

    public function renotify(int $orderId, int $adminUserId, ?string $ip): void
    {
        $order = $this->findOrderOrFail($orderId);
        if (! in_array($order->status, self::NOTIFIABLE_STATUSES, true)) {
            throw new HttpException(409, '订单还没有最终结果，不能重推回调');
        }

        $this->merchantNotifyService->notify($order->id);

        $this->operationLogDao->record($adminUserId, self::MODULE, 'renotify', 'order', $order->id, null, [
            'status' => $order->status,
        ], $ip);
    }

    /**
     * 异常单发起供应商撤单（requirements.md 7.1「供应商支持撤单时，客服可发起撤单」，kasushou.md 第 1 节）。
     * 只对话费、卡券（卡速售）的异常单开放；按最新一次尝试的 `external_orderno` 撤。
     *
     * **只发起，不改订单**：卡速售受理撤单不等于撤单成功，结果以撤单结果回调或「查询供应商」看到的状态为准。
     * 异常单的自动推进本来就只记录不改订单（SupplierRouter），所以撤单成功后客服用「人工处理 → 置失败」解冻；
     * 撤单失败（已经充上了）就按实际情况置成功。受理与否、供应商给的原因都记操作日志。
     *
     * @return array{accepted: bool, message: string}
     */
    public function cancelAtSupplier(int $orderId, int $adminUserId, ?string $ip): array
    {
        $order = $this->findOrderOrFail($orderId);
        if ($order->status !== Order::STATUS_ABNORMAL || ! in_array($order->business_line, ['recharge', 'card'], true)) {
            throw new HttpException(409, '只有话费、卡券的异常单可以发起供应商撤单');
        }
        $attempt = $this->orderAttemptDao->findLatestForOrder((int) $order->id);
        $supplier = $attempt === null ? null : $this->supplierDao->find((int) $attempt->supplier_id);
        if ($attempt === null || $supplier === null) {
            throw new HttpException(409, '订单没有供应商尝试记录，无法撤单');
        }

        try {
            $result = $this->supplierDriverFactory->build($supplier)->cancelOrder(
                $order->order_no . '-' . $attempt->attempt_no,
                $this->notifyAddressService->orderNotifyUrl($supplier)
            );
        } catch (Throwable $e) {
            throw new HttpException(502, '发起撤单失败：' . $e->getMessage(), 0, $e);
        }

        $this->operationLogDao->record($adminUserId, self::MODULE, 'cancel_at_supplier', 'order', $order->id, null, $result + [
            'supplier_id' => (int) $supplier->id,
            'attempt_no' => (int) $attempt->attempt_no,
        ], $ip);

        return $result;
    }

    /**
     * 部分退款（requirements.md 7.1「供应商部分退款：转人工处理」、8.3「部分退款订单处理」）：客服核实供应商
     * 实际退了多少后，把对应金额退给商户。只对话费、卡券的成功订单；退到跟已扣款一样多就是全额退款
     * （订单改已退款、返佣作废或扣回），见 OrderRefundService::refundPartially()。
     *
     * @return array<string, mixed> 处理后的订单
     */
    public function partialRefund(int $orderId, mixed $amount, mixed $remark, int $adminUserId, ?string $ip): array
    {
        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            throw new HttpException(422, 'amount 必须是金额');
        }
        $amount = (string) $amount;
        if (preg_match('/^\d+(\.\d{1,2})?$/', $amount) !== 1 || bccomp($amount, '0', 2) <= 0) {
            throw new HttpException(422, 'amount 必须是大于 0、最多两位小数的金额');
        }
        $remark = is_string($remark) ? trim($remark) : '';
        if ($remark === '' || mb_strlen($remark) > 200) {
            throw new HttpException(422, 'remark 不能为空，且不超过 200 个字符');
        }

        $order = $this->findOrderOrFail($orderId);
        if ($order->status !== Order::STATUS_SUCCESS || ! in_array($order->business_line, ['recharge', 'card'], true)) {
            throw new HttpException(409, '只有话费、卡券的成功订单可以部分退款');
        }
        $refundable = bcsub((string) ($order->deducted_amount ?? '0.00'), (string) $order->refunded_amount, 2);
        if (bccomp($amount, $refundable, 2) > 0) {
            throw new HttpException(422, '退款金额不能超过可退金额 ' . $refundable . ' 元');
        }
        $before = $this->formatOrder($order);

        if (! $this->orderRefundService->refundPartially($order, $amount, '部分退款：' . $remark, $adminUserId)) {
            throw new HttpException(409, '订单状态已变化，请刷新后重试');
        }

        $order->refresh();
        $this->operationLogDao->record($adminUserId, self::MODULE, 'partial_refund', 'order', $order->id, $before, $this->formatOrder($order) + [
            'amount' => $amount,
            'remark' => $remark,
        ], $ip);

        return $this->formatOrder($order);
    }

    /**
     * 卡密类卡券必须有供应商确认的成功结果（带卡密）才能置成功；其它订单按人工核实
     * 结果置成功，成本价沿用订单上的值。
     */
    private function manualSuccessResult(Order $order, ?string $supplierOrderNo): DriverResult
    {
        if (! $this->isCardSecretOrder($order)) {
            return new DriverResult(
                result: UnifiedResult::Success,
                supplierOrderNo: $supplierOrderNo ?? $order->supplier_order_no,
            );
        }

        try {
            $confirmed = $this->pollingService->queryLatestAttempt($order);
        } catch (Throwable $e) {
            throw new HttpException(502, '卡密类订单需要向供应商确认，查询失败：' . $e->getMessage(), 0, $e);
        }
        if ($confirmed === null || $confirmed->result !== UnifiedResult::Success || empty($confirmed->cardList)) {
            throw new HttpException(409, '卡密类订单只有供应商确认成功并返回卡密后才能置成功');
        }
        $order->refresh();

        return $confirmed;
    }

    private function isCardSecretOrder(Order $order): bool
    {
        if ($order->business_line !== 'card') {
            return false;
        }
        $recharge = $this->orderRechargeDao->find($order->id);
        $product = $recharge !== null ? $this->productDao->find((int) $recharge->product_id) : null;

        return $product?->card_type === 'card_secret';
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $query): array
    {
        $filters = [];

        foreach (['status' => self::STATUSES, 'business_line' => self::BUSINESS_LINES] as $key => $allowed) {
            $value = $query[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (! in_array($value, $allowed, true)) {
                throw new HttpException(422, $key . ' 不合法');
            }
            $filters[$key] = $value;
        }

        $merchantId = $query['merchant_id'] ?? null;
        if ($merchantId !== null && $merchantId !== '') {
            if (! is_numeric($merchantId) || (int) $merchantId <= 0) {
                throw new HttpException(422, 'merchant_id 不合法');
            }
            $filters['merchant_id'] = (int) $merchantId;
        }

        foreach (['order_no', 'merchant_order_no'] as $key) {
            $value = $query[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $filters[$key] = trim($value);
            }
        }

        foreach (['created_from', 'created_to'] as $key) {
            $value = $query[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $time = is_string($value) ? strtotime($value) : false;
            if ($time === false) {
                throw new HttpException(422, $key . ' 不是合法的时间');
            }
            $filters[$key] = date('Y-m-d H:i:s', $time);
        }

        return $filters;
    }

    private function findOrderOrFail(int $orderId): Order
    {
        $order = $this->orderDao->find($orderId);
        if (! $order instanceof Order) {
            throw new HttpException(404, '订单不存在');
        }

        return $order;
    }

    /**
     * 后台看到的订单字段：比商户多了成本价和供应商信息。
     *
     * @return array<string, mixed>
     */
    private function formatOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'merchant_id' => $order->merchant_id,
            'merchant_order_no' => $order->merchant_order_no,
            'business_line' => $order->business_line,
            'status' => $order->status,
            'sale_price' => $order->sale_price,
            'cost_price' => $order->cost_price,
            'frozen_amount' => $order->frozen_amount,
            'deducted_amount' => $order->deducted_amount,
            'refunded_amount' => $order->refunded_amount,
            'supplier_id' => $order->supplier_id,
            'supplier_order_no' => $order->supplier_order_no,
            'fail_reason' => $order->fail_reason,
            'callback_url' => $order->callback_url,
            'created_at' => $order->created_at?->toDateTimeString(),
            'completed_at' => $order->completed_at?->toDateTimeString(),
            'finished_at' => $order->finished_at?->toDateTimeString(),
        ];
    }

    /**
     * 成功订单查一次供应商，看是不是被退款了（全额自动处理、部分告警，见 SupplierRefundAfterSuccessService）。
     *
     * @param array<string, mixed> $before
     * @return array{result: string, supplier_order_no: null|string, fail_reason: null|string, order: array<string, mixed>}
     */
    private function checkRefundAfterSuccess(Order $order, array $before, int $adminUserId, ?string $ip): array
    {
        $attempt = $this->orderAttemptDao->findLatestForOrder((int) $order->id);
        $supplier = $attempt === null ? null : $this->supplierDao->find((int) $attempt->supplier_id);
        if ($attempt === null || $supplier === null) {
            throw new HttpException(409, '订单没有供应商尝试记录，无法查询');
        }

        try {
            $result = $this->supplierDriverFactory->build($supplier)
                ->queryOrder($order->order_no . '-' . $attempt->attempt_no, $order->business_line === 'card');
        } catch (Throwable $e) {
            throw new HttpException(502, '查询供应商失败：' . $e->getMessage(), 0, $e);
        }
        $outcome = $this->refundAfterSuccessService->handle($order, $result);
        $order->refresh();

        $response = [
            'result' => strtolower($result->result->name),
            'supplier_order_no' => $result->supplierOrderNo,
            'fail_reason' => $result->failReason,
            'refund_check' => $outcome,
            'order' => $this->formatOrder($order),
        ];
        $this->operationLogDao->record($adminUserId, self::MODULE, 'query_supplier', 'order', $order->id, $before, [
            'supplier_result' => $response['result'],
            'refund_check' => $outcome,
            'status' => $order->status,
        ], $ip);

        return $response;
    }

    /**
     * 快递明细，后台版：比商户看到的多寄收件人、各项成本（预估/冻结/实际运费成本）、费用调整原因。
     * `freight_sale_price` 是实际向商户收的运费，跟 `actual_freight`（成本）并排看就是这单运费的毛利。
     *
     * @return null|array<string, mixed>
     */
    private function formatExpress(Order $order): ?array
    {
        if ($order->business_line !== 'express') {
            return null;
        }
        $express = $this->orderExpressDao->findByOrderId((int) $order->id);
        if ($express === null) {
            return null;
        }

        return [
            'company_code' => $express->express_company_code,
            'company_name' => $express->express_company_name,
            'sender' => $express->sender_info,
            'receiver' => $express->receiver_info,
            'item' => $express->item_info,
            'weight' => $express->weight,
            'insured_amount' => $express->insured_amount,
            'waybill_no' => $express->waybill_no,
            'logistics_status' => $express->logistics_status,
            'estimated_freight' => $express->estimated_freight,
            'frozen_freight' => $express->frozen_freight,
            'actual_freight' => $express->actual_freight,
            'actual_insured_fee' => $express->actual_insured_fee,
            'actual_material_fee' => $express->actual_material_fee,
            'actual_reverse_fee' => $express->actual_reverse_fee,
            'freight_sale_price' => $express->freight_sale_price,
            'fee_over_at' => $express->fee_over_at?->toDateTimeString(),
            'signed_at' => $express->signed_at?->toDateTimeString(),
            'fee_adjustments' => $this->feeAdjustmentDao->listForOrder((int) $order->id)
                ->map(static fn (OrderExpressFeeAdjustment $adjustment) => [
                    'type' => $adjustment->type,
                    'item' => $adjustment->item,
                    'amount' => $adjustment->amount,
                    'reason' => $adjustment->reason,
                    'created_at' => $adjustment->created_at->toDateTimeString(),
                ])->values()->all(),
        ];
    }

    /**
     * 电影票明细，后台版：比商户看到的多每张成本和供应商返佣。取票码后台也显示——电影票没有卡密那样的
     * 敏感等级，客服帮商户查"票出了没有、码是多少"是最常见的问题。
     *
     * @return null|array<string, mixed>
     */
    private function formatMovie(Order $order): ?array
    {
        if ($order->business_line !== 'movie') {
            return null;
        }
        $movie = $this->orderMovieDao->findByOrderId((int) $order->id);
        if ($movie === null) {
            return null;
        }

        return [
            'cinema_id' => $movie->cinema_id,
            'cinema_name' => $movie->cinema_name,
            'film_id' => $movie->film_id,
            'film_name' => $movie->film_name,
            'show_id' => $movie->show_id,
            'show_time' => $movie->show_time->toDateTimeString(),
            'area_id' => $movie->area_id,
            'seats' => array_map(static fn (array $seat) => [
                'seat_code' => $seat['seat_code'],
                'row_label' => $seat['row_label'] ?? null,
                'col_label' => $seat['col_label'] ?? null,
                'love_status' => $seat['love_status'] ?? 0,
            ], $movie->seats),
            'seat_count' => $movie->seat_count,
            'unit_price' => $movie->unit_price,
            'unit_cost' => $movie->unit_cost,
            'mobile' => $movie->mobile,
            'lock_expire_at' => $movie->lock_expire_at->toDateTimeString(),
            'confirmed_at' => $movie->confirmed_at?->toDateTimeString(),
            'ticket_codes' => $movie->ticket_codes ?? [],
            'supplier_rebate' => $movie->supplier_rebate,
        ];
    }

    private function formatRecharge(Order $order): ?array
    {
        $recharge = $this->orderRechargeDao->find($order->id);
        if ($recharge === null) {
            return null;
        }

        return [
            'product_id' => $recharge->product_id,
            'recharge_account' => $recharge->recharge_account,
            'rebate_amount' => $recharge->rebate_amount,
            // 卡密只给商户（订单查询接口），后台只显示有没有拿到
            'has_card_secret' => $recharge->card_no !== null || $recharge->card_pwd !== null,
        ];
    }

    /**
     * @return null|array<string, mixed>
     */
    private function formatRebate(int $orderId): ?array
    {
        $rebate = $this->rebateDao->findByOrderId($orderId);
        if ($rebate === null) {
            return null;
        }

        return [
            'amount' => $rebate->amount,
            'status' => $rebate->status,
            'rebate_rate' => $rebate->rebate_rate,
            'due_at' => $rebate->due_at?->toDateTimeString(),
            'settled_at' => $rebate->settled_at?->toDateTimeString(),
        ];
    }
}
