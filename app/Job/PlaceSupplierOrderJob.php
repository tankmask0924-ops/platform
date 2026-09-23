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

namespace App\Job;

use App\Service\Order\SupplierOrderDispatcher;
use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;

/**
 * 话费、卡券订单调用供应商下单（requirements.md 9「异步处理」），逻辑见 App\Service\Order\SupplierOrderDispatcher::dispatch()。
 * 构造参数只放订单 id（Job 会被序列化进 Redis，同 NotifyMerchantJob）。
 *
 * **不靠队列重试**：dispatch() 本身幂等（订单已有尝试记录就跳过），但重试时机交给
 * App\Crontab\SupplierDispatchRecoveryCrontab 统一兜底，免得队列重试和兜底任务各自按自己的节奏再调一次。
 */
class PlaceSupplierOrderJob extends Job
{
    protected int $maxAttempts = 0;

    public function __construct(public readonly int $orderId)
    {
    }

    public function handle(): void
    {
        ApplicationContext::getContainer()->get(SupplierOrderDispatcher::class)->dispatch($this->orderId);
    }
}
