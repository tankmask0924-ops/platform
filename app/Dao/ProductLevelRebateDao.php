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

use App\Model\ProductLevelRebate;

class ProductLevelRebateDao extends AbstractDao
{
    protected string $model = ProductLevelRebate::class;

    /**
     * 按 (product_id, level_id) 查商品对某个等级单独设置的返佣比例覆盖
     * （requirements.md 5.3 返佣比例取法第 1 步），没有单独设置时返回 null。
     */
    public function findForProductAndLevel(int $productId, int $levelId): ?ProductLevelRebate
    {
        return $this->newQuery()
            ->where('product_id', $productId)
            ->where('level_id', $levelId)
            ->first();
    }
}
