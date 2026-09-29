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

use App\Service\Reconciliation\FrozenBalanceCheckService;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 冻结余额定时核对，逻辑见 App\Service\Reconciliation\FrozenBalanceCheckService。
 * 每 10 分钟一次：只有两条聚合查询，有差额时才对那几个商户复查和定位订单，开销很小；
 * 冻结余额对不上意味着商户的钱被锁住或将被扣穿，越早发现越好查。
 */
#[Crontab(rule: '*/10 * * * *', name: 'FrozenBalanceCheck', memo: '商户冻结余额 = 处理中订单冻结金额之和（requirements.md 9）', singleton: true, onOneServer: true)]
class FrozenBalanceCheckCrontab
{
    #[Inject]
    protected FrozenBalanceCheckService $frozenBalanceCheckService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function __invoke(): void
    {
        try {
            $summary = $this->frozenBalanceCheckService->checkAll();
            if ($summary['mismatched'] > 0) {
                $this->loggerFactory->get('crontab')->warning('[Crontab] FrozenBalanceCheck found mismatches', $summary);
            }
        } catch (Throwable $e) {
            $this->loggerFactory->get('crontab')->error('[Crontab] FrozenBalanceCheck failed: ' . $e->getMessage());
        }
    }
}
