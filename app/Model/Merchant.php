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
use Hyperf\ModelCache\Cacheable;
use Hyperf\ModelCache\CacheableInterface;

/**
 * @property int $id
 * @property string $type
 * @property null|string $phone
 * @property null|string $email
 * @property string $password
 * @property string $status
 * @property null|int $level_id
 * @property null|string $app_key
 * @property null|string $app_secret
 * @property null|Carbon $app_secret_reset_at
 * @property null|array $ip_whitelist
 * @property string $available_balance
 * @property string $frozen_balance
 * @property null|Carbon $debt_since
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Merchant extends Model implements CacheableInterface
{
    use Cacheable;

    protected ?string $table = 'merchants';

    protected array $fillable = [
        'type',
        'phone',
        'email',
        'password',
        'status',
        'level_id',
        'app_key',
        'app_secret',
        'app_secret_reset_at',
        'ip_whitelist',
        'available_balance',
        'frozen_balance',
        'debt_since',
    ];

    protected array $casts = [
        'ip_whitelist' => 'array',
    ];
}
