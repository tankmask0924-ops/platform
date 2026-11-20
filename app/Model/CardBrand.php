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
 * 卡券品牌（爱奇艺、腾讯视频、游戏点卡……），卡券商品按品牌归类，后台和商户按品牌筛选。
 * 话费不用品牌，运营商体现在 products.operator 上。
 *
 * @property int $id
 * @property string $name
 * @property int $sort
 * @property string $status active / disabled
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CardBrand extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected ?string $table = 'card_brands';

    protected array $fillable = ['name', 'sort', 'status'];

    protected array $casts = ['sort' => 'integer'];
}
