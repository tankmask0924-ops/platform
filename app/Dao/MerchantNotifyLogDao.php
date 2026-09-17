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

namespace App\Dao;

use App\Model\MerchantNotifyLog;
use Hyperf\Database\Model\Collection;

class MerchantNotifyLogDao extends AbstractDao
{
    protected string $model = MerchantNotifyLog::class;

    /**
     * 记一条回调尝试的日志。created_at 由调用方（App\Job\NotifyMerchantJob）传入，
     * 不依赖 Eloquent 的自动时间戳（Model 里关掉了，见 MerchantNotifyLog 类注释）。
     */
    public function log(
        int $orderId,
        string $url,
        array $payload,
        ?string $responseBody,
        ?int $httpStatus,
        int $attemptNo,
        bool $success
    ): MerchantNotifyLog {
        /** @var MerchantNotifyLog $log */
        return $this->create([
            'order_id' => $orderId,
            'url' => $url,
            'payload' => $payload,
            'response_body' => $responseBody,
            'http_status' => $httpStatus,
            'attempt_no' => $attemptNo,
            'success' => $success,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 按订单查回调记录，按时间倒序（系统管理后台、商户后台的订单详情用）。一笔订单
     * 最多 7 次自动尝试加上手动重推，不分页。
     */
    public function findByOrderId(int $orderId): Collection
    {
        return $this->newQuery()
            ->where('order_id', $orderId)
            ->orderByDesc('created_at')
            ->get();
    }
}
