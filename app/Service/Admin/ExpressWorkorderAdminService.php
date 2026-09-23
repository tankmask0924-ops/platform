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
use App\Dao\ExpressWorkorderDao;
use App\Dao\OrderDao;
use App\Dao\SupplierDao;
use App\Model\ExpressWorkorder;
use App\Model\Order;
use App\Service\AbstractService;
use App\Service\Merchant\BalanceService;
use App\Supplier\SupplierDriverFactory;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Throwable;

/**
 * 系统后台「售后处理：快递工单代提交与跟踪」（requirements.md 7.2、8.3，yunyang.md 第 1 节「售后」）。
 * 快递售后不对商户开放，商户找平台客服，客服在订单详情代提交云洋工单。
 *
 * - **提交**：订单必须是快递、已经有云洋单号（`orders.supplier_order_no`），且还没有失败/取消/退款；
 *   同一笔订单同一类型已有处理中的工单时不能再提（409），重复提交只会让云洋那边多一张单。
 *   云洋明确拒绝 → 422 带原因、不建工单；网络失败/响应解析不了 → 502、不建工单，提示客服先去云洋后台
 *   确认是否已受理（工单也没有防重复参数，不能盲目重提）。
 * - **跟踪**：云洋工单回调（App\Service\Order\ExpressWorkorderCallbackService）**没有签名**，
 *   只把"供应商说了什么"（回复、金额）记在工单上给客服看，并触发一次带签名的订单查询：重量核实、
 *   状态异常退回的运费会体现在订单运费里，由快递结算按费用调整自动退给商户（requirements.md 7.2），
 *   这里不另外动钱。
 * - **结单**：客服核实后手动「完成」或「驳回」，都要写处理结果。**理赔款**（`type=claim`）在完成时
 *   填核实的实际赔付金额，按调账加到商户可用余额（yunyang.md 第 5 节「理赔款客服核实后调账」），
 *   跟状态更新在同一个事务里；带状态条件更新，两个客服同时结单时后一个 409，不会调两次账。
 * - 都写操作日志（module = aftersale），权限沿用售后争议的 `aftersale.view` / `aftersale.handle`。
 */
class ExpressWorkorderAdminService extends AbstractService
{
    public const MODULE = 'aftersale';

    /** 已经没有售后可做的订单状态 */
    private const CLOSED_ORDER_STATUSES = [Order::STATUS_FAILED, 'cancelled', 'refunded'];

    private const MAX_PER_PAGE = 100;

    private const MAX_CLAIM_AMOUNT = '99999.99';

    #[Inject]
    protected ExpressWorkorderDao $workorderDao;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected BalanceService $balanceService;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @param array<string, mixed> $input type / content
     * @return array<string, mixed>
     */
    public function submit(int $orderId, array $input, int $adminUserId, ?string $ip): array
    {
        $type = $input['type'] ?? null;
        if (! is_string($type) || ! in_array($type, ExpressWorkorder::TYPES, true)) {
            throw new HttpException(422, 'type 不合法');
        }
        $content = is_string($input['content'] ?? null) ? trim($input['content']) : '';
        if ($content === '' || mb_strlen($content) > 500) {
            throw new HttpException(422, '请填写工单内容（最多 500 字）');
        }

        $order = $this->orderDao->find($orderId);
        if ($order === null) {
            throw new HttpException(404, '订单不存在');
        }
        if ($order->business_line !== 'express') {
            throw new HttpException(409, '只有快递订单可以提交工单');
        }
        if (in_array($order->status, self::CLOSED_ORDER_STATUSES, true)) {
            throw new HttpException(409, '订单已经失败、取消或退款，不能再提交工单');
        }
        if ($order->supplier_order_no === null || $order->supplier_order_no === '' || $order->supplier_id === null) {
            throw new HttpException(409, '订单还没有云洋单号（下单结果未知），请先到云洋后台核实');
        }
        if ($this->workorderDao->hasProcessing((int) $order->id, $type)) {
            throw new HttpException(409, '这笔订单已有同类型的工单在处理中');
        }
        $supplier = $this->supplierDao->find((int) $order->supplier_id);
        if ($supplier === null) {
            throw new HttpException(409, '订单的供应商不存在');
        }

        try {
            $outcome = $this->supplierDriverFactory->buildYunyang($supplier)->submitWorkOrder($order->supplier_order_no, $type, $content);
        } catch (Throwable $e) {
            throw new HttpException(502, '提交云洋工单失败：' . $e->getMessage(), 0, $e);
        }
        if ($outcome['unknown']) {
            throw new HttpException(502, '云洋没有返回明确结果（' . $outcome['message'] . '），请先到云洋后台确认是否已受理，确认没有再重新提交');
        }
        if (! $outcome['accepted']) {
            throw new HttpException(422, '云洋拒绝了工单：' . $outcome['message']);
        }

        $workorder = $this->workorderDao->create([
            'order_id' => $order->id,
            'supplier_id' => $supplier->id,
            'type' => $type,
            'status' => ExpressWorkorder::STATUS_PROCESSING,
            'content' => $content,
            'supplier_workorder_no' => $outcome['workorder_no'],
            'submitted_by' => $adminUserId,
        ]);

        $this->operationLogDao->record($adminUserId, self::MODULE, 'submit_workorder', 'order', (int) $order->id, null, [
            'workorder_id' => $workorder->id,
            'type' => $type,
            'supplier_workorder_no' => $outcome['workorder_no'],
        ], $ip);

        return $this->format($workorder, $order->order_no);
    }

