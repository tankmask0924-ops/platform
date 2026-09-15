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

use App\Model\SupplierProduct;
use App\Model\SupplierProductPriceHistory;
use Hyperf\DbConnection\Db;

class SupplierProductDao extends AbstractDao
{
    protected string $model = SupplierProduct::class;

    /**
     * 按供应商 + 供应商商品编码反查映射行。`(product_id, supplier_id)` 才是唯一键，
     * `supplier_product_code` 不是，所以这里是普通查询，不走 model-cache（见
     * App\Model\SupplierProduct 类注释）。商品变更通知、全量同步都靠这个方法定位
     * 要更新的映射行；查不到就是"平台还没配置这个供应商商品的映射"，调用方按
     * no-op 处理，不是这里的职责。
     */
    public function findBySupplierAndCode(int $supplierId, string $supplierProductCode): ?SupplierProduct
    {
        return $this->newQuery()
            ->where('supplier_id', $supplierId)
            ->where('supplier_product_code', $supplierProductCode)
            ->first();
    }

    /**
     * 把供应商侧查到的权威成本价/状态/库存同步到已有映射行，`synced_at` 设为当前时间；
     * 只要 `cost_price` 真的发生变化（新旧值不相等，用 bccomp 比较，不转 float）才在
     * 同一个事务里补一条 `supplier_product_price_history`（source=sync）。
     *
     * 【为什么把"改价必留痕"这条规则收在这一个方法里】这是整个代码库里唯一负责
     * 写 `supplier_products.cost_price` 的方法（商品映射创建行不在本次任务范围，
     * 见类注释）——只要以后所有改价路径（通知触发、全量同步）都必须经过这里，
     * 就不可能出现"改了价但漏记历史"的中间状态，不需要在每个调用方（
     * App\Service\Supplier\ProductSyncService 的两个方法）各自重复一遍"要不要写
     * 历史"的判断。
     */
    public function applySync(SupplierProduct $product, string $costPrice, string $status, ?int $stock): SupplierProduct
    {
        return Db::transaction(function () use ($product, $costPrice, $status, $stock) {
            $oldPrice = $product->cost_price;
            $priceChanged = bccomp($oldPrice, $costPrice, 2) !== 0;

            $product->fill([
                'cost_price' => $costPrice,
                'status' => $status,
                'stock' => $stock,
                'synced_at' => date('Y-m-d H:i:s'),
            ])->save();

            if ($priceChanged) {
                SupplierProductPriceHistory::create([
                    'supplier_product_id' => $product->id,
                    'old_price' => $oldPrice,
                    'new_price' => $costPrice,
                    'source' => 'sync',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            return $product;
        });
    }
}
