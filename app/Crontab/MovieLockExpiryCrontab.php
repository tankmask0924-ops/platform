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

use App\Service\OpenApi\MovieOrderService;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 电影票锁座超时释放座位并解冻（requirements.md 7.3），逻辑见 MovieOrderService::expireLocks()。
 */
#[Crontab(rule: '* * * * *', name: 'MovieLockExpiry', memo: '电影票锁座超时释放座位并解冻（requirements.md 7.3）', singleton: true, onOneServer: true)]
class MovieLockExpiryCrontab
{
    #[Inject]
    protected MovieOrderService $movieOrderService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function __invoke(): void
    {
        try {
            $this->movieOrderService->expireLocks();
        } catch (Throwable $e) {
            $this->loggerFactory->get('crontab')->error('[Crontab] MovieLockExpiry failed: ' . $e->getMessage());
        }
    }
}