    /**
     * @param array<string, mixed> $query status / type / order_no / replied（1 有供应商回复 / 0 没有）/ page / per_page
     * @return array<string, mixed>
     */
    public function list(array $query): array
    {
        $filters = [];
        foreach (['status' => ExpressWorkorder::STATUSES, 'type' => ExpressWorkorder::TYPES] as $key => $allowed) {
            $value = $query[$key] ?? '';
            if ($value === '' || $value === null) {
                continue;
            }
            if (! in_array($value, $allowed, true)) {
                throw new HttpException(422, $key . ' 不合法');
            }
            $filters[$key] = $value;
        }
        if (isset($query['replied']) && $query['replied'] !== '') {
            $filters['replied'] = in_array($query['replied'], ['1', 1, true, 'true'], true);
        }
        $orderNo = is_string($query['order_no'] ?? null) ? trim($query['order_no']) : '';
        if ($orderNo !== '') {
            $order = $this->orderDao->findByOrderNo($orderNo);
            // 查不到的单号返回空列表，不是报错
            $filters['order_id'] = $order === null ? 0 : (int) $order->id;
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 15)));
        $workorders = $this->workorderDao->paginateFiltered($filters, $page, $perPage);
        $orderNos = $workorders->isEmpty() ? collect() : $this->orderDao->newQuery()
            ->whereIn('id', $workorders->pluck('order_id')->unique()->all())->pluck('order_no', 'id');

        return [
            'data' => $workorders->map(fn (ExpressWorkorder $w) => $this->format($w, $orderNos->get($w->order_id)))->values()->all(),
            'total' => $this->workorderDao->countFiltered($filters),
            'page' => $page,
            'per_page' => $perPage,
            'processing_count' => $this->workorderDao->countProcessing(),
        ];
    }

    /**
     * 订单详情里的工单列表。
     *
     * @return list<array<string, mixed>>
     */
    public function forOrder(Order $order): array
    {
        return $this->workorderDao->listForOrder((int) $order->id)
            ->map(fn (ExpressWorkorder $w) => $this->format($w, $order->order_no))->values()->all();
    }

    /**
     * 完成。理赔工单可以带 `claim_amount`（核实的实际赔付），按调账加到商户可用余额。
     *
     * @param array<string, mixed> $input result_remark / claim_amount
     * @return array<string, mixed>
     */
    public function complete(int $id, array $input, int $adminUserId, ?string $ip): array
    {
        $workorder = $this->findOrFail($id);
        $remark = $this->remark($input);
        $claimAmount = $this->claimAmount($workorder, $input['claim_amount'] ?? null);
        $order = $this->orderDao->find($workorder->order_id);
        if ($order === null) {
            throw new HttpException(409, '工单对应的订单不存在');
        }

        Db::transaction(function () use ($workorder, $order, $remark, $claimAmount, $adminUserId) {
            $resolved = $this->workorderDao->resolveIfProcessing((int) $workorder->id, [
                'status' => ExpressWorkorder::STATUS_COMPLETED,
                'result_remark' => $remark,
                'claim_amount' => $claimAmount,
                'resolved_by' => $adminUserId,
                'resolved_at' => date('Y-m-d H:i:s'),
            ]);
            if (! $resolved) {
                throw new HttpException(409, '工单已经处理过了');
            }
            if ($claimAmount !== null) {
                $this->balanceService->adjust(
                    (int) $order->merchant_id,
                    $claimAmount,
                    sprintf('快递理赔：订单 %s 工单 #%d', $order->order_no, $workorder->id),
                    $adminUserId
                );
            }
        });

        $this->operationLogDao->record($adminUserId, self::MODULE, 'complete_workorder', 'order', (int) $order->id, null, [
            'workorder_id' => $workorder->id,
            'claim_amount' => $claimAmount,
            'result_remark' => $remark,
        ], $ip);

        return $this->format($workorder->refresh(), $order->order_no);
    }

    /**
     * @param array<string, mixed> $input result_remark
     * @return array<string, mixed>
     */
    public function reject(int $id, array $input, int $adminUserId, ?string $ip): array
    {
        $workorder = $this->findOrFail($id);
        $remark = $this->remark($input);

        if (! $this->workorderDao->resolveIfProcessing((int) $workorder->id, [
            'status' => ExpressWorkorder::STATUS_REJECTED,
            'result_remark' => $remark,
            'resolved_by' => $adminUserId,
            'resolved_at' => date('Y-m-d H:i:s'),
        ])) {
            throw new HttpException(409, '工单已经处理过了');
        }

        $this->operationLogDao->record($adminUserId, self::MODULE, 'reject_workorder', 'order', (int) $workorder->order_id, null, [
            'workorder_id' => $workorder->id,
            'result_remark' => $remark,
        ], $ip);

        return $this->format($workorder->refresh(), $this->orderDao->find($workorder->order_id)?->order_no);
    }

    private function findOrFail(int $id): ExpressWorkorder
    {
        $workorder = $this->workorderDao->find($id);
        if (! $workorder instanceof ExpressWorkorder) {
            throw new HttpException(404, '工单不存在');
        }

        return $workorder;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function remark(array $input): string
    {
        $remark = is_string($input['result_remark'] ?? null) ? trim($input['result_remark']) : '';
        if ($remark === '' || mb_strlen($remark) > 255) {
            throw new HttpException(422, '请填写处理结果（最多 255 字）');
        }

        return $remark;
    }

    /**
     * 只有理赔工单能带金额；不填或 0 表示没有赔付（例如核实下来不赔）。
     */
    private function claimAmount(ExpressWorkorder $workorder, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($workorder->type !== ExpressWorkorder::TYPE_CLAIM) {
            throw new HttpException(422, '只有理赔工单可以填写理赔金额');
        }
        $value = is_int($value) || is_float($value) ? (string) $value : $value;
        if (! is_string($value) || ! preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw new HttpException(422, '理赔金额格式不对，最多两位小数');
        }
        if (bccomp($value, '0', 2) === 0) {
            return null;
        }
        if (bccomp($value, self::MAX_CLAIM_AMOUNT, 2) > 0) {
            throw new HttpException(422, '理赔金额过大');
        }

        return bcadd($value, '0', 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(ExpressWorkorder $workorder, ?string $orderNo): array
    {
        return [
            'id' => (int) $workorder->id,
            'order_id' => (int) $workorder->order_id,
            'order_no' => $orderNo,
            'type' => $workorder->type,
            'status' => $workorder->status,
            'content' => $workorder->content,
            'supplier_workorder_no' => $workorder->supplier_workorder_no,
            'submitted_by' => (int) $workorder->submitted_by,
            'supplier_reply' => $workorder->supplier_reply,
            'supplier_amount' => $workorder->supplier_amount === null ? null : (string) $workorder->supplier_amount,
            'supplier_replied_at' => $workorder->supplier_replied_at?->toDateTimeString(),
            'result_remark' => $workorder->result_remark,
            'claim_amount' => $workorder->claim_amount === null ? null : (string) $workorder->claim_amount,
            'resolved_by' => $workorder->resolved_by,
            'resolved_at' => $workorder->resolved_at?->toDateTimeString(),
            'created_at' => $workorder->created_at?->toDateTimeString(),
        ];
    }
}
