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

use App\Dao\MerchantNotifyLogDao;
use App\Dao\OrderDao;
use App\Model\Merchant;
use App\Model\MerchantNotifyLog;
use App\Model\Order;
use App\OpenApi\ErrorCode;
use App\Service\AbstractService;
use App\Service\MerchantNotifyService;
use App\Service\OpenApi\OrderQueryService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户管理后台「订单管理」（requirements.md 8.2）：列表、详情（含回调记录）、手动重推回调。
 * 导出不在本次范围内（同"资金流水"一行的处理）。
 *
 * - 只查当前登录商户自己的订单，merchant_id 一律来自登录态。
 * - 商户看到的就是开放 API 给的那一份：不含供应商、成本价；异常单显示为处理中
 *   （Order::merchantFacingStatus()），按"处理中"筛选时异常单也包含在内；详情里的卡号
 *   卡密跟订单查询接口一样返回明文（App\Service\OpenApi\OrderQueryService）。
 * - 重推回调只允许已经有最终结果的订单；同一笔订单 60 秒内有过回调记录就拒绝，避免
 *   反复点击把队列刷满。
 */
class OrderService extends AbstractService
{
    public const RENOTIFY_COOLDOWN_SECONDS = 60;

    /**
     * 商户能筛选的状态 => 实际查询的状态。
     */
    private const STATUS_FILTERS = [
        'processing' => [Order::STATUS_PROCESSING, Order::STATUS_ABNORMAL],
        'success' => ['success'],
        'failed' => ['failed'],
        'cancelled' => ['cancelled'],
        'refunded' => ['refunded'],
    ];

    private const BUSINESS_LINES = ['recharge', 'card', 'movie', 'express'];

    private const NOTIFIABLE_STATUSES = ['success', 'failed', 'cancelled', 'refunded'];

    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderQueryService $orderQueryService;

    #[Inject]
    protected MerchantNotifyLogDao $notifyLogDao;

    #[Inject]
    protected MerchantNotifyService $merchantNotifyService;

    /**
     * @param array<string, mixed> $query 原始查询参数
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(Merchant $merchant, array $query): array
    {
        $filters = $this->normalizeFilters($query);
        $filters['merchant_id'] = (int) $merchant->id;
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 15)));

        return [
            'data' => $this->orderDao->paginateFiltered($filters, $page, $perPage)
                ->map(fn (Order $order) => $this->formatOrder($order))->values()->all(),
            'total' => $this->orderDao->countFiltered($filters),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Merchant $merchant, string $orderNo): array
    {
        $order = $this->findOwnOrderOrFail($merchant, $orderNo);
        $result = $this->orderQueryService->find($merchant, $orderNo, null);

        return $result + [
            'created_at' => $order->created_at?->toDateTimeString(),
            'notify_logs' => $this->notifyLogDao->findByOrderId($order->id)
                ->map(static fn (MerchantNotifyLog $log) => [
                    'attempt_no' => $log->attempt_no,
                    'url' => $log->url,
                    'http_status' => $log->http_status,
                    'success' => (bool) $log->success,
                    'response_body' => $log->response_body,
                    'created_at' => $log->created_at?->toDateTimeString(),
                ])->values()->all(),
        ];
    }

    public function renotify(Merchant $merchant, string $orderNo): void
    {
        $order = $this->findOwnOrderOrFail($merchant, $orderNo);
        if (! in_array($order->status, self::NOTIFIABLE_STATUSES, true)) {
            throw new HttpException(409, '订单还没有最终结果，不能重推回调');
        }

        $latest = $this->notifyLogDao->findByOrderId($order->id)->first();
        if ($latest !== null && $latest->created_at !== null
            && $latest->created_at->getTimestamp() > time() - self::RENOTIFY_COOLDOWN_SECONDS) {
            throw new HttpException(429, '回调刚发送过，请稍后再试');
        }

        $this->merchantNotifyService->notify($order->id);
    }

    private function findOwnOrderOrFail(Merchant $merchant, string $orderNo): Order
    {
        $order = $this->orderDao->findByOrderNoForMerchant((int) $merchant->id, $orderNo);
        if ($order === null) {
            throw new HttpException(404, '订单不存在');
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOrder(Order $order): array
    {
        return [
            'order_no' => $order->order_no,
            'merchant_order_no' => $order->merchant_order_no,
            'business_line' => $order->business_line,
            'status' => $order->merchantFacingStatus(),
            'sale_price' => $order->sale_price,
            'frozen_amount' => $order->frozen_amount,
            'deducted_amount' => $order->deducted_amount,
            'refunded_amount' => $order->refunded_amount,
            'created_at' => $order->created_at?->toDateTimeString(),
            'completed_at' => $order->completed_at?->toDateTimeString(),
        ] + ErrorCode::presentOrderFailure($order->fail_reason);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $query): array
    {
        $filters = [];

        $status = $query['status'] ?? null;
        if ($status !== null && $status !== '') {
            if (! is_string($status) || ! isset(self::STATUS_FILTERS[$status])) {
                throw new HttpException(422, 'status 不合法');
            }
            $filters['status'] = self::STATUS_FILTERS[$status];
        }

        $businessLine = $query['business_line'] ?? null;
        if ($businessLine !== null && $businessLine !== '') {
            if (! in_array($businessLine, self::BUSINESS_LINES, true)) {
                throw new HttpException(422, 'business_line 不合法');
            }
            $filters['business_line'] = $businessLine;
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
}
