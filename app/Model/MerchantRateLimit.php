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
 * 商户单独限流配置（migrations/2026_09_14_091000_create_merchant_rate_limits_table.php，
 * docs/database-design.md「merchant_rate_limits」）：只有被运营单独调整过的商户才有
 * 一行，没有行的商户走 `system_settings.default_rate_limit_per_second` 全局默认值。
 * 这张表只存配置，真正的计数器走 Redis（按商户限流中间件，docs/modules.md 第 1 节）。
 *
 * 主键就是 `merchant_id`（不自增），表上只有 `updated_at` 没有 `created_at`，
 * 跟 App\Model\SystemSetting 是同一种处理方式。
 *
 * @property int $merchant_id
 * @property int $limit_per_second
 * @property Carbon $updated_at
 */
class MerchantRateLimit extends Model
{
    public const CREATED_AT = null;

    public bool $incrementing = false;

    protected ?string $table = 'merchant_rate_limits';

    protected string $primaryKey = 'merchant_id';

    protected array $fillable = [
        'merchant_id',
        'limit_per_second',
    ];

    protected array $casts = [
        'merchant_id' => 'integer',
        'limit_per_second' => 'integer',
    ];
}
