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
 * @property string $username
 * @property string $password
 * @property string $real_name
 * @property int $role_id
 * @property string $status
 * @property null|Carbon $last_login_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AdminUser extends Model
{
    protected ?string $table = 'admin_users';

    protected array $fillable = [
        'username',
        'password',
        'real_name',
        'role_id',
        'status',
        'last_login_at',
    ];
}
