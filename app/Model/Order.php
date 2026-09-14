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
 * @property string $order_no
 * @property int $merchant_id
 * @property string $merchant_order_no
 * @property string $business_line
 * @property string $status
 * @property string $sale_price
 * @property string $cost_price
 * @property null|int $supplier_id
 * @property null|string $supplier_order_no
 * @property string $frozen_amount
 * @property null|string $deducted_amount
 * @property string $refunded_amount
 * @property string $callback_url
 * @property null|string $fail_reason
 * @property null|Carbon $completed_at
 * @property null|Carbon $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Order extends Model
{
    protected ?string $table = 'orders';

    protected array $fillable = [
        'order_no',
        'merchant_id',
        'merchant_order_no',
        'business_line',
        'status',
        'sale_price',
        'cost_price',
        'supplier_id',
        'supplier_order_no',
        'frozen_amount',
        'deducted_amount',
        'refunded_amount',
        'callback_url',
        'fail_reason',
        'completed_at',
        'finished_at',
    ];

    /**
     * completed_at/finished_at 不会像 created_at/updated_at 那样被 Eloquent 自动转成
     * Carbon 实例，必须显式加进 $dates，否则是原始字符串，Service 层调用
     * ->toDateTimeString() 会直接报错。
     */
    protected array $dates = [
        'completed_at',
        'finished_at',
    ];
}
