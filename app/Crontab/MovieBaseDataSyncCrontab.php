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

use App\Service\Movie\MovieBaseDataSyncService;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 电影票城市/区县/影院全量同步（requirements.md 7.3），逻辑见 MovieBaseDataSyncService::syncAll()。
 */
#[Crontab(rule: '30 4 * * *', name: 'MovieBaseDataSync', memo: '电影票城市/区县/影院全量同步（requirements.md 7.3）', singleton: true, onOneServer: true)]
class MovieBaseDataSyncCrontab
{
    #[Inject]
    protected MovieBaseDataSyncService $syncService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function __invoke(): void
    {
        try {
            $this->syncService->syncAll();
        } catch (Throwable $e) {
            $this->loggerFactory->get('crontab')->error('[Crontab] MovieBaseDataSync failed: ' . $e->getMessage());
        }
    }
}
