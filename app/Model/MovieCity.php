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

/**
 * 电影票城市缓存（database-design.md 4.11），由 App\Service\Movie\MovieBaseDataSyncService 全量同步维护，
 * 开放 API 的查询直接读这张表，不实时转发给供应商。按 `(supplier_id, ...)` 唯一，预留多家供应商。
 */
class MovieCity extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'movie_cities';

    protected array $fillable = ['supplier_id', 'city_id', 'city_name', 'first_letter', 'is_hot', 'synced_at'];

    protected array $casts = ['is_hot' => 'boolean'];
}
