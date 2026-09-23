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
 * 快递费用调整记录（requirements.md 7.2「结算点...之后到签收前产生的耗材费、保价费、
 * 逆向费变化，按费用调整补扣或退回，每次调整记录并回调商户」，database-design.md 4.7）。
 *
 * 写一次不再更新的流水，只有 `created_at`（同 App\Model\MerchantBalanceLog）。
 * `amount` 恒为正数，方向由 `type` 表达——跟资金流水把方向放进 type 是同一个约定，
 * 避免出现"负数的补扣"这种要读两遍才明白的记录。
 *
 * @property int $id
 * @property int $order_id
 * @property string $type
 * @property string $item
 * @property string $amount
 * @property null|string $reason
 * @property Carbon $created_at
 */
class OrderExpressFeeAdjustment extends Model
{
    /** 补扣：从商户可用余额再扣 */
    public const TYPE_SUPPLEMENT = 'supplement';

    /** 退回：退给商户 */
    public const TYPE_REFUND = 'refund';

    public const TYPES = [self::TYPE_SUPPLEMENT, self::TYPE_REFUND];

    public const ITEM_FREIGHT = 'freight';

    public const ITEM_INSURED = 'insured';

    public const ITEM_MATERIAL = 'material';

    public const ITEM_REVERSE = 'reverse';

    public const ITEMS = [self::ITEM_FREIGHT, self::ITEM_INSURED, self::ITEM_MATERIAL, self::ITEM_REVERSE];

    public bool $timestamps = false;

    protected ?string $table = 'order_express_fee_adjustments';

    protected array $fillable = [
        'order_id',
        'type',
        'item',
        'amount',
        'reason',
        'created_at',
    ];

    protected array $casts = [
        'order_id' => 'integer',
    ];

    protected array $dates = [
        'created_at',
    ];
}
