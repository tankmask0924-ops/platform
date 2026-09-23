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

namespace App\Service\Order;

use App\Dao\OrderAttemptDao;
use App\Dao\OrderMovieDao;
use App\Exception\OpenApiException;
use App\Model\Merchant;
use App\Model\Order;
use App\Movie\SeatSelectionException;
use App\Movie\SeatSelectionValidator;
use App\OpenApi\ErrorCode;
use App\Service\OpenApi\MovieQueryService;
use App\Service\Product\PricingRuleService;
use App\Supplier\DriverResult;
use App\Supplier\Mango\MangoDriver;
use App\Supplier\UnifiedResult;
use Carbon\Carbon;
use Hyperf\Di\Annotation\Inject;
use Throwable;

/**
 * 电影票锁座（requirements.md 7.3 第一步，8.1「锁座」）。跟话费、卡券、快递共用
 * AbstractOrderPlacementService 的幂等重放、欠款拦截、建单竞态、余额不足处理；电影票自己的部分。
 *
 * 1. **重新核价**：不采用商户传入的任何价格（7.3 第 2 条）。锁座时重新查一次这个影院这部影片的场次，
 *    找到商户选的场次，按所选座位的分区取成本（分区用该区成本，不分区用场次成本），按电影票加价规则算每张售价，
 *    **冻结 = 每张售价 × 张数**。场次查不到（已停售、换了影院/影片）报 42012。
 * 2. **锁座前置校验**（App\Movie\SeatSelectionValidator）：重新拉一次实时座位图校验张数、可售、分区、情侣座、
 *    隔空选座，不合法直接 41001，**不建单、不冻结、不调芒果**。
 * 3. **只锁一次，不重试**：芒果锁座没有防重复单号。结果未知就保持处理中——锁座 10 分钟后芒果自己会超时释放，
 *    商户也没法对一个没有芒果单号的订单确认出票，所以超时释放任务到期直接解冻是安全的
 *    （App\Service\OpenApi\MovieOrderService::expireLocks()）。
 * 4. **"订单溢价"**（芒果说价格跟当前成本对不上）是明确失败：订单失败、全额解冻，失败码 43004，
 *    商户 2~3 分钟后重新查场次再锁。
 * 5. 锁座有效期按芒果固定的 10 分钟，从芒果受理那一刻算，写进 `order_movies.lock_expire_at` 返回给商户。
 */
class MovieOrderPlacementService extends AbstractOrderPlacementService
{
    private const ORDER_NO_PREFIX = 'M';

    #[Inject]
    protected MovieQueryService $queryService;

    #[Inject]
    protected PricingRuleService $pricingRuleService;

    #[Inject]
    protected SeatSelectionValidator $seatValidator;

    #[Inject]
    protected OrderMovieDao $orderMovieDao;

    #[Inject]
    protected OrderAttemptDao $orderAttemptDao;

    #[Inject]
    protected MovieOrderSettlementService $settlementService;

    #[Inject]
    protected MovieOrderPresenter $presenter;

