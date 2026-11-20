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
 * 卡券分类（影音会员、游戏点卡、生活服务……），卡券的第一级；第二级是品牌（App\Model\CardBrand）。
 * 商品只选品牌，分类由品牌带出来。会员等级、时长（黄金/白金、月卡/年卡）是商品本身的区别，不进分类。
 *
 * @property int $id
 * @property string $name
 * @property int $sort
 * @property string $status active / disabled
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CardCategory extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected ?string $table = 'card_categories';

    protected array $fillable = ['name', 'sort', 'status'];

    protected array $casts = ['sort' => 'integer'];
}
