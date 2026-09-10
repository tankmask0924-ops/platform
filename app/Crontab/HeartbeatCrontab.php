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

use Hyperf\Context\ApplicationContext;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

#[Crontab(rule: '* * * * *', name: 'Heartbeat', memo: '示例定时任务：每分钟跑一次', onOneServer: true)]
class HeartbeatCrontab
{
    public function __invoke(): void
    {
        $this->logger()->info('[Crontab] Heartbeat at ' . date('Y-m-d H:i:s'));
    }

    protected function logger(): LoggerInterface
    {
        return ApplicationContext::getContainer()
            ->get(LoggerFactory::class)
            ->get('crontab');
    }
}
