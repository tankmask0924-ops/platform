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

namespace App\Crontab;

use App\Service\Supplier\CircuitBreakerService;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 熔断到期恢复的收尾（requirements.md 6.6「暂停期满自动恢复」）。
 *
 * **这个任务不是恢复机制本身**：路由那边判断"还在不在熔断中"直接看 `paused_until`
 * 有没有过（`SupplierCircuitBreaker::isPausedNow()`），所以暂停 5 分钟就是 5 分钟，
 * 不会因为定时任务一分钟一跑而多停几十秒。这个任务只是把过期的行写回 `normal`，
 * 让后台「熔断状态」列表和日志如实反映当前状态；就算它整个没跑，路由行为也完全正确，
 * 只是后台会看到一堆"已过期但还标着 paused"的行。
 *
 * 一分钟一次：暂停时长最小可以配到 1 分钟，再稀就会出现"已经恢复了但列表里还显示
 * 熔断中"的时间比暂停时长本身还长。
 */
#[Crontab(rule: '* * * * *', name: 'CircuitBreakerRecovery', memo: '熔断到期恢复收尾（requirements.md 6.6）', singleton: true, onOneServer: true)]
class CircuitBreakerRecoveryCrontab
{
    #[Inject]
    protected CircuitBreakerService $circuitBreakerService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function __invoke(): void
    {
        try {
            $this->circuitBreakerService->resumeExpired();
        } catch (Throwable $e) {
            $this->loggerFactory->get('crontab')->error('[Crontab] CircuitBreakerRecovery failed: ' . $e->getMessage());
        }
    }
}
