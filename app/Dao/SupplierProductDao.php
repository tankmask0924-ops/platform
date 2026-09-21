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
use Hyperf\Database\Model\Collection;
use Hyperf\DbConnection\Db;

class SupplierProductDao extends AbstractDao
{
    protected string $model = SupplierProduct::class;

    /**
     * 跟 App\Dao\SupplierDao::find()/App\Dao\ProductDao::find() 同样的目的：只是把
     * AbstractDao::find() 的返回类型从基类 Model 收窄成 SupplierProduct，不涉及
     * model-cache（SupplierProduct 未实现 CacheableInterface）。
     */
    public function find(int $id): ?SupplierProduct
    {
        return SupplierProduct::find($id);
    }

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
     * 某个本地商品的全部供应商映射行，按 `priority` 升序——这个顺序跟 requirements.md
     * 6.5「固定优先级路由」将来挑选供应商时读取的顺序一致，虽然路由本身不在本次任务
     * 范围（见 App\Service\Admin\ProductMappingAdminService 类注释），但排序规则现在
     * 就按最终会被消费的方式定下来，不留给以后再改。
     */
    /**
     * 按"本地商品 + 供应商"取映射行（`(product_id, supplier_id)` 唯一）。
     * 熔断的"供应商 + 商品"粒度要用它确认这个组合真的存在——给一个没映射过的组合
     * 建熔断行，路由根本不会走到，只会在后台留下一条永远不生效的记录。
     */
    public function findMapping(int $supplierId, int $productId): ?SupplierProduct
    {
        return $this->newQuery()
            ->where('supplier_id', $supplierId)
            ->where('product_id', $productId)
            ->first();
    }

    public function listForProduct(int $productId): Collection
    {
        return $this->newQuery()
            ->where('product_id', $productId)
            ->orderBy('priority')
            ->get();
    }

    /**
     * 把供应商侧查到的权威成本价/状态/库存同步到已有映射行，`synced_at` 设为当前时间。
     * 改价必留痕的事务逻辑收在 writeCostPrice()，这里只负责拼出"同步"场景特有的
     * 附加字段（status/stock/synced_at）和固定的 source='sync'。
     */
    public function applySync(SupplierProduct $product, string $costPrice, string $status, ?int $stock): SupplierProduct
    {
        return $this->writeCostPrice($product, $costPrice, 'sync', [
            'status' => $status,
            'stock' => $stock,
            'synced_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 后台「商品映射」人工改价入口（requirements.md 6.4「成本价每次变化都记历史」），
     * source='manual'。跟 applySync() 共用同一个 writeCostPrice() 私有方法走同一套
     * 事务 + 历史记录逻辑，只是不像 applySync() 那样顺带同步 status/stock/synced_at
     * ——人工改价只改 cost_price 这一个字段，状态/库存有各自独立的更新入口（见
     * App\Service\Admin\ProductMappingAdminService::setStatus()），不应该被一次改价
     * 顺带覆盖掉。
     */
    public function updateCostPriceManually(SupplierProduct $product, string $costPrice): SupplierProduct
    {
        return $this->writeCostPrice($product, $costPrice, 'manual', []);
    }

    /**
     * 【为什么把"改价必留痕"这条规则收在这一个私有方法里】这是整个代码库里唯一
     * 负责写 `supplier_products.cost_price` 的地方——`applySync()`（同步触发）和
     * `updateCostPriceManually()`（后台人工改价）都只是拼出各自场景特有的附加字段
     * 后转发到这里。只要以后所有改价路径都必须经过这里，就不可能出现"改了价但漏记
     * 历史"的中间状态，不需要在每个调用方各自重复一遍"要不要写历史"的判断。
     *
     * 只要 `cost_price` 真的发生变化（新旧值不相等，用 bccomp 比较，不转 float）才在
     * 同一个事务里补一条 `supplier_product_price_history`，`source` 由调用方传入
     * （sync/manual，对应 `supplier_product_price_history.source` 列注释）。
     *
     * @param array<string, mixed> $extraAttributes 随 cost_price 一起写入的场景特有字段
     *                                              （目前只有 applySync() 的 status/stock/synced_at）
     */
    private function writeCostPrice(
        SupplierProduct $product,
        string $costPrice,
        string $source,
        array $extraAttributes
    ): SupplierProduct {
        return Db::transaction(function () use ($product, $costPrice, $source, $extraAttributes) {
            $oldPrice = $product->cost_price;
            $priceChanged = bccomp($oldPrice, $costPrice, 2) !== 0;

            $product->fill(array_merge(['cost_price' => $costPrice], $extraAttributes))->save();

            if ($priceChanged) {
                SupplierProductPriceHistory::create([
                    'supplier_product_id' => $product->id,
                    'old_price' => $oldPrice,
                    'new_price' => $costPrice,
                    'source' => $source,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            return $product;
        });
    }
}
