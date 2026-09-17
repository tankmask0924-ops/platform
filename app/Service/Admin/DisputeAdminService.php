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
use App\Dao\AftersaleDisputeDao;
use App\Dao\MerchantRebateDao;
use App\Dao\OrderDao;
use App\Model\AftersaleDispute;
use App\Model\Order;
use App\Service\AbstractService;
use App\Service\Order\OrderRefundService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统管理后台「售后处理：话费卡券争议处理」（requirements.md 7.7、8.3）。
 *
 * - 驳回（确认已到账）：必须附凭证和说明，返佣恢复正常到账。
 * - 确认未到账：走 OrderRefundService 全额退款（订单改已退款、扣款退回、返佣作废或扣回、
 *   通知商户），争议状态跟退款在同一个事务里提交。
 * - 只能处理"处理中"的争议；两个客服同时处理时后到的返回 409。
 * - 都写操作日志（module = aftersale）。
 * - 不含：通过供应商售后接口提交核实（卡速售驱动还没实现售后接口）。
 */
class DisputeAdminService extends AbstractService
{
    public const MODULE = 'aftersale';

    private const STATUSES = [AftersaleDispute::STATUS_PROCESSING, AftersaleDispute::STATUS_REJECTED, AftersaleDispute::STATUS_CONFIRMED];

    private const MAX_PER_PAGE = 100;

    private const MAX_EVIDENCE_ITEMS = 20;

    private const MAX_EVIDENCE_ITEM_LENGTH = 2000;

    #[Inject]
    protected AftersaleDisputeDao $disputeDao;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected MerchantRebateDao $rebateDao;

