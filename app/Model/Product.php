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
 * 话费、卡券本地商品库（requirements.md 5.1/5.2/8.1，migrations/2026_09_14_091300_
 * create_products_table.php）。`business_line` 只有 recharge/card 两种取值——电影票、
 * 快递没有本地商品行，售价/返佣基数分别来自场次成本+加价规则、供应商返佣（见
 * requirements.md 5.3），不走这张表。
 *
 * @property int $id
 * @property string $business_line
 * @property string $name
 * @property null|string $operator
 * @property null|string $province
 * @property null|string $charge_speed
 * @property null|string $card_type
 * @property string $face_value
 * @property string $sale_price
 * @property string $rebate_amount
 * @property null|string $applicable_region
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Product extends Model
{
    protected ?string $table = 'products';

    protected array $fillable = [
        'business_line',
        'name',
        'operator',
        'province',
        'charge_speed',
        'card_type',
        'face_value',
        'sale_price',
        'rebate_amount',
        'applicable_region',
        'status',
    ];
}
