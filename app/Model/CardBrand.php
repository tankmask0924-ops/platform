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
 * 卡券品牌（爱奇艺、腾讯视频……），卡券的第二级，属于一个分类（App\Model\CardCategory）。
 * 卡券商品选品牌，后台和商户按分类、品牌筛选。
 * 话费不用品牌，运营商体现在 products.operator 上。
 *
 * @property int $id
 * @property null|int $category_id card_categories.id（品牌属于哪个分类；新建品牌必填）
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

    protected array $fillable = ['category_id', 'name', 'sort', 'status'];

    protected array $casts = ['sort' => 'integer'];
}
