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
 * 商品列表接口只给价格、状态、库存、销售限制；下单参数模板、购买数量、是否支持撤单只有商品详情
 * 接口才有（变更通知会查详情，后台建映射时也会查一次，见 ProductMappingAdminService）。
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
 * @property null|string $goods_type card_secret 卡密 / virtual 虚拟（直充、话费）；为空表示供应商没给
 * @property string $cost_price
 * @property string $status 取值 active/paused/banned
 * @property null|int $stock 为空表示不限
 * @property null|array<int, array{key: string, name: null|string, type: null|string}> $attach_template 下单参数模板；null 表示还没查过详情，卡密商品是空数组
 * @property null|int $min_quantity 最小购买数量
 * @property null|int $max_quantity 最大购买数量
 * @property null|bool $can_back 是否支持撤单
 * @property null|array<string, null|string> $sale_restrictions can_buy/can_no_buy/can_price/need_balance，不展示给商户
 * @property null|Carbon $detail_synced_at 最近一次查商品详情的时间
 * @property null|Carbon $synced_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SupplierGoods extends Model
{
    public const TYPE_CARD_SECRET = 'card_secret';

    public const TYPE_VIRTUAL = 'virtual';

    protected ?string $table = 'supplier_goods';

    protected array $fillable = [
        'supplier_id',
        'code',
        'name',
        'face_value',
        'goods_type',
        'cost_price',
        'status',
        'stock',
        'attach_template',
        'min_quantity',
        'max_quantity',
        'can_back',
        'sale_restrictions',
        'synced_at',
        'detail_synced_at',
    ];

    protected array $casts = [
        'attach_template' => 'array',
        'sale_restrictions' => 'array',
        'can_back' => 'boolean',
        'synced_at' => 'datetime',
        'detail_synced_at' => 'datetime',
    ];
}
