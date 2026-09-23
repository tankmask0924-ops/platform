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
 * 对账差异（requirements.md 8.3「对账」，database-design.md 4.15），对应
 * migrations/2026_09_21_120000_create_reconciliation_diffs_table.php。产生逻辑在
 * App\Service\Reconciliation\ReconciliationService，后台查看与标记处理在
 * App\Service\Admin\ReconciliationAdminService。
 *
 * 两个 type 分开记（8.3「订单对账与返佣对账分开进行」）：`order` 比状态和成本，`rebate` 比供应商返佣，
 * 目前只有电影票有供应商返佣可对（requirements.md 5.4，快递的云洋没有返佣）。两者由 ReconciliationService
 * 的同一次查询产生，各自按 `(type, reconciliation_date)` 整批替换。
 *
 * 没有 updated_at：按 database-design.md 4.15 的字段表建表，标记处理写的是
 * resolved_at/resolved_by/remark，$timestamps 关掉、created_at 写入时自己传。
 *
 * @property int $id
 * @property string $type
 * @property int $order_id
 * @property int $supplier_id
 * @property Carbon $reconciliation_date
 * @property string $field
 * @property string $platform_value
 * @property string $supplier_value
 * @property null|string $diff_amount
 * @property string $status
 * @property null|int $resolved_by
 * @property null|Carbon $resolved_at
 * @property null|string $remark
 * @property Carbon $created_at
 */
class ReconciliationDiff extends Model
{
    public const TYPE_ORDER = 'order';

    public const TYPE_REBATE = 'rebate';

    public const TYPES = [self::TYPE_ORDER, self::TYPE_REBATE];

    /** 订单状态不一致：平台终态 vs 供应商侧订单状态映射出来的统一结果 */
    public const FIELD_STATUS = 'status';

    /** 成本金额不一致：平台订单成本快照 vs 供应商订单金额 */
    public const FIELD_COST_PRICE = 'cost_price';

    /** 返佣金额不一致：平台记录的供应商返佣 vs 供应商查询订单详情给的返佣 */
    public const FIELD_REBATE_AMOUNT = 'rebate_amount';

    public const FIELDS = [self::FIELD_STATUS, self::FIELD_COST_PRICE, self::FIELD_REBATE_AMOUNT];

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_IGNORED = 'ignored';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_RESOLVED, self::STATUS_IGNORED];

    public bool $timestamps = false;

    protected ?string $table = 'reconciliation_diffs';

    protected array $fillable = [
        'type',
        'order_id',
        'supplier_id',
        'reconciliation_date',
        'field',
        'platform_value',
        'supplier_value',
        'diff_amount',
        'status',
        'resolved_by',
        'resolved_at',
        'remark',
        'created_at',
    ];

    protected array $casts = [
        'order_id' => 'integer',
        'supplier_id' => 'integer',
        'resolved_by' => 'integer',
    ];

    protected array $dates = [
        'reconciliation_date',
        'resolved_at',
        'created_at',
    ];
}
