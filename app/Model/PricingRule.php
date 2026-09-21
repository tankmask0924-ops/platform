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
 * 电影票、快递的加价规则（requirements.md 5.1，database-design.md 4.13），对应
 * migrations/2026_09_21_130000_create_pricing_rules_table.php。计算和读写都走
 * App\Service\Product\PricingRuleService。
 *
 * **每条业务线只有一条当前生效的规则**（`business_line` 唯一），改了只影响之后下的订单——
 * 订单下单时已经把算出来的售价写进 `orders.sale_price` 快照，所以这张表不留历史版本，
 * 修改记录走 `admin_operation_logs`。
 *
 * 话费、卡券不用这张表：它们的售价是运营在本地商品库给每个商品直接设置的
 * （`products.sale_price`），没有"成本 + 规则"这一层。
 *
 * @property int $id
 * @property string $business_line
 * @property string $rule_type
 * @property string $value
 * @property null|int $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PricingRule extends Model
{
    /** 固定金额：售价 = 成本 + value */
    public const TYPE_FIXED = 'fixed';

    /** 百分比：售价 = 成本 ×(1 + value)，四舍五入到分 */
    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPES = [self::TYPE_FIXED, self::TYPE_PERCENTAGE];

    /**
     * 用这张表定价的业务线。话费、卡券不在其中（见类注释）。
     */
    public const BUSINESS_LINES = ['movie', 'express'];

    protected ?string $table = 'pricing_rules';

    protected array $fillable = [
        'business_line',
        'rule_type',
        'value',
        'updated_by',
    ];

    protected array $casts = [
        'updated_by' => 'integer',
    ];
}
