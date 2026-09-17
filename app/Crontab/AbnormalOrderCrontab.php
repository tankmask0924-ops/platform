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

use App\Service\Order\AbnormalOrderService;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 异常单标记，逻辑见 App\Service\Order\AbnormalOrderService。异常单时长以小时计，
 * 每 5 分钟扫一次足够；onOneServer 避免多台部署重复扫描。
 */
#[Crontab(rule: '*/5 * * * *', name: 'AbnormalOrder', memo: '处理中超时订单标记为异常单（requirements.md 7.1）', singleton: true, onOneServer: true)]
class AbnormalOrderCrontab
{
    #[Inject]
    protected AbnormalOrderService $abnormalOrderService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function __invoke(): void
    {
        try {
            $this->abnormalOrderService->markOverdue();
        } catch (Throwable $e) {
            $this->loggerFactory->get('crontab')->error('[Crontab] AbnormalOrder failed: ' . $e->getMessage());
        }
    }
}
