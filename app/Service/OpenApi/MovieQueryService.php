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

namespace App\Service\OpenApi;

use App\Dao\MovieBaseDataDao;
use App\Dao\SupplierDao;
use App\Exception\OpenApiException;
use App\Model\Merchant;
use App\Model\MovieCinema;
use App\Model\MovieCity;
use App\Model\MovieRegion;
use App\Model\Supplier;
use App\OpenApi\ErrorCode;
use App\Service\AbstractService;
use App\Service\Merchant\SubscriptionService;
use App\Service\Product\PricingRuleService;
use App\Supplier\Mango\MangoDriver;
use App\Supplier\SupplierDriverFactory;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 开放 API 电影票查询（requirements.md 7.3、8.1）。
 *
 * - **城市、区县、影院读平台缓存**（App\Service\Movie\MovieBaseDataSyncService 维护），不实时转发；
 * - **影片、场次、座位实时转发**：影片没有批量接口，场次没有批量拉取权限（mango.md 待确认 #5），
 *   座位状态变化快，始终实时；
 * - **场次价格替换成售价**：驱动归一化后只有一个成本 `cost`（分区时每个区各一个），这里按电影票加价规则
 *   算出每张售价，成本本身绝不出现在响应里（7.3 第 1 条）。算不出售价（缺成本）的场次/分区不返回——
 *   给商户一个没有价格的场次，他锁座时也锁不了。没配电影票加价规则时 PricingRuleService 抛错、接口报系统
 *   错误，**绝不按成本卖**（同快递查价）。
 * - 实时查询失败（网络、芒果报错、场次刚下架）统一返回 42011，文案让商户稍后重试
 *   （mango.md「场次下架」建议 30 秒左右后再拉）。
 *
 * 目前只有芒果一家电影票供应商（mango.md），取启用中的第一家；预留多家时再按城市/影院路由。
 */
class MovieQueryService extends AbstractService
{
    public const BUSINESS_LINE = 'movie';

    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected MovieBaseDataDao $baseDataDao;

    #[Inject]
    protected PricingRuleService $pricingRuleService;

    #[Inject]
    protected SubscriptionService $subscriptionService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function assertSubscribed(Merchant $merchant): void
    {
        if (! $this->subscriptionService->isSubscribed((int) $merchant->id, self::BUSINESS_LINE)) {
            throw new OpenApiException(ErrorCode::BusinessNotSubscribed);
        }
    }

    public function supplier(): Supplier
    {
        $supplier = $this->supplierDao->newQuery()
            ->where('status', 'active')
            ->where('business_line', self::BUSINESS_LINE)
            ->where('driver', 'mango')
            ->orderBy('id')
            ->first();
        if ($supplier === null) {
            throw new OpenApiException(ErrorCode::MovieUnavailable);
        }

        return $supplier;
    }

    public function driver(Supplier $supplier): MangoDriver
    {
        try {
            return $this->supplierDriverFactory->buildMango($supplier);
        } catch (Throwable $e) {
            $this->logFailure('build driver', $e);
            throw new OpenApiException(ErrorCode::MovieUnavailable);
        }
    }

    /**
     * @return list<array{city_id: string, city_name: string, first_letter: null|string, is_hot: bool}>
     */
    public function cities(Merchant $merchant): array
    {
        $this->assertSubscribed($merchant);

        return $this->baseDataDao->cities((int) $this->supplier()->id)
            ->map(static fn (MovieCity $city) => [
                'city_id' => $city->city_id,
                'city_name' => $city->city_name,
                'first_letter' => $city->first_letter,
                'is_hot' => (bool) $city->is_hot,
            ])->values()->all();
    }

    /**
     * @return list<array{region_id: string, region_name: string}>
     */
    public function regions(Merchant $merchant, string $cityId): array
    {
        $this->assertSubscribed($merchant);

        return $this->baseDataDao->regions((int) $this->supplier()->id, $cityId)
            ->map(static fn (MovieRegion $region) => ['region_id' => $region->region_id, 'region_name' => $region->region_name])
            ->values()->all();
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function cinemas(Merchant $merchant, string $cityId, ?string $regionId, int $page, int $perPage): array
    {
        $this->assertSubscribed($merchant);
        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));

        $result = $this->baseDataDao->cinemas((int) $this->supplier()->id, $cityId, $regionId, $page, $perPage);

