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

namespace App\Service\Merchant;

use App\Dao\AftersaleDisputeDao;
use App\Dao\OrderDao;
use App\Dao\SystemSettingDao;
use App\Model\AftersaleDispute;
use App\Model\Merchant;
use App\Model\Order;
use App\Service\AbstractService;
use Hyperf\Database\Exception\QueryException;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户管理后台「售后：未到账争议提交与查看」（requirements.md 7.7、8.2）。
 *
 * - 只接受话费、卡券的成功订单，且在订单成功后争议时限内（system_settings.
 *   dispute_deadline_days，默认 7 天，从 completed_at 起算）。
 * - 一笔订单只能提交一次（表上 order_id 唯一），被驳回后不能再次提交。
 * - 提交后该订单返佣暂停到账（MerchantRebateDao::findDuePending()），客服在系统管理后台
 *   处理（App\Service\Admin\DisputeAdminService），处理结果和凭证商户可以查看。
 */
class DisputeService extends AbstractService
{
    public const DEADLINE_SETTING_KEY = 'dispute_deadline_days';

    public const DEFAULT_DEADLINE_DAYS = 7;

    private const BUSINESS_LINES = ['recharge', 'card'];

    private const STATUSES = [AftersaleDispute::STATUS_PROCESSING, AftersaleDispute::STATUS_REJECTED, AftersaleDispute::STATUS_CONFIRMED];

    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected AftersaleDisputeDao $disputeDao;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected SystemSettingDao $systemSettingDao;

    /**
     * @return array<string, mixed>
     */
    public function submit(Merchant $merchant, mixed $orderNo): array
    {
        if (! is_string($orderNo) || trim($orderNo) === '') {
            throw new HttpException(422, 'order_no 不能为空');
        }

        $order = $this->orderDao->findByOrderNoForMerchant((int) $merchant->id, trim($orderNo));
        if ($order === null) {
            throw new HttpException(404, '订单不存在');
        }
        if (! in_array($order->business_line, self::BUSINESS_LINES, true)) {
            throw new HttpException(422, '只有话费、卡券订单可以提交未到账争议');
        }
        if ($order->status !== 'success' || $order->completed_at === null) {
            throw new HttpException(422, '只有成功的订单可以提交未到账争议');
        }
        $deadlineDays = $this->deadlineDays();
        if ($order->completed_at->copy()->addDays($deadlineDays)->getTimestamp() < time()) {
            throw new HttpException(422, "已超过订单成功后 {$deadlineDays} 天的争议时限");
        }
        if ($this->disputeDao->findByOrderId((int) $order->id) !== null) {
            throw new HttpException(409, '该订单已经提交过争议');
        }

        try {
            $dispute = $this->disputeDao->create([
                'order_id' => $order->id,
                'merchant_id' => $merchant->id,
                'status' => AftersaleDispute::STATUS_PROCESSING,
                'submitted_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (QueryException $e) {
            // order_id 唯一索引：并发重复提交
            throw new HttpException(409, '该订单已经提交过争议', 0, $e);
        }

        return $this->format($dispute, $order);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(Merchant $merchant, array $query): array
    {
        $filters = ['merchant_id' => (int) $merchant->id];
        $status = $query['status'] ?? null;
        if ($status !== null && $status !== '') {
            if (! in_array($status, self::STATUSES, true)) {
                throw new HttpException(422, 'status 不合法');
            }
            $filters['status'] = $status;
        }
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 15)));

        return [
            'data' => $this->disputeDao->paginateFiltered($filters, $page, $perPage)
                ->map(fn (AftersaleDispute $dispute) => $this->format($dispute, $this->orderDao->find((int) $dispute->order_id)))
                ->values()->all(),
            'total' => $this->disputeDao->countFiltered($filters),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Merchant $merchant, int $disputeId): array
    {
        $dispute = $this->disputeDao->findForMerchant((int) $merchant->id, $disputeId);
        if ($dispute === null) {
            throw new HttpException(404, '争议不存在');
        }

        return $this->format($dispute, $this->orderDao->find((int) $dispute->order_id));
    }

    public function deadlineDays(): int
    {
        $value = $this->systemSettingDao->getValue(self::DEADLINE_SETTING_KEY, self::DEFAULT_DEADLINE_DAYS);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_DEADLINE_DAYS;
    }

    /**
     * 商户看到的争议：不含处理人。
     *
     * @return array<string, mixed>
     */
    private function format(AftersaleDispute $dispute, ?Order $order): array
    {
        return [
            'id' => $dispute->id,
            'order_no' => $order?->order_no,
            'merchant_order_no' => $order?->merchant_order_no,
            'business_line' => $order?->business_line,
            'sale_price' => $order?->sale_price,
            'order_status' => $order?->merchantFacingStatus(),
            'status' => $dispute->status,
            'result_remark' => $dispute->result_remark,
            'evidence' => $dispute->evidence,
            'submitted_at' => $dispute->submitted_at?->toDateTimeString(),
            'resolved_at' => $dispute->resolved_at?->toDateTimeString(),
        ];
    }
}
