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

use App\Service\Order\ExpressOrderSettlementService;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 快递完成兜底：扣费后超过兜底天数仍未签收的订单补上完成时间（requirements.md 7.2、7.5），
 * 逻辑见 ExpressOrderSettlementService::completeOverdue()。以天计，每小时扫一次足够。
 */
#[Crontab(rule: '17 * * * *', name: 'ExpressCompletion', memo: '快递扣费后超过兜底天数自动完成（requirements.md 7.2）', singleton: true, onOneServer: true)]
class ExpressCompletionCrontab
{
    #[Inject]
    protected ExpressOrderSettlementService $settlementService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function __invoke(): void
    {
        try {
            $this->settlementService->completeOverdue();
        } catch (Throwable $e) {
            $this->loggerFactory->get('crontab')->error('[Crontab] ExpressCompletion failed: ' . $e->getMessage());
        }
    }
}
