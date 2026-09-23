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
 * 全部由 App\Service\Merchant\BalanceService 写入（每种 type 对应它的一个方法）。
 *
 * `freeze_adjust`（2026-09-23 快递下单时新增）：冻结金额的**事后调整**，只有快递有
 * （requirements.md 7.2「以供应商返回的冻结运费为准重算预估售价，多退少补冻结金额」）。
 * **`amount` 带符号**：正数 = 多冻结（可用减、冻结加），负数 = 还回可用余额——其它类型的
 * 方向都由 type 本身表达，只有这一种两个方向共用一个 type。
 * 它**不在** `dedupe_order_key` 覆盖的类型里（生成列只认 deduct/unfreeze），
 * 所以同一笔订单可以有多条。
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
        'freeze_adjust',
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
