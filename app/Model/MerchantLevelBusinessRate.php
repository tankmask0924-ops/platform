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
 * 商户等级在某条业务线上的默认返佣比例（requirements.md 5.2/5.3，
 * migrations/2026_09_14_090800_create_merchant_level_business_rates_table.php）：
 * 创建等级时运营为每个业务线填写一条，`(level_id, business_line)` 唯一。
 * 话费、卡券商品可以用 App\Model\ProductLevelRebate 单独覆盖这里的比例。
 *
 * @property int $id
 * @property int $level_id
 * @property string $business_line
 * @property string $rebate_rate
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MerchantLevelBusinessRate extends Model
{
    protected ?string $table = 'merchant_level_business_rates';

    protected array $fillable = [
        'level_id',
        'business_line',
        'rebate_rate',
    ];
}
