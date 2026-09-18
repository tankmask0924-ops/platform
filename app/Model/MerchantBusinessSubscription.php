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
 * 商户业务线开通申请与状态（requirements.md 4.2）。每个商户每条业务线一行
 * （`(merchant_id, business_line)` 唯一），驳回后重新申请是把这一行改回 pending。
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $business_line
 * @property string $status
 * @property Carbon $applied_at
 * @property null|int $reviewed_by
 * @property null|Carbon $reviewed_at
 * @property null|string $reject_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MerchantBusinessSubscription extends Model
{
    protected ?string $table = 'merchant_business_subscriptions';

    protected array $fillable = [
        'merchant_id',
        'business_line',
        'status',
        'applied_at',
        'reviewed_by',
        'reviewed_at',
        'reject_reason',
    ];

    protected array $casts = [
        'applied_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];
}
