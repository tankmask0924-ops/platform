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

namespace App\Dao;

use App\Model\SupplierProductPriceHistory;
use Hyperf\Database\Model\Collection;

class SupplierProductPriceHistoryDao extends AbstractDao
{
    protected string $model = SupplierProductPriceHistory::class;

    /**
     * 按供应商商品映射行查调价历史，按时间倒序。历史写入本身在
     * App\Dao\SupplierProductDao::applySync() 里完成，这里只提供读取。
     */
    public function findBySupplierProductId(int $supplierProductId): Collection
    {
        return $this->newQuery()
            ->where('supplier_product_id', $supplierProductId)
            ->orderByDesc('created_at')
            ->get();
    }
}