    /**
     * @param list<string> $seatCodes
     * @return array<string, mixed>
     */
    public function lock(
        Merchant $merchant,
        string $merchantOrderNo,
        string $cinemaId,
        string $filmId,
        string $showId,
        array $seatCodes,
        string $mobile,
        string $callbackUrl
    ): array {
        $existing = $this->findIdempotentReplay($merchant, $merchantOrderNo);
        if ($existing !== null) {
            return $existing;
        }

        $this->assertBusinessSubscribed($merchant);
        $this->assertMerchantNotSuspended($merchant);

        $supplier = $this->queryService->supplier();
        $show = $this->findShow($this->queryService->rawShows($supplier, $cinemaId, $filmId), $showId);

        try {
            $seats = $this->seatValidator->validate($seatCodes, $this->queryService->rawSeats($supplier, $showId));
        } catch (SeatSelectionException $e) {
            throw new OpenApiException(ErrorCode::InvalidParams, $e->getMessage());
        }

        $areaId = $seats[0]['area_id'];
        $unitCost = $this->unitCost($show, $areaId);
        $unitPrice = $this->pricingRuleService->salePriceFor(MovieOrderSettlementService::BUSINESS_LINE, $unitCost);
        $count = (string) count($seats);
        $driver = $this->queryService->driver($supplier);

        $order = $this->createOrderRow(
            $merchant,
            $merchantOrderNo,
            bcmul($unitPrice, $count, 2),
            $callbackUrl,
            bcmul($unitCost, $count, 2)
        );
        if ($order === null) {
            return $this->resolveReplayAfterCreateRace($merchant, $merchantOrderNo);
        }

        if (! $this->balanceService->freeze($merchant->id, $order->id, $order->sale_price)) {
            $this->handleFreezeFailure($order);
            return $this->toResponseArray($order);
        }

        $this->orderMovieDao->create([
            'order_id' => $order->id,
            'cinema_id' => $cinemaId,
            'cinema_name' => $this->queryService->cinemaName($supplier, $cinemaId),
            'film_id' => $filmId,
            'film_name' => $this->filmName($show),
            'show_id' => $showId,
            'show_time' => $show['show_time'] ?? date('Y-m-d H:i:s'),
            'area_id' => $areaId,
            'seats' => $seats,
            'seat_count' => count($seats),
            'unit_price' => $unitPrice,
            'unit_cost' => $unitCost,
            'mobile' => $mobile,
            // 先按现在 + 10 分钟占位，芒果受理后按受理时间重算；锁座结果未知的单就按这个时间超时释放
            'lock_expire_at' => Carbon::now()->addSeconds(MangoDriver::LOCK_TTL_SECONDS)->toDateTimeString(),
        ]);

        $order->fill(['supplier_id' => $supplier->id])->save();
        $attempt = $this->orderAttemptDao->claim((int) $order->id, (int) $supplier->id, 1);

        try {
            $result = $driver->lockSeats($showId, $seats, $mobile, $order->order_no);
        } catch (Throwable $e) {
            // 请求可能已经发出去了，只能按结果未知处理，不能当失败解冻
            $result = new DriverResult(result: UnifiedResult::Unknown, failReason: 'movie lockSeats threw: ' . $e->getMessage());
        }

        $attempt?->fill(['request_snapshot' => $result->rawRequest, 'response_snapshot' => $result->rawResponse])->save();

        if ($result->result === UnifiedResult::Processing) {
            $this->orderMovieDao->newQuery()->where('order_id', $order->id)->update([
                'lock_expire_at' => Carbon::now()->addSeconds((int) ($result->movieDetails['lock_ttl_seconds'] ?? MangoDriver::LOCK_TTL_SECONDS))->toDateTimeString(),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $this->settlementService->apply($order, $result, (int) $supplier->id, true);
        $order->refresh();

        return $this->toResponseArray($order);
    }

    /**
     * @return array<string, mixed>
     */
    protected function toResponseArray(Order $order): array
    {
        return parent::toResponseArray($order) + ['movie' => $this->presenter->present((int) $order->id)];
    }

    protected function businessLine(): string
    {
        return MovieOrderSettlementService::BUSINESS_LINE;
    }

    protected function orderNoPrefix(): string
    {
        return self::ORDER_NO_PREFIX;
    }

    /**
     * @param list<array<string, mixed>> $shows
     * @return array<string, mixed>
     */
    private function findShow(array $shows, string $showId): array
    {
        foreach ($shows as $show) {
            if ($show['show_id'] === $showId) {
                return $show;
            }
        }

        throw new OpenApiException(ErrorCode::MovieShowNotFound);
    }

    /**
     * 分区场次按所选座位的分区取该区成本；不分区取场次成本。取不到成本就没法定价，按场次不可售处理。
     *
     * @param array<string, mixed> $show
     */
    private function unitCost(array $show, ?string $areaId): string
    {
        if ($show['areas'] !== []) {
            foreach ($show['areas'] as $area) {
                if ($area['area_id'] === $areaId && $area['cost'] !== null) {
                    return $area['cost'];
                }
            }

            throw new OpenApiException(ErrorCode::MovieShowNotFound, '所选座位的分区当前不可售，请重新查询场次');
        }
        if ($show['cost'] === null) {
            throw new OpenApiException(ErrorCode::MovieShowNotFound);
        }

        return $show['cost'];
    }

    /**
     * @param array<string, mixed> $show
     */
    private function filmName(array $show): ?string
    {
        $name = $show['attributes']['film_name'] ?? $show['attributes']['filmName'] ?? null;

        return is_string($name) && $name !== '' ? mb_substr($name, 0, 128) : null;
    }
}
