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
 * @property null|int $supplier_id 提交给哪家供应商售后
 * @property null|string $supplier_aftersale_no
 * @property null|string $supplier_aftersale_status processing / completed / terminated
 * @property null|string $supplier_aftersale_reply
 * @property null|Carbon $supplier_aftersale_submitted_at
 * @property null|Carbon $supplier_aftersale_updated_at
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

    /** 供应商售后状态（卡速售售后处理回调：处理中 / 处理完成 / 终止） */
    public const SUPPLIER_AFTERSALE_PROCESSING = 'processing';

    public const SUPPLIER_AFTERSALE_COMPLETED = 'completed';

    public const SUPPLIER_AFTERSALE_TERMINATED = 'terminated';

    protected ?string $table = 'aftersale_disputes';

    protected array $fillable = [
        'order_id',
        'merchant_id',
        'status',
        'evidence',
        'handler_id',
        'result_remark',
        'supplier_id',
        'supplier_aftersale_no',
        'supplier_aftersale_status',
        'supplier_aftersale_reply',
        'supplier_aftersale_submitted_at',
        'supplier_aftersale_updated_at',
        'submitted_at',
        'resolved_at',
    ];

    protected array $casts = [
        'evidence' => 'array',
        'supplier_id' => 'integer',
    ];

    protected array $dates = [
        'submitted_at',
        'resolved_at',
        'supplier_aftersale_submitted_at',
        'supplier_aftersale_updated_at',
    ];
}
