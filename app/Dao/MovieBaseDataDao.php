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

namespace App\Dao;

use App\Model\MovieCinema;
use App\Model\MovieCity;
use App\Model\MovieRegion;
use Hyperf\Database\Model\Collection;
use Hyperf\DbConnection\Db;

/**
 * 电影票基础数据缓存（城市、区县、影院三张表）的读写，三张表形状类似、总是一起用，放在一个 Dao 里
 * （跟"一个 Dao 对一个 Model"的惯例不同，`$model` 指向影院表，城市和区县走各自 Model 的查询）。
 *
 * 写入一律按唯一键 upsert（`INSERT ... ON DUPLICATE KEY UPDATE`）：全量同步是"拉到什么写什么"，
 * 同一条记录反复同步只更新，不会重复插入。
 */
class MovieBaseDataDao extends AbstractDao
{
    protected string $model = MovieCinema::class;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function upsertCities(array $rows): void
    {
        $this->upsert('movie_cities', $rows, ['city_name', 'first_letter', 'is_hot', 'synced_at']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function upsertRegions(array $rows): void
    {
        $this->upsert('movie_regions', $rows, ['region_name', 'synced_at']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $updateColumns 批量同步和回调增量同步更新的时间列不同
     */
    public function upsertCinemas(array $rows, array $updateColumns): void
    {
        $this->upsert('movie_cinemas', $rows, $updateColumns);
    }

    /**
     * @return Collection<int, MovieCity>
     */
    public function cities(int $supplierId): Collection
    {
        return MovieCity::query()->where('supplier_id', $supplierId)->orderByDesc('is_hot')->orderBy('first_letter')->orderBy('city_name')->get();
    }

    /**
     * @return Collection<int, MovieRegion>
     */
    public function regions(int $supplierId, string $cityId): Collection
    {
        return MovieRegion::query()->where('supplier_id', $supplierId)->where('city_id', $cityId)->orderBy('region_name')->get();
    }

    /**
     * @return array{items: Collection<int, MovieCinema>, total: int}
     */
    public function cinemas(int $supplierId, string $cityId, ?string $regionId, int $page, int $perPage): array
    {
        $query = MovieCinema::query()->where('supplier_id', $supplierId)->where('city_id', $cityId);
        if ($regionId !== null) {
            $query->where('region_id', $regionId);
        }

        return [
            'total' => (clone $query)->count(),
            'items' => $query->orderBy('id')->forPage($page, $perPage)->get(),
        ];
    }

    public function findCinema(int $supplierId, string $cinemaId): ?MovieCinema
    {
        return MovieCinema::query()->where('supplier_id', $supplierId)->where('cinema_id', $cinemaId)->first();
    }

    /**
     * @return list<string>
     */
    public function cityIds(int $supplierId): array
    {
        return MovieCity::query()->where('supplier_id', $supplierId)->pluck('city_id')->map(static fn ($id) => (string) $id)->all();
    }

    /**
     * 全量同步结束后删掉这次没同步到的记录（芒果那边下线的城市/影院）。只在这一轮确实同步成功后调用。
     */
    public function deleteStale(string $table, int $supplierId, string $syncedBefore, ?string $cityId = null): int
    {
        $query = Db::table($table)->where('supplier_id', $supplierId)->where('synced_at', '<', $syncedBefore);
        if ($cityId !== null) {
            $query->where('city_id', $cityId);
        }

        return $query->delete();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $updateColumns
     */
    private function upsert(string $table, array $rows, array $updateColumns): void
    {
        if ($rows === []) {
            return;
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Db::table($table)->upsert($chunk, [], $updateColumns);
        }
    }
}
