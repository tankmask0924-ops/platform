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
 * 商户资金流水（requirements.md 4.4/4.5），每次余额变动写一条，写完不再更新
 * （只有 created_at，没有 updated_at），跟 App\Model\MerchantNotifyLog 一样的
 * 写一次不改的日志表，关掉 Eloquent 默认时间戳维护（$timestamps = false），
 * created_at 需要在写入时自己传值（见 App\Dao\MerchantBalanceLogDao）。
 *
 * `dedupe_order_key`/`dedupe_rebate_key` 是迁移里的 MySQL 生成列（storedAs），
 * 只读、由数据库根据 type/order_id/rebate_id 自动算出，不放进 $fillable ——
 * 应用代码永远不会、也不能直接写这两列，写了会被数据库忽略或报错。
 *
 * type 目前落地的合法值见迁移注释：recharge/freeze/deduct/unfreeze/
 * supplement_deduct/refund/adjustment/rebate_settle/rebate_clawback，完整枚举
 * 見下面 self::TYPES（给资金流水筛选接口的 `?type=` 参数做白名单校验用，
 * App\Service\Merchant\BalanceLogService、App\Service\Admin\MerchantAdminService
 * 各自的 normalizeTypeFilter() 都引用这个常量，不各自维护一份容易漂移的列表）。
 * 目前 App\Service\Merchant\BalanceService 写 freeze/deduct/unfreeze/recharge/
 * rebate_settle/adjustment 六种（手动调账见 adjust()）；supplement_deduct/refund/
 * rebate_clawback 对应的触发流程（售后补扣/退款、返佣扣回）还是没建的功能，
 * 不在这次任务范围内，但表结构和 Model 需要如实覆盖完整枚举，因为是同一张共用表。
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $type
 * @property string $amount
 * @property string $available_before
 * @property string $available_after
 * @property string $frozen_before
 * @property string $frozen_after
 * @property null|int $order_id
 * @property null|int $rebate_id
 * @property null|string $reason
 * @property null|int $operator_id
 * @property Carbon $created_at
 */
class MerchantBalanceLog extends Model
{
    /**
     * @var array<int, string>
     */
    public const TYPES = [
        'recharge',
        'freeze',
        'deduct',
        'unfreeze',
        'supplement_deduct',
        'refund',
        'adjustment',
        'rebate_settle',
        'rebate_clawback',
    ];

    public bool $timestamps = false;

    protected ?string $table = 'merchant_balance_logs';

    protected array $fillable = [
        'merchant_id',
        'type',
        'amount',
        'available_before',
        'available_after',
        'frozen_before',
        'frozen_after',
        'order_id',
        'rebate_id',
        'reason',
        'operator_id',
        'created_at',
    ];

    protected array $dates = [
        'created_at',
    ];
}
