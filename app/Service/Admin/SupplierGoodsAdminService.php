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

use App\Dao\ProductDao;
use App\Dao\SupplierDao;
use App\Dao\SupplierGoodsDao;
use App\Dao\SupplierProductDao;
use App\Model\Supplier;
use App\Model\SupplierGoods;
use App\Model\SupplierProduct;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统管理后台「供应商商品库」查询：新增本地商品、添加映射时从这里选供应商商品，
 * 供应商详情页也用它看这家供应商同步到了哪些商品。数据由商品同步写入
 * （App\Service\Supplier\ProductSyncService），后台只读。
 *
 * 每行附带「已被哪些本地商品使用」，方便运营判断一个供应商商品是不是已经配过。
 */
class SupplierGoodsAdminService extends AbstractService
{
    private const ALLOWED_STATUSES = ['active', 'paused', 'banned'];

    private const ALLOWED_GOODS_TYPES = [SupplierGoods::TYPE_CARD_SECRET, SupplierGoods::TYPE_VIRTUAL];

    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected SupplierGoodsDao $supplierGoodsDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierProductDao $supplierProductDao;

    #[Inject]
    protected ProductDao $productDao;

    /**
     * `supplier_id` 和 `business_line` 至少给一个：前者看一家供应商的商品，后者给某条
     * 业务线的本地商品选供应商商品（只列这条业务线的供应商）。`goods_type`（card_secret/virtual）
     * 按商品类型筛，类型未知的也会列出来。
     *
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(
        int $page,
        int $perPage,
        ?int $supplierId,
        ?string $businessLine,
        ?string $keyword,
        ?string $status,
        ?string $goodsType = null
    ): array {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), self::MAX_PER_PAGE);

        if ($status !== null && ! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new HttpException(422, 'status 必须是以下之一：' . implode('/', self::ALLOWED_STATUSES));
        }
        if ($goodsType !== null && ! in_array($goodsType, self::ALLOWED_GOODS_TYPES, true)) {
            throw new HttpException(422, 'goods_type 必须是以下之一：' . implode('/', self::ALLOWED_GOODS_TYPES));
        }

        if ($supplierId !== null) {
            $suppliers = $this->supplierDao->findMany([$supplierId]);
            $supplier = $suppliers->get($supplierId);
            if (! $supplier instanceof Supplier) {
                throw new HttpException(404, '供应商不存在');
            }
            if ($businessLine !== null && $supplier->business_line !== $businessLine) {
                return ['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
            }
        } elseif ($businessLine !== null) {
            $suppliers = $this->supplierDao->listByBusinessLine($businessLine);
        } else {
            throw new HttpException(422, 'supplier_id 和 business_line 至少传一个');
        }

        $supplierIds = $suppliers->keys()->all();
        $keyword = $keyword !== null && trim($keyword) !== '' ? trim($keyword) : null;

        $rows = $this->supplierGoodsDao->paginate($page, $perPage, $supplierIds, $keyword, $status, $goodsType);
        $usedBy = $this->usedBy($rows->all());

        return [
            'data' => $rows
                ->map(fn (SupplierGoods $goods) => $this->format(
                    $goods,
                    $suppliers->get($goods->supplier_id),
                    $usedBy[$goods->supplier_id . ':' . $goods->code] ?? []
                ))
                ->values()
                ->all(),
            'total' => $this->supplierGoodsDao->count($supplierIds, $keyword, $status, $goodsType),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 这一页供应商商品各被哪些本地商品映射了，键为 `supplier_id:code`。
     *
     * @param array<int, SupplierGoods> $rows
     * @return array<string, array<int, array{id: int, name: null|string}>>
     */
    private function usedBy(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $mappings = $this->supplierProductDao->listBySupplierCodes(
            array_values(array_unique(array_map(fn (SupplierGoods $g) => $g->supplier_id, $rows))),
            array_values(array_unique(array_map(fn (SupplierGoods $g) => $g->code, $rows)))
        );
        $products = $this->productDao->findMany($mappings->pluck('product_id')->unique()->values()->all());

        $result = [];
        foreach ($mappings as $mapping) {
            /* @var SupplierProduct $mapping */
            $result[$mapping->supplier_id . ':' . $mapping->supplier_product_code][] = [
                'id' => $mapping->product_id,
                'name' => $products->get($mapping->product_id)?->name,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array{id: int, name: null|string}> $usedBy
     * @return array<string, mixed>
     */
    private function format(SupplierGoods $goods, ?Supplier $supplier, array $usedBy): array
    {
        return [
            'id' => $goods->id,
            'supplier_id' => $goods->supplier_id,
            'supplier_name' => $supplier?->name,
            'supplier_code' => $supplier?->code,
            'code' => $goods->code,
            'name' => $goods->name,
            'face_value' => $goods->face_value,
            'goods_type' => $goods->goods_type,
            'cost_price' => $goods->cost_price,
            'status' => $goods->status,
            'stock' => $goods->stock,
            'synced_at' => $goods->synced_at?->toDateTimeString(),
            'used_by' => $usedBy,
        ];
    }
}
