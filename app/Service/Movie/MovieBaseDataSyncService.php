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

namespace App\Service\Movie;

use App\Dao\MovieBaseDataDao;
use App\Dao\SupplierDao;
use App\Model\Supplier;
use App\Service\AbstractService;
use App\Supplier\Mango\MangoDriver;
use App\Supplier\SupplierDriverFactory;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 电影票城市、区县、影院缓存的同步（requirements.md 7.3「城市、影院用批量拉取接口全量缓存，配合供应商的
 * 影院更新回调做增量同步，回调只发一次不补发，靠后台手动同步兜底遗漏」，mango.md 第 5 节）。
 *
 * - **全量**（syncSupplier()，每日定时 + 后台手动）：城市 → 每个城市的区县 → 每个城市的影院（按页拉到不满一页为止）。
 *   按唯一键 upsert，这一轮没拉到的记录删掉（芒果下线的城市/影院）。**只在拉成功的范围内删**：某个城市
 *   查失败了只记日志、跳过，不删它的旧数据——宁可多留一条过时影院，也不能因为一次网络抖动把整个城市清空。
 *   城市列表本身查失败就整轮放弃。
 * - **增量**（syncCinema()，影院更新回调触发）：回调只给影院 ID，按缓存里这家影院所属的城市重拉那个城市的影院，
 *   更新 `callback_synced_at`。缓存里还没有这家影院（新开的影院）就等下一次全量。
 *
 * 用的是逐城市的「影院列表」接口，不是「批量拉取影院数据」——后者要找芒果商务单独开权限（mango.md 待确认 #5），
 * 权限批下来以后把 cinemasOfCity() 换成按页批量拉取即可。
 */
