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

use App\Service\Order\SupplierOrderDispatcher;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 供应商下单分派兜底：入队失败或消息丢失、一直没分派给供应商的话费卡券订单重新入队，
 * 逻辑见 App\Service\Order\SupplierOrderDispatcher::redispatchStale()。每分钟一次，onOneServer 防多台重复入队
 * （重复了也只是多一条消息，dispatch() 本身幂等）。
 */
#[Crontab(rule: '* * * * *', name: 'SupplierDispatchRecovery', memo: '重新分派没调用上供应商的话费卡券订单（requirements.md 9）', singleton: true, onOneServer: true)]
class SupplierDispatchRecoveryCrontab
{
    #[Inject]
    protected SupplierOrderDispatcher $dispatcher;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function __invoke(): void
    {
        try {
            $this->dispatcher->redispatchStale();
        } catch (Throwable $e) {
            $this->loggerFactory->get('crontab')->error('[Crontab] SupplierDispatchRecovery failed: ' . $e->getMessage());
        }
    }
}
