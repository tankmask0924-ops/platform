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

namespace App\Service\Admin;

use App\Dao\CardBrandDao;
use App\Dao\CardCategoryDao;
use App\Dao\ProductDao;
use App\Model\CardBrand;
use App\Service\AbstractService;
use Hyperf\Database\Exception\QueryException;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统管理后台「卡券品牌」：卡券的第二级，每个品牌属于一个分类（新建品牌必须选启用的分类）。增删改查。商品已经用到的品牌不能删，只能停用（停用后新建、编辑卡券商品时选不到，
 * 已有商品不受影响）。权限跟本地商品一样：product.view / product.manage。
 */
class CardBrandAdminService extends AbstractService
{
    private const NAME_MAX_LENGTH = 64;

    private const STATUSES = [CardBrand::STATUS_ACTIVE, CardBrand::STATUS_DISABLED];

    #[Inject]
    protected CardBrandDao $cardBrandDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected CardCategoryDao $cardCategoryDao;

    #[Inject]
    protected CardCategoryAdminService $cardCategoryAdminService;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(?string $status, ?int $categoryId = null): array
    {
        if ($status !== null && ! in_array($status, self::STATUSES, true)) {
            throw new HttpException(422, 'status 必须是 active 或 disabled');
        }

        $brands = $this->cardBrandDao->listAll($status, $categoryId);
        $counts = $this->productDao->countByBrand($brands->pluck('id')->all());
        $categories = $this->categoryNames($brands->pluck('category_id')->all());

        return $brands->map(fn (CardBrand $brand) => $this->format($brand, $counts[$brand->id] ?? 0, $categories))->values()->all();
    }

    /**
     * @param array<string, mixed> $data name / sort / status
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $name = $this->validateName($data['name'] ?? null, null);
        $categoryId = $this->cardCategoryAdminService->assertSelectable($data['category_id'] ?? null);

        try {
            $brand = $this->cardBrandDao->create([
                'category_id' => $categoryId,
                'name' => $name,
                'sort' => $this->validateSort($data['sort'] ?? 0),
                'status' => array_key_exists('status', $data) ? $this->validateStatus($data['status']) : CardBrand::STATUS_ACTIVE,
            ]);
        } catch (QueryException $e) {
            throw new HttpException(422, '品牌「' . $name . '」已经存在', 0, $e);
        }

        return $this->format($brand, 0, $this->categoryNames([$categoryId]));
    }

    /**
     * 只改传了的字段。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $brand = $this->findOrFail($id);
        $attributes = [];
        if (array_key_exists('category_id', $data)) {
            $attributes['category_id'] = $this->cardCategoryAdminService->assertSelectable($data['category_id'], $brand->category_id);
        }
        if (array_key_exists('name', $data)) {
            $attributes['name'] = $this->validateName($data['name'], $id);
        }
        if (array_key_exists('sort', $data)) {
            $attributes['sort'] = $this->validateSort($data['sort']);
        }
        if (array_key_exists('status', $data)) {
            $attributes['status'] = $this->validateStatus($data['status']);
        }

        try {
            $brand->fill($attributes)->save();
        } catch (QueryException $e) {
            throw new HttpException(422, '品牌名称已经存在', 0, $e);
        }

        return $this->format($brand, $this->productDao->countByBrand([$id])[$id] ?? 0, $this->categoryNames([$brand->category_id]));
    }

    public function delete(int $id): void
    {
        $brand = $this->findOrFail($id);
        $used = $this->productDao->countByBrand([$id])[$id] ?? 0;
        if ($used > 0) {
            throw new HttpException(409, '品牌下还有 ' . $used . ' 个商品，不能删除，可以改成停用');
        }

        $brand->delete();
    }

    /**
     * 给卡券商品选品牌用：品牌必须存在；新选的品牌必须是启用的（商品原来就是这个品牌的，停用了也照样保存）。
     */
    public function assertSelectable(mixed $brandId, ?int $currentBrandId = null): int
    {
        if (! is_numeric($brandId) || (int) $brandId != $brandId || (int) $brandId <= 0) {
            throw new HttpException(422, '请选择卡券品牌（brand_id）');
        }
        $brand = $this->cardBrandDao->find((int) $brandId);
        if ($brand === null) {
            throw new HttpException(422, '卡券品牌不存在');
        }
        if ($brand->status !== CardBrand::STATUS_ACTIVE && $brand->id !== $currentBrandId) {
            throw new HttpException(422, '卡券品牌「' . $brand->name . '」已停用，不能选');
        }

        return (int) $brand->id;
    }

    private function findOrFail(int $id): CardBrand
    {
        $brand = $this->cardBrandDao->find($id);
        if ($brand === null) {
            throw new HttpException(404, '卡券品牌不存在');
        }

        return $brand;
    }

    private function validateName(mixed $name, ?int $exceptId): string
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new HttpException(422, '品牌名称不能为空，最多 ' . self::NAME_MAX_LENGTH . ' 个字');
        }
        if ($this->cardBrandDao->nameTaken($name, $exceptId)) {
            throw new HttpException(422, '品牌「' . $name . '」已经存在');
        }

        return $name;
    }

    private function validateSort(mixed $sort): int
    {
        if (! is_numeric($sort) || (int) $sort != $sort || (int) $sort < 0 || (int) $sort > 9999) {
            throw new HttpException(422, '排序必须是 0~9999 的整数');
        }

        return (int) $sort;
    }

    private function validateStatus(mixed $status): string
    {
        if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
            throw new HttpException(422, 'status 必须是 active 或 disabled');
        }

        return $status;
    }

    /**
     * @param array<int, null|int> $categoryIds
     * @return array<int, string> 分类 id => 名称
     */
    private function categoryNames(array $categoryIds): array
    {
        $ids = array_values(array_unique(array_filter($categoryIds, static fn ($id) => $id !== null)));

        return $this->cardCategoryDao->findMany($ids)->map(static fn ($category) => $category->name)->all();
    }

    /**
     * @param array<int, string> $categoryNames
     * @return array<string, mixed>
     */
    private function format(CardBrand $brand, int $productCount, array $categoryNames): array
    {
        return [
            'id' => $brand->id,
            'category_id' => $brand->category_id,
            'category_name' => $brand->category_id === null ? null : ($categoryNames[$brand->category_id] ?? null),
            'name' => $brand->name,
            'sort' => $brand->sort,
            'status' => $brand->status,
            'product_count' => $productCount,
            'created_at' => $brand->created_at?->toDateTimeString(),
            'updated_at' => $brand->updated_at?->toDateTimeString(),
        ];
    }
}