class MovieBaseDataSyncService extends AbstractService
{
    public const BUSINESS_LINE = 'movie';

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected MovieBaseDataDao $baseDataDao;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 全部启用中的电影票供应商各全量同步一次（定时任务用）。
     */
    public function syncAll(): void
    {
        $suppliers = $this->supplierDao->newQuery()
            ->where('status', 'active')
            ->where('business_line', self::BUSINESS_LINE)
            ->where('driver', 'mango')
            ->get();
        foreach ($suppliers as $supplier) {
            try {
                $this->syncSupplier($supplier);
            } catch (Throwable $e) {
                $this->logger()->error('movie base data sync failed', ['supplier_id' => $supplier->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @return array{cities: int, regions: int, cinemas: int, failed_cities: list<string>}
     * @throws Throwable 城市列表都拉不到时原样抛出
     */
    public function syncSupplier(Supplier $supplier): array
    {
        $driver = $this->supplierDriverFactory->buildMango($supplier);
        $supplierId = (int) $supplier->id;
        $now = date('Y-m-d H:i:s');

        $cities = $driver->queryCities();
        $this->baseDataDao->upsertCities(array_map(static fn (array $city) => [
            'supplier_id' => $supplierId,
            'city_id' => $city['city_id'],
            'city_name' => $city['city_name'],
            'first_letter' => $city['first_letter'],
            'is_hot' => $city['is_hot'] ? 1 : 0,
            'synced_at' => $now,
        ], $cities));
        if ($cities !== []) {
            // 返回空列表更像是接口异常而不是全国没有城市，不据此清空
            $this->baseDataDao->deleteStale('movie_cities', $supplierId, $now);
        }

        $stats = ['cities' => count($cities), 'regions' => 0, 'cinemas' => 0, 'failed_cities' => []];
        foreach ($cities as $city) {
            $cityId = $city['city_id'];
            try {
                $regions = $driver->queryRegions($cityId);
                $cinemas = $this->cinemasOfCity($driver, $cityId);
            } catch (Throwable $e) {
                $stats['failed_cities'][] = $cityId;
                $this->logger()->warning('movie base data sync failed for city', [
                    'supplier_id' => $supplierId,
                    'city_id' => $cityId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $this->baseDataDao->upsertRegions(array_map(static fn (array $region) => [
                'supplier_id' => $supplierId,
                'city_id' => $cityId,
                'region_id' => $region['region_id'],
                'region_name' => $region['region_name'],
                'synced_at' => $now,
            ], $regions));
            $this->baseDataDao->upsertCinemas(
                $this->cinemaRows($supplierId, $cityId, $cinemas, ['synced_at' => $now]),
                ['cinema_code', 'cinema_name', 'city_id', 'region_id', 'address', 'tel', 'longitude', 'latitude', 'service_info', 'synced_at']
            );
            $this->baseDataDao->deleteStale('movie_regions', $supplierId, $now, $cityId);
            $this->baseDataDao->deleteStale('movie_cinemas', $supplierId, $now, $cityId);

            $stats['regions'] += count($regions);
            $stats['cinemas'] += count($cinemas);
        }

        $this->logger()->info('movie base data synced', ['supplier_id' => $supplierId] + $stats);

        return $stats;
    }

    /**
     * 影院更新回调：重拉这家影院所在城市的影院列表。
     *
     * @return bool 是否找到了这家影院并完成了同步
     */
    public function syncCinema(Supplier $supplier, string $cinemaId): bool
    {
        $cached = $this->baseDataDao->findCinema((int) $supplier->id, $cinemaId);
        if ($cached === null) {
            $this->logger()->info('movie cinema update for unknown cinema, wait for full sync', ['supplier_id' => $supplier->id, 'cinema_id' => $cinemaId]);

            return false;
        }

        $cityId = (string) $cached->city_id;
        $cinemas = $this->cinemasOfCity($this->supplierDriverFactory->buildMango($supplier), $cityId);
        $this->baseDataDao->upsertCinemas(
            $this->cinemaRows((int) $supplier->id, $cityId, $cinemas, ['callback_synced_at' => date('Y-m-d H:i:s')]),
            ['cinema_code', 'cinema_name', 'region_id', 'address', 'tel', 'longitude', 'latitude', 'service_info', 'callback_synced_at']
        );

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cinemasOfCity(MangoDriver $driver, string $cityId): array
    {
        $all = [];
        for ($page = 1; $page <= 200; ++$page) {
            $batch = $driver->queryCinemas($cityId, $page, MangoDriver::MAX_PAGE_SIZE);
            array_push($all, ...$batch);
            if (count($batch) < MangoDriver::MAX_PAGE_SIZE) {
                break;
            }
        }

        return $all;
    }

    /**
     * @param list<array<string, mixed>> $cinemas
     * @param array<string, mixed> $extra
     * @return list<array<string, mixed>>
     */
    private function cinemaRows(int $supplierId, string $cityId, array $cinemas, array $extra): array
    {
        return array_map(static fn (array $cinema) => array_merge([
            'supplier_id' => $supplierId,
            'cinema_id' => $cinema['cinema_id'],
            'cinema_code' => $cinema['cinema_code'],
            'cinema_name' => mb_substr($cinema['cinema_name'], 0, 128),
            // 按哪个城市查到的就挂在哪个城市下，影院记录自带的 city_id 不一定跟查询用的一致
            'city_id' => $cityId,
            'region_id' => $cinema['region_id'],
            'address' => $cinema['address'] === null ? null : mb_substr($cinema['address'], 0, 255),
            'tel' => $cinema['tel'] === null ? null : mb_substr($cinema['tel'], 0, 64),
            'longitude' => $cinema['longitude'],
            'latitude' => $cinema['latitude'],
            'service_info' => $cinema['service_info'] === null ? null : json_encode($cinema['service_info'], JSON_UNESCAPED_UNICODE),
            'synced_at' => date('Y-m-d H:i:s'),
            'callback_synced_at' => null,
        ], $extra), $cinemas);
    }

    private function logger(): LoggerInterface
    {
        return $this->loggerFactory->get('movie');
    }
}
