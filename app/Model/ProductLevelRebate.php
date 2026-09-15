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
 * 话费、卡券商品对某个商户等级单独设置的返佣比例覆盖（requirements.md 5.2/5.3，
 * migrations/2026_09_14_091400_create_product_level_rebates_table.php）：一个商品可以
 * 只给部分等级单独设置，`(product_id, level_id)` 唯一。`rebate_rate` 是比例（如 0.9000），
 * 不是百分比整数。
 *
 * @property int $id
 * @property int $product_id
 * @property int $level_id
 * @property string $rebate_rate
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ProductLevelRebate extends Model
{
    protected ?string $table = 'product_level_rebates';

    protected array $fillable = [
        'product_id',
        'level_id',
        'rebate_rate',
    ];
}
