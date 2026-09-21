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
 * 运营告警（requirements.md 8.3「告警」列出的 7 类场景），对应
 * migrations/2026_09_21_110000_create_alerts_table.php。产生逻辑在
 * App\Service\Alert\AlertService，后台查看与处理在 App\Service\Admin\AlertAdminService。
 *
 * **7 个 type 全部在这里定义，哪怕暂时没有产生方**：这张表的取值范围是需求给定的
 * （8.3 那一行逐条列了 7 种），不是按"现在写了几个触发点"决定的。常量先齐全，
 * 后续补上检测链路时只需要找个地方调 AlertService，不用回头改枚举、迁移注释和前端
 * 中文名三处。各 type 当前有没有产生方见 AlertService 类注释。
 *
 * @property int $id
 * @property string $type
 * @property string $level
 * @property null|string $related_type
 * @property null|int $related_id
 * @property string $message
 * @property string $status
 * @property int $occurrence_count
 * @property null|int $resolved_by
 * @property null|Carbon $resolved_at
 * @property Carbon $triggered_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Alert extends Model
{
    public const TYPE_SUPPLIER_LOW_BALANCE = 'supplier_low_balance';

    public const TYPE_SUPPLIER_CIRCUIT_BROKEN = 'supplier_circuit_broken';

    public const TYPE_PRODUCT_FAIL_RATE_SPIKE = 'product_fail_rate_spike';

    public const TYPE_ABNORMAL_ORDER_BACKLOG = 'abnormal_order_backlog';

    public const TYPE_SUPPLIER_REFUND_AFTER_SUCCESS = 'supplier_refund_after_success';

    public const TYPE_REBATE_LOSS = 'rebate_loss';

    public const TYPE_MERCHANT_DEBT_EXCEEDED = 'merchant_debt_exceeded';

    public const TYPES = [
        self::TYPE_SUPPLIER_LOW_BALANCE,
        self::TYPE_SUPPLIER_CIRCUIT_BROKEN,
        self::TYPE_PRODUCT_FAIL_RATE_SPIKE,
        self::TYPE_ABNORMAL_ORDER_BACKLOG,
        self::TYPE_SUPPLIER_REFUND_AFTER_SUCCESS,
        self::TYPE_REBATE_LOSS,
        self::TYPE_MERCHANT_DEBT_EXCEEDED,
    ];

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_CRITICAL = 'critical';

    public const LEVELS = [self::LEVEL_WARNING, self::LEVEL_CRITICAL];

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_IGNORED = 'ignored';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_RESOLVED, self::STATUS_IGNORED];

    /** related_type 的取值，为 null 表示全局性告警（不指向任何一行） */
    public const RELATED_TYPES = ['supplier', 'product', 'merchant', 'order'];

    protected ?string $table = 'alerts';

    protected array $fillable = [
        'type',
        'level',
        'related_type',
        'related_id',
        'message',
        'status',
        'occurrence_count',
        'resolved_by',
        'resolved_at',
        'triggered_at',
    ];

    protected array $casts = [
        'related_id' => 'integer',
        'occurrence_count' => 'integer',
        'resolved_by' => 'integer',
    ];

    protected array $dates = [
        'resolved_at',
        'triggered_at',
    ];
}
