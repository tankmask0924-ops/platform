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
 * 快递订单明细（requirements.md 7.2，database-design.md 4.7），一笔订单一行，
 * 主键就是 `order_id`（同 App\Model\OrderRecharge，要关掉自增主键行为）。
 *
 * **费用分两组**：`estimated_freight`（查价时的运费成本）、`frozen_freight`
 * （下单后供应商返回的冻结运费）是下单阶段的预估；`actual_*` 四项是供应商扣费
 * （云洋 `feeOver=1`）之后的实际费用，结算时才写。两组分开存，是因为"预估多少、
 * 实际多少、差了多少"正是快递这条业务线要对账的东西（7.2 结算举例整节都在讲这个）。
 *
 * **这张表存的基本是成本，不是售价**：售价在 `orders.sale_price`。加价只加在运费上
 * （5.1），保价费、耗材费、逆向费按成本转给商户，所以唯一需要单独存的售价是
 * `freight_sale_price`（结算时按当时的加价规则算出、实际向商户收的运费）——商户侧的
 * 费用明细 = `freight_sale_price` + 三项实际费用，不能把 `actual_freight` 直接给商户看。
 *
 * @property int $order_id
 * @property string $express_company_code
 * @property string $express_company_name
 * @property array $sender_info
 * @property array $receiver_info
 * @property array $item_info
 * @property string $weight
 * @property null|string $insured_amount
 * @property null|string $waybill_no
 * @property string $estimated_freight
 * @property null|string $frozen_freight
 * @property null|string $actual_freight
 * @property null|string $actual_insured_fee
 * @property null|string $actual_material_fee
 * @property null|string $actual_reverse_fee
 * @property null|string $freight_sale_price
 * @property string $logistics_status
 * @property null|Carbon $fee_over_at
 * @property null|Carbon $signed_at
 * @property null|string $supplier_rebate
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OrderExpress extends Model
{
    public const STATUS_PENDING_PICKUP = 'pending_pickup';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING_PICKUP,
        self::STATUS_IN_TRANSIT,
        self::STATUS_SIGNED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    public bool $incrementing = false;

    protected ?string $table = 'order_expresses';

    protected string $primaryKey = 'order_id';

    protected array $fillable = [
        'order_id',
        'express_company_code',
        'express_company_name',
        'sender_info',
        'receiver_info',
        'item_info',
        'weight',
        'insured_amount',
        'waybill_no',
        'estimated_freight',
        'frozen_freight',
        'actual_freight',
        'actual_insured_fee',
        'actual_material_fee',
        'actual_reverse_fee',
        'freight_sale_price',
        'logistics_status',
        'fee_over_at',
        'signed_at',
        'supplier_rebate',
    ];

    protected array $casts = [
        'sender_info' => 'array',
        'receiver_info' => 'array',
        'item_info' => 'array',
    ];

    protected array $dates = [
        'fee_over_at',
        'signed_at',
    ];
}
