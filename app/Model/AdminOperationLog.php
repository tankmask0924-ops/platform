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
 * 后台操作日志（database-design.md 4.1 `admin_operation_logs`），只写不改，
 * 所以关掉 updated_at 自动维护，created_at 由写入方给。
 *
 * @property int $id
 * @property int $admin_user_id
 * @property string $module
 * @property string $action
 * @property null|string $target_type
 * @property null|int $target_id
 * @property null|array $before_data
 * @property null|array $after_data
 * @property null|string $ip
 * @property Carbon $created_at
 */
class AdminOperationLog extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'admin_operation_logs';

    protected array $fillable = [
        'admin_user_id',
        'module',
        'action',
        'target_type',
        'target_id',
        'before_data',
        'after_data',
        'ip',
        'created_at',
    ];

    protected array $casts = [
        'before_data' => 'array',
        'after_data' => 'array',
    ];

    protected array $dates = [
        'created_at',
    ];
}
