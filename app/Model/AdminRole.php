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
 * @property bool $is_system
 * @property null|string $remark
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AdminRole extends Model
{
    protected ?string $table = 'admin_roles';

    protected array $fillable = [
        'name',
        'is_system',
        'remark',
    ];

    protected array $casts = [
        'is_system' => 'boolean',
    ];
}
