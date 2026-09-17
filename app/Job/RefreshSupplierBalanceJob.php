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

use App\Service\Supplier\SupplierBalanceService;
use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;

/**
 * 立即刷新单个供应商的余额（requirements.md 6.7：供应商返回预存款不足时触发）。
 * 放到队列里做，不让一次余额查询拖慢正在进行的下单/回调请求。查询失败由
 * SupplierBalanceService 记日志，不重试，下一轮定时刷新会再查。
 */
class RefreshSupplierBalanceJob extends Job
{
    public function __construct(public readonly int $supplierId)
    {
    }

    public function handle(): void
    {
        ApplicationContext::getContainer()->get(SupplierBalanceService::class)->refreshById($this->supplierId);
    }
}