    #[Inject]
    protected OrderRefundService $orderRefundService;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $query): array
    {
        $filters = [];
        $status = $query['status'] ?? null;
        if ($status !== null && $status !== '') {
            if (! in_array($status, self::STATUSES, true)) {
                throw new HttpException(422, 'status 不合法');
            }
            $filters['status'] = $status;
        }
        $merchantId = $query['merchant_id'] ?? null;
        if ($merchantId !== null && $merchantId !== '') {
            if (! is_numeric($merchantId) || (int) $merchantId <= 0) {
                throw new HttpException(422, 'merchant_id 不合法');
            }
            $filters['merchant_id'] = (int) $merchantId;
        }
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 15)));

        return [
            'data' => $this->disputeDao->paginateFiltered($filters, $page, $perPage)
                ->map(fn (AftersaleDispute $dispute) => $this->format($dispute))
                ->values()->all(),
            'total' => $this->disputeDao->countFiltered($filters),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(int $disputeId): array
    {
        $dispute = $this->findOrFail($disputeId);
        $rebate = $this->rebateDao->findByOrderId((int) $dispute->order_id);

        return $this->format($dispute) + [
            'rebate' => $rebate === null ? null : [
                'amount' => $rebate->amount,
                'status' => $rebate->status,
                'due_at' => $rebate->due_at?->toDateTimeString(),
            ],
        ];
    }

    /**
     * 驳回：供应商确认已到账。
     *
     * @return array<string, mixed>
     */
    public function reject(int $disputeId, mixed $remark, mixed $evidence, int $adminUserId, ?string $ip): array
    {
        $remark = $this->normalizeRemark($remark);
        $evidence = $this->normalizeEvidence($evidence, required: true);

        $dispute = Db::transaction(function () use ($disputeId, $remark, $evidence, $adminUserId) {
            $dispute = $this->lockProcessingOrFail($disputeId);
            $dispute->fill($this->resolution(AftersaleDispute::STATUS_REJECTED, $remark, $evidence, $adminUserId))->save();

            return $dispute;
        });

        $this->log('reject_dispute', $dispute, $adminUserId, $ip);

        return $this->format($dispute);
    }

    /**
     * 确认未到账：全额退款。
     *
     * @return array<string, mixed>
     */
    public function confirm(int $disputeId, mixed $remark, mixed $evidence, int $adminUserId, ?string $ip): array
    {
        $remark = $this->normalizeRemark($remark);
        $evidence = $this->normalizeEvidence($evidence, required: false);

        $snapshot = $this->findOrFail($disputeId);
        if ($snapshot->status !== AftersaleDispute::STATUS_PROCESSING) {
            throw new HttpException(409, '只能处理处理中的争议');
        }
        $order = $this->orderDao->find((int) $snapshot->order_id);
        if (! $order instanceof Order) {
            throw new HttpException(409, '争议对应的订单不存在');
        }

        $dispute = null;
        $refunded = $this->orderRefundService->refundUndelivered(
            $order,
            '售后核实未到账（争议 #' . $disputeId . '）',
            $adminUserId,
            function () use ($disputeId, $remark, $evidence, $adminUserId, &$dispute) {
                $dispute = $this->lockProcessingOrFail($disputeId);
                $dispute->fill($this->resolution(AftersaleDispute::STATUS_CONFIRMED, $remark, $evidence, $adminUserId))->save();
            },
        );
        if (! $refunded) {
            throw new HttpException(409, '订单已经不是成功状态，无法退款，请刷新后重试');
        }

        $this->log('confirm_dispute', $dispute, $adminUserId, $ip);

        return $this->format($dispute);
    }

    private function lockProcessingOrFail(int $disputeId): AftersaleDispute
    {
        $dispute = $this->disputeDao->lockForUpdate($disputeId);
        if ($dispute === null) {
            throw new HttpException(404, '争议不存在');
        }
        if ($dispute->status !== AftersaleDispute::STATUS_PROCESSING) {
            throw new HttpException(409, '只能处理处理中的争议');
        }

        return $dispute;
    }

    private function findOrFail(int $disputeId): AftersaleDispute
    {
        $dispute = $this->disputeDao->find($disputeId);
        if ($dispute === null) {
            throw new HttpException(404, '争议不存在');
        }

        return $dispute;
    }

    /**
     * @param null|list<string> $evidence
     * @return array<string, mixed>
     */
    private function resolution(string $status, string $remark, ?array $evidence, int $adminUserId): array
    {
        return [
            'status' => $status,
            'result_remark' => $remark,
            'evidence' => $evidence,
            'handler_id' => $adminUserId,
            'resolved_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function normalizeRemark(mixed $remark): string
    {
        $remark = is_string($remark) ? trim($remark) : '';
        if ($remark === '' || mb_strlen($remark) > 255) {
            throw new HttpException(422, 'remark 不能为空，且不超过 255 个字符');
        }

        return $remark;
    }

    /**
     * 凭证是文本或图片地址的列表（供应商查询结果、截图链接等）。
     *
     * @return null|list<string>
     */
    private function normalizeEvidence(mixed $evidence, bool $required): ?array
    {
        if ($evidence === null || $evidence === [] || $evidence === '') {
            if ($required) {
                throw new HttpException(422, '驳回必须附上凭证 evidence');
            }

            return null;
        }
        if (! is_array($evidence) || ! array_is_list($evidence) || count($evidence) > self::MAX_EVIDENCE_ITEMS) {
            throw new HttpException(422, 'evidence 必须是不超过 ' . self::MAX_EVIDENCE_ITEMS . ' 项的字符串列表');
        }
        foreach ($evidence as $item) {
            if (! is_string($item) || trim($item) === '' || mb_strlen($item) > self::MAX_EVIDENCE_ITEM_LENGTH) {
                throw new HttpException(422, 'evidence 每一项必须是非空、不超过 ' . self::MAX_EVIDENCE_ITEM_LENGTH . ' 个字符的字符串');
            }
        }

        return array_map('trim', $evidence);
    }

    private function log(string $action, AftersaleDispute $dispute, int $adminUserId, ?string $ip): void
    {
        $this->operationLogDao->record($adminUserId, self::MODULE, $action, 'aftersale_dispute', (int) $dispute->id, [
            'status' => AftersaleDispute::STATUS_PROCESSING,
        ], [
            'status' => $dispute->status,
            'order_id' => $dispute->order_id,
            'result_remark' => $dispute->result_remark,
        ], $ip);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(AftersaleDispute $dispute): array
    {
        $order = $this->orderDao->find((int) $dispute->order_id);

        return [
            'id' => $dispute->id,
            'merchant_id' => $dispute->merchant_id,
            'order_id' => $dispute->order_id,
            'order_no' => $order?->order_no,
            'business_line' => $order?->business_line,
            'order_status' => $order?->status,
            'sale_price' => $order?->sale_price,
            'deducted_amount' => $order?->deducted_amount,
            'refunded_amount' => $order?->refunded_amount,
            'completed_at' => $order?->completed_at?->toDateTimeString(),
            'status' => $dispute->status,
            'result_remark' => $dispute->result_remark,
            'evidence' => $dispute->evidence,
            'handler_id' => $dispute->handler_id,
            'submitted_at' => $dispute->submitted_at?->toDateTimeString(),
            'resolved_at' => $dispute->resolved_at?->toDateTimeString(),
        ];
    }
}
