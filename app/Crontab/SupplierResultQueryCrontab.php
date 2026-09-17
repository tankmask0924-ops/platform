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

use App\Service\Order\SupplierResultPollingService;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 供应商结果定时查询，逻辑见 App\Service\Order\SupplierResultPollingService。
 * 调度靠已注册的 App\Process\CrontabDispatcherProcess。
 *
 * 每分钟一次；onOneServer 避免多台部署重复查询，singleton 避免一批查询没跑完
 * （供应商慢时最多约 BATCH_SIZE / 并发数 × 10 秒）下一次又开始。
 */
#[Crontab(rule: '* * * * *', name: 'SupplierResultQuery', memo: '处理中/结果未知订单定时查询供应商（requirements.md 6.2）', singleton: true, onOneServer: true)]
class SupplierResultQueryCrontab
{
    #[Inject]
    protected SupplierResultPollingService $pollingService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function __invoke(): void
    {
        try {
            $queried = $this->pollingService->pollDue();
            if ($queried > 0) {
                $this->loggerFactory->get('crontab')->info(sprintf('[Crontab] SupplierResultQuery: queried %d attempts', $queried));
            }
        } catch (Throwable $e) {
            $this->loggerFactory->get('crontab')->error('[Crontab] SupplierResultQuery failed: ' . $e->getMessage());
        }
    }
}
