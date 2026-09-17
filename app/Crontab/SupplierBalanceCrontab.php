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

use App\Service\Supplier\SupplierBalanceService;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 供应商余额定时刷新，逻辑见 App\Service\Supplier\SupplierBalanceService。
 * 每 5 分钟一次：余额在两次刷新之间被订单消耗到不足时，供应商会返回预存款不足，
 * 路由那边会立即补一次刷新，所以不需要更密。
 */
#[Crontab(rule: '*/5 * * * *', name: 'SupplierBalance', memo: '供应商预存款余额刷新（requirements.md 6.7）', singleton: true, onOneServer: true)]
class SupplierBalanceCrontab
{
    #[Inject]
    protected SupplierBalanceService $supplierBalanceService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function __invoke(): void
    {
        try {
            $this->supplierBalanceService->refreshAll();
        } catch (Throwable $e) {
            $this->loggerFactory->get('crontab')->error('[Crontab] SupplierBalance failed: ' . $e->getMessage());
        }
    }
}
