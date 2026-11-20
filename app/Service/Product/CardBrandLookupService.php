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

namespace App\Service\Product;

use App\Dao\CardBrandDao;
use App\Dao\CardCategoryDao;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 商品列表（后台、开放 API、商户后台）按品牌 id 批量补品牌名称和所属分类，一次列表两条 SQL。
 */
class CardBrandLookupService extends AbstractService
{
    #[Inject]
    protected CardBrandDao $cardBrandDao;

    #[Inject]
    protected CardCategoryDao $cardCategoryDao;

    /**
     * @param array<int, null|int> $brandIds 可以带 null 和重复值
     * @return array<int, array{brand_name: string, category_id: null|int, category_name: null|string}> 按品牌 id 作键
     */
    public function infoByBrandIds(array $brandIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($brandIds, static fn ($id) => $id !== null)))));
        if ($ids === []) {
            return [];
        }

        $brands = $this->cardBrandDao->findMany($ids);
        $categories = $this->cardCategoryDao->findMany($brands->pluck('category_id')->filter()->unique()->values()->all());

        $result = [];
        foreach ($brands as $brand) {
            $category = $brand->category_id === null ? null : $categories->get($brand->category_id);
            $result[(int) $brand->id] = [
                'brand_name' => $brand->name,
                'category_id' => $category?->id,
                'category_name' => $category?->name,
            ];
        }

        return $result;
    }

    /**
     * 给一行商品数据补 brand_name / category_id / category_name（话费和没设置品牌的卡券都是 null）。
     *
     * @param array<int, array{brand_name: string, category_id: null|int, category_name: null|string}> $info infoByBrandIds() 的结果
     * @return array{brand_name: null|string, category_id: null|int, category_name: null|string}
     */
    public static function fieldsFor(?int $brandId, array $info): array
    {
        $row = $brandId === null ? null : ($info[$brandId] ?? null);

        return [
            'brand_name' => $row['brand_name'] ?? null,
            'category_id' => $row['category_id'] ?? null,
            'category_name' => $row['category_name'] ?? null,
        ];
    }
}
