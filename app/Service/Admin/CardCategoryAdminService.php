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
use App\Model\CardCategory;
use App\Service\AbstractService;
use Hyperf\Database\Exception\QueryException;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统管理后台「卡券分类」：卡券的第一级（影音会员、游戏点卡……），下面挂品牌。
 * 有品牌的分类不能删，只能停用（停用后新建品牌时选不到，已有品牌不受影响）。权限同本地商品。
 */
class CardCategoryAdminService extends AbstractService
{
    private const NAME_MAX_LENGTH = 64;

    private const STATUSES = [CardCategory::STATUS_ACTIVE, CardCategory::STATUS_DISABLED];

    #[Inject]
    protected CardCategoryDao $cardCategoryDao;

    #[Inject]
    protected CardBrandDao $cardBrandDao;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(?string $status): array
    {
        if ($status !== null && ! in_array($status, self::STATUSES, true)) {
            throw new HttpException(422, 'status 必须是 active 或 disabled');
        }

        $categories = $this->cardCategoryDao->listAll($status);
        $counts = $this->cardBrandDao->countByCategory($categories->pluck('id')->all());

        return $categories->map(fn (CardCategory $c) => $this->format($c, $counts[$c->id] ?? 0))->values()->all();
    }

    /**
     * @param array<string, mixed> $data name / sort / status
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $name = $this->validateName($data['name'] ?? null, null);

        try {
            $category = $this->cardCategoryDao->create([
                'name' => $name,
                'sort' => $this->validateSort($data['sort'] ?? 0),
                'status' => array_key_exists('status', $data) ? $this->validateStatus($data['status']) : CardCategory::STATUS_ACTIVE,
            ]);
        } catch (QueryException $e) {
            throw new HttpException(422, '分类「' . $name . '」已经存在', 0, $e);
        }

        return $this->format($category, 0);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $category = $this->findOrFail($id);
        $attributes = [];
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
            $category->fill($attributes)->save();
        } catch (QueryException $e) {
            throw new HttpException(422, '分类名称已经存在', 0, $e);
        }

        return $this->format($category, $this->cardBrandDao->countByCategory([$id])[$id] ?? 0);
    }

    public function delete(int $id): void
    {
        $category = $this->findOrFail($id);
        $brands = $this->cardBrandDao->countByCategory([$id])[$id] ?? 0;
        if ($brands > 0) {
            throw new HttpException(409, '分类下还有 ' . $brands . ' 个品牌，不能删除，可以改成停用');
        }

        $category->delete();
    }

    /**
     * 给品牌选分类用：分类必须存在；新选的分类必须是启用的（品牌原来就在这个分类里，停用了也照样保存）。
     */
    public function assertSelectable(mixed $categoryId, ?int $currentCategoryId = null): int
    {
        if (! is_numeric($categoryId) || (int) $categoryId != $categoryId || (int) $categoryId <= 0) {
            throw new HttpException(422, '请选择卡券分类（category_id）');
        }
        $category = $this->cardCategoryDao->find((int) $categoryId);
        if ($category === null) {
            throw new HttpException(422, '卡券分类不存在');
        }
        if ($category->status !== CardCategory::STATUS_ACTIVE && $category->id !== $currentCategoryId) {
            throw new HttpException(422, '卡券分类「' . $category->name . '」已停用，不能选');
        }

        return (int) $category->id;
    }

    private function findOrFail(int $id): CardCategory
    {
        $category = $this->cardCategoryDao->find($id);
        if ($category === null) {
            throw new HttpException(404, '卡券分类不存在');
        }

        return $category;
    }

    private function validateName(mixed $name, ?int $exceptId): string
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new HttpException(422, '分类名称不能为空，最多 ' . self::NAME_MAX_LENGTH . ' 个字');
        }
        if ($this->cardCategoryDao->nameTaken($name, $exceptId)) {
            throw new HttpException(422, '分类「' . $name . '」已经存在');
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
     * @return array<string, mixed>
     */
    private function format(CardCategory $category, int $brandCount): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'sort' => $category->sort,
            'status' => $category->status,
            'brand_count' => $brandCount,
            'created_at' => $category->created_at?->toDateTimeString(),
            'updated_at' => $category->updated_at?->toDateTimeString(),
        ];
    }
}
