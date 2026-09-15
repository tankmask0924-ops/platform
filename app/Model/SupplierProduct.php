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

namespace App\Model;

use Carbon\Carbon;

/**
 * 供应商商品映射行（商户本地商品 <-> 某个供应商的商品）。映射行的创建/查询/改价/
 * 优先级/启停由后台"商品映射"功能负责（requirements.md 6.4，
 * App\Service\Admin\ProductMappingAdminService + App\Controller\Admin\ProductMappingController），
 * 成本价的同步写入（供应商变更通知/全量同步触发）由
 * App\Service\Supplier\ProductSyncService 负责，两者共用
 * App\Dao\SupplierProductDao 的同一套"改价必留痕"事务逻辑，不重复实现。
 *
 * `(product_id, supplier_id)` 唯一，但 `supplier_product_code` 不是唯一键（同一个
 * 供应商编码理论上可能被不同 product_id 各配一条映射），按供应商编码反查用
 * `App\Dao\SupplierProductDao::findBySupplierAndCode()`，不是主键/唯一键查询。
 *
 * @property int $id
 * @property int $product_id
 * @property int $supplier_id
 * @property string $supplier_product_code
 * @property string $cost_price
 * @property int $priority
 * @property string $status 取值 active/paused/banned
 * @property null|int $stock 为空表示不限
 * @property null|array $param_mapping
 * @property null|array $sale_restrictions
 * @property null|Carbon $synced_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SupplierProduct extends Model
{
    protected ?string $table = 'supplier_products';

    protected array $fillable = [
        'product_id',
        'supplier_id',
        'supplier_product_code',
        'cost_price',
        'priority',
        'status',
        'stock',
        'param_mapping',
        'sale_restrictions',
        'synced_at',
    ];

    protected array $casts = [
        'param_mapping' => 'array',
        'sale_restrictions' => 'array',
    ];
}
