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
 * 话费、卡券未到账争议（requirements.md 7.7，database-design.md `aftersale_disputes`）。
 * 一笔订单只能有一条（order_id 唯一）。
 *
 * @property int $id
 * @property int $order_id
 * @property int $merchant_id
 * @property string $status processing 处理中 / rejected 已驳回 / confirmed 已确认未到账
 * @property null|array $evidence 客服核实凭证
 * @property null|int $handler_id
 * @property null|string $result_remark
 * @property Carbon $submitted_at
 * @property null|Carbon $resolved_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AftersaleDispute extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CONFIRMED = 'confirmed';

    protected ?string $table = 'aftersale_disputes';

    protected array $fillable = [
        'order_id',
        'merchant_id',
        'status',
        'evidence',
        'handler_id',
        'result_remark',
        'submitted_at',
        'resolved_at',
    ];

    protected array $casts = [
        'evidence' => 'array',
    ];

    protected array $dates = [
        'submitted_at',
        'resolved_at',
    ];
}
