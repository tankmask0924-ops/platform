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

use App\Service\Reconciliation\ReconciliationService;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 每日订单对账（requirements.md 8.3「对账」），逻辑见
 * App\Service\Reconciliation\ReconciliationService::runOrderReconciliation()。
 *
 * 05:00 跑，对的是前一天完成的订单：比 04:00 的商品全量校准晚一小时，两个都要翻页调
 * 供应商接口，错开避免同时打满对方的频率限制；又留足时间让昨天最后一批订单的回调、
 * 定时查询把状态落定。逐笔调供应商查询可能跑很久，singleton 锁 1 小时防叠加，
 * onOneServer 防多台重复对账。
 */
#[Crontab(rule: '0 5 * * *', name: 'Reconciliation', memo: '每日订单对账（requirements.md 8.3）', singleton: true, mutexExpires: 3600, onOneServer: true)]
class ReconciliationCrontab
{
    #[Inject]
    protected ReconciliationService $reconciliationService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function __invoke(): void
    {
        try {
            $this->reconciliationService->runOrderReconciliation();
        } catch (Throwable $e) {
            $this->loggerFactory->get('crontab')->error('[Crontab] Reconciliation failed: ' . $e->getMessage());
        }
    }
}
