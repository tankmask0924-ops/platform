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
 * @property int $id
 * @property string $name
 * @property null|string $remark
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MerchantLevel extends Model
{
    protected ?string $table = 'merchant_levels';

    protected array $fillable = ['name', 'remark'];
}
