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
 * 调价历史，写一次不再更新（只有 created_at，没有 updated_at），跟
 * App\Model\MerchantNotifyLog / App\Model\OrderRecharge 关掉 Eloquent 自动时间戳
 * 是同一类坑：这里同样需要在写入时自己传 created_at（见
 * App\Dao\SupplierProductDao::applySync()）。
 *
 * @property int $id
 * @property int $supplier_product_id
 * @property null|string $old_price
 * @property string $new_price
 * @property string $source 取值 sync/manual
 * @property Carbon $created_at
 */
class SupplierProductPriceHistory extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'supplier_product_price_history';

    protected array $fillable = [
        'supplier_product_id',
        'old_price',
        'new_price',
        'source',
        'created_at',
    ];

    protected array $dates = [
        'created_at',
    ];
}
