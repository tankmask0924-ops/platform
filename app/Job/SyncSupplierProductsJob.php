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

namespace App\Job;

use App\Dao\SupplierDao;
use App\Service\Supplier\ProductSyncService;
use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 系统后台手动触发某个供应商的商品全量同步（跟每日 04:00 的校准同一套逻辑）。
 * 翻页拉取可能要几十秒，放队列里做；失败记日志不重试，结果看商品映射的同步时间和调用日志。
 */
class SyncSupplierProductsJob extends Job
{
    public function __construct(public readonly int $supplierId)
    {
    }

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        $supplier = $container->get(SupplierDao::class)->find($this->supplierId);
        if ($supplier === null) {
            return;
        }

        try {
            $container->get(ProductSyncService::class)->syncSupplier($supplier);
        } catch (Throwable $e) {
            $container->get(LoggerFactory::class)->get('supplier-product-sync')->error('manual supplier product sync failed', [
                'supplier_id' => $this->supplierId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
