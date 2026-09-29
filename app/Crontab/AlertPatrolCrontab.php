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

use App\Service\Alert\AlertPatrolService;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 告警巡检（商户欠款超预警线、异常单积压），逻辑见 App\Service\Alert\AlertPatrolService。
 * 每 5 分钟一次，跟异常单标记同频：刚标出一批异常单，下一轮就能看到积压。
 */
#[Crontab(rule: '*/5 * * * *', name: 'AlertPatrol', memo: '欠款超预警线、异常单积压告警巡检（requirements.md 8.3）', singleton: true, onOneServer: true)]
class AlertPatrolCrontab
{
    #[Inject]
    protected AlertPatrolService $alertPatrolService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function __invoke(): void
    {
        try {
            $this->alertPatrolService->patrol();
        } catch (Throwable $e) {
            $this->loggerFactory->get('crontab')->error('[Crontab] AlertPatrol failed: ' . $e->getMessage());
        }
    }
}
