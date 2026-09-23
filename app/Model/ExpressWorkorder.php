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
 * 快递工单（客服代提交，database-design.md 4.9，requirements.md 7.2、8.3「售后处理」）。
 * 业务规则见 App\Service\Admin\ExpressWorkorderAdminService。
 *
 * @property int $id
 * @property int $order_id
 * @property int $supplier_id
 * @property string $type
 * @property string $status
 * @property string $content
 * @property null|string $supplier_workorder_no
 * @property int $submitted_by
 * @property null|string $supplier_reply
 * @property null|string $supplier_amount
 * @property null|Carbon $supplier_replied_at
 * @property null|string $result_remark
 * @property null|string $claim_amount
 * @property null|int $resolved_by
 * @property null|Carbon $resolved_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ExpressWorkorder extends Model
{
    public const TYPE_WEIGHT_VERIFY = 'weight_verify';

    public const TYPE_CLAIM = 'claim';

    public const TYPE_CANCEL = 'cancel';

    public const TYPE_COD = 'cod';

    public const TYPE_URGE_PICKUP = 'urge_pickup';

    public const TYPE_URGE_TRANSPORT = 'urge_transport';

    public const TYPE_URGE_DELIVERY = 'urge_delivery';

    public const TYPES = [
        self::TYPE_WEIGHT_VERIFY,
        self::TYPE_CLAIM,
        self::TYPE_CANCEL,
        self::TYPE_COD,
        self::TYPE_URGE_PICKUP,
        self::TYPE_URGE_TRANSPORT,
        self::TYPE_URGE_DELIVERY,
    ];

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_PROCESSING, self::STATUS_COMPLETED, self::STATUS_REJECTED];

    protected ?string $table = 'express_workorders';

    protected array $fillable = [
        'order_id',
        'supplier_id',
        'type',
        'status',
        'content',
        'supplier_workorder_no',
        'submitted_by',
        'supplier_reply',
        'supplier_amount',
        'supplier_replied_at',
        'result_remark',
        'claim_amount',
        'resolved_by',
        'resolved_at',
    ];

    protected array $casts = [
        'order_id' => 'integer',
        'supplier_id' => 'integer',
        'submitted_by' => 'integer',
        'resolved_by' => 'integer',
    ];

    protected array $dates = [
        'supplier_replied_at',
        'resolved_at',
        'created_at',
        'updated_at',
    ];
}
