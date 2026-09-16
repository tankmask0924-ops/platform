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
 * @property int $id
 * @property int $order_id
 * @property int $merchant_id
 * @property string $business_line
 * @property int $level_id
 * @property string $rebate_base
 * @property string $rebate_base_source
 * @property string $rebate_rate
 * @property string $rebate_rate_source
 * @property string $amount
 * @property string $status
 * @property null|Carbon $order_completed_at
 * @property null|Carbon $due_at
 * @property null|Carbon $settled_at
 * @property null|Carbon $voided_at
 * @property null|Carbon $clawed_back_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MerchantRebate extends Model
{
    protected ?string $table = 'merchant_rebates';

    protected array $fillable = [
        'order_id',
        'merchant_id',
        'business_line',
        'level_id',
        'rebate_base',
        'rebate_base_source',
        'rebate_rate',
        'rebate_rate_source',
        'amount',
        'status',
        'order_completed_at',
        'due_at',
        'settled_at',
        'voided_at',
        'clawed_back_at',
    ];

    /**
     * 跟 `App\Model\Order` 类注释里同样的坑：这几个日期列不会像 created_at/
     * updated_at 那样被 Eloquent 自动转成 Carbon 实例，必须显式加进 `$dates`，
     * 否则读出来是原始字符串，调用方（`OrderResultApplier`/`RebateSettlementCrontab`/
     * 测试里）拿它们做 `Carbon::parse()`/`->toDateTimeString()` 之类的日期运算
     * 会直接报错。
     */
    protected array $dates = [
        'order_completed_at',
        'due_at',
        'settled_at',
        'voided_at',
        'clawed_back_at',
    ];
}