        return [
            'data' => $result['items']->map(static fn (MovieCinema $cinema) => [
                'cinema_id' => $cinema->cinema_id,
                'cinema_name' => $cinema->cinema_name,
                'region_id' => $cinema->region_id,
                'address' => $cinema->address,
                'tel' => $cinema->tel,
                'longitude' => $cinema->longitude,
                'latitude' => $cinema->latitude,
            ])->values()->all(),
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return list<array{film_id: string, film_name: null|string, attributes: array<string, mixed>}>
     */
    public function films(Merchant $merchant, string $cityId): array
    {
        $this->assertSubscribed($merchant);
        $supplier = $this->supplier();

        try {
            return $this->driver($supplier)->queryFilms($cityId);
        } catch (OpenApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logFailure('query films', $e);
            throw new OpenApiException(ErrorCode::MovieUnavailable);
        }
    }

    /**
     * 场次（价格已换成每张售价）。
     *
     * @return list<array<string, mixed>>
     */
    public function shows(Merchant $merchant, string $cinemaId, string $filmId): array
    {
        $this->assertSubscribed($merchant);

        return array_values(array_filter(array_map(
            fn (array $show) => $this->priceShow($show),
            $this->rawShows($this->supplier(), $cinemaId, $filmId)
        )));
    }

    /**
     * 座位图（实时，不含价格——分区价格在场次里）。
     *
     * @return list<array<string, mixed>>
     */
    public function seats(Merchant $merchant, string $showId): array
    {
        $this->assertSubscribed($merchant);

        return array_map(static fn (array $seat) => [
            'seat_code' => $seat['seat_code'],
            'row' => $seat['row'],
            'col' => $seat['col'],
            'row_label' => $seat['row_label'],
            'col_label' => $seat['col_label'],
            'area_id' => $seat['area_id'],
            'love_status' => $seat['love_status'],
            'available' => $seat['available'],
        ], $this->rawSeats($this->supplier(), $showId));
    }

    /**
     * 驱动归一化后的场次（含成本），给锁座重新核价用，不能直接返回给商户。
     *
     * @return list<array<string, mixed>>
     */
    public function rawShows(Supplier $supplier, string $cinemaId, string $filmId): array
    {
        $driver = $this->driver($supplier);
        try {
            return $driver->queryShows($cinemaId, $filmId);
        } catch (Throwable $e) {
            $this->logFailure('query shows', $e);
            throw new OpenApiException(ErrorCode::MovieUnavailable);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rawSeats(Supplier $supplier, string $showId): array
    {
        $driver = $this->driver($supplier);
        try {
            return $driver->querySeats($showId);
        } catch (Throwable $e) {
            $this->logFailure('query seats', $e);
            throw new OpenApiException(ErrorCode::MovieUnavailable);
        }
    }

    public function cinemaName(Supplier $supplier, string $cinemaId): ?string
    {
        return $this->baseDataDao->findCinema((int) $supplier->id, $cinemaId)?->cinema_name;
    }

    /**
     * @param array<string, mixed> $show
     * @return null|array<string, mixed>
     */
    private function priceShow(array $show): ?array
    {
        $areas = [];
        foreach ($show['areas'] as $area) {
            if ($area['cost'] !== null) {
                $areas[] = [
                    'area_id' => $area['area_id'],
                    'area_name' => $area['area_name'],
                    'price' => $this->pricingRuleService->salePriceFor(self::BUSINESS_LINE, $area['cost']),
                ];
            }
        }
        $price = $show['cost'] === null ? null : $this->pricingRuleService->salePriceFor(self::BUSINESS_LINE, $show['cost']);
        // 不分区的场次看 price，分区的看每个区的 price；两样都没有就是没法卖
        if ($price === null && $areas === []) {
            return null;
        }

        return [
            'show_id' => $show['show_id'],
            'cinema_id' => $show['cinema_id'],
            'film_id' => $show['film_id'],
            'show_time' => $show['show_time'],
            'price' => $show['areas'] === [] ? $price : null,
            'areas' => $areas,
            'attributes' => $show['attributes'],
        ];
    }

    private function logFailure(string $what, Throwable $e): void
    {
        $this->loggerFactory->get('movie')->warning('movie ' . $what . ' failed', ['error' => $e->getMessage()]);
    }
}
