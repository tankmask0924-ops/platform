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

use App\Model\Product;
use Hyperf\Database\Model\Collection;

class ProductDao extends AbstractDao
{
    protected string $model = Product::class;

    /**
     * 开放 API「话费/卡券商品列表」用（requirements.md 8.1）：某条业务线全部在架商品，
     * 按创建时间正序（先上架的排前面）。这些列表预期很小、变化也不频繁，但读取时机不可预测
     * （商户随时可能来查），所以不加 model-cache，普通查询即可。
     */
    public function listOnShelfByBusinessLine(string $businessLine): Collection
    {
        return $this->newQuery()
            ->where('business_line', $businessLine)
            ->where('status', 'on_shelf')
            ->orderBy('created_at')
            ->get();
    }
}
