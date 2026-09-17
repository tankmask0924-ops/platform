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

use App\Service\Supplier\ProductSyncService;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 供应商商品每日全量校准（kasushou.md 第 4 节），逻辑见
 * App\Service\Supplier\ProductSyncService::syncAllSuppliers()。放在凌晨 4 点业务低峰；
 * 翻页可能跑很久，singleton 防止叠加（锁 1 小时，默认 60 秒不够），onOneServer 防止多台重复拉取。
 */
#[Crontab(rule: '0 4 * * *', name: 'SupplierProductSync', memo: '供应商商品每日全量校准（requirements.md 6.4）', singleton: true, mutexExpires: 3600, onOneServer: true)]
class SupplierProductSyncCrontab
{
    #[Inject]
    protected ProductSyncService $productSyncService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function __invoke(): void
    {
        try {
            $this->productSyncService->syncAllSuppliers();
        } catch (Throwable $e) {
            $this->loggerFactory->get('crontab')->error('[Crontab] SupplierProductSync failed: ' . $e->getMessage());
        }
    }
}
