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
 * 供应商商品库：供应商那边有哪些商品（编码、名称、面值、成本价、状态、库存），
 * 由商品同步（App\Service\Supplier\ProductSyncService）整批写入，不管平台有没有用到。
 *
 * 跟 App\Model\SupplierProduct 的分工：这里是「供应商有什么」，SupplierProduct 是
 * 「本地商品用了供应商的哪个商品」（优先级、下单参数映射等平台侧配置）。后台新增
 * 本地商品、添加映射时从这里选，映射行按 `(supplier_id, code)` 关联到这里。
 *
 * @property int $id
 * @property int $supplier_id
 * @property string $code
 * @property null|string $name
 * @property null|string $face_value
 * @property string $cost_price
 * @property string $status 取值 active/paused/banned
 * @property null|int $stock 为空表示不限
 * @property null|Carbon $synced_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SupplierGoods extends Model
{
    protected ?string $table = 'supplier_goods';

    protected array $fillable = [
        'supplier_id',
        'code',
        'name',
        'face_value',
        'cost_price',
        'status',
        'stock',
        'synced_at',
    ];

    protected array $casts = [
        'synced_at' => 'datetime',
    ];
}
