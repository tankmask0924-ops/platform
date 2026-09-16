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
 * 全平台统一的系统参数表（migrations/2026_09_14_092600_create_system_settings_table.php），
 * 目前唯一的读者是 `App\Dao\SystemSettingDao::getValue()`，第一个用到的 key 是
 * `rebate_due_period_days`（requirements.md 5.4"返佣固定期限...默认 7 天"）。
 *
 * 主键是 `key`（字符串，不自增），必须关掉 Eloquent 默认的自增整数主键行为——
 * 跟 `App\Model\OrderRecharge`（主键是 `order_id`，同样不自增）是同一个模式，
 * 区别只在于那边主键是 int（`$keyType` 默认 'int' 不用改），这里主键是字符串，
 * 额外需要把 `$keyType` 也改成 'string'，否则 Eloquent 内部按主键做 where 查询/
 * 序列化时会按整数类型处理，字符串主键会被处理错。
 *
 * 只有 `updated_at`、没有 `created_at`（迁移里没有 `datetimes()`，只手写了一个
 * `updated_at`）：不能简单整体 `$timestamps = false`（那样 `updated_at` 也不会
 * 自动维护了），而是保留 `$timestamps = true`（父类默认值，这里不用重复声明），
 * 把 `CREATED_AT` 常量覆盖成 `null`——`Hyperf\Database\Model\Concerns\
 * HasTimestamps::updateTimestamps()` 对 `CREATED_AT`/`UPDATED_AT` 分别判断
 * "是否为 null"，`null` 的那一列会被跳过、不写，`UPDATED_AT` 保留默认值
 * `'updated_at'` 继续在每次 `save()` 时自动维护。
 *
 * @property string $key
 * @property string $value
 * @property null|string $description
 * @property null|int $updated_by
 * @property Carbon $updated_at
 */
class SystemSetting extends Model
{
    public const CREATED_AT = null;

    public bool $incrementing = false;

    protected ?string $table = 'system_settings';

    protected string $primaryKey = 'key';

    protected string $keyType = 'string';

    protected array $fillable = [
        'key',
        'value',
        'description',
        'updated_by',
    ];
}
