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

namespace App\Controller\OpenApi;

use App\Middleware\OpenApiSignatureMiddleware;
use App\Model\Merchant;
use App\Movie\SeatSelectionValidator;
use App\OpenApi\ErrorCode;
use App\Service\OpenApi\MovieOrderService;
use App\Service\OpenApi\MovieQueryService;
use App\Service\Order\MovieOrderPlacementService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 开放 API 电影票接口（requirements.md 7.3、8.1）：查询用 GET，锁座、确认出票、释放座位用 POST。
 * 中间件挂载方式沿用 App\Controller\OpenApi\BalanceController。
 *
 * 锁座的座位参数 `seat_codes` 是英文逗号分隔的座位编码字符串，不是数组：开放 API 签名只能拼标量
 * （同快递查价的扁平参数，见 ExpressQuoteService::validate()）。场次 ID 可能含特殊字符（mango.md），
 * 商户按普通参数传、做好 URL 编码即可。
 *
 * 口径和取舍见 MovieQueryService、MovieOrderPlacementService、MovieOrderService 类注释。
 */
#[Controller(prefix: '/open-api')]
#[Middleware(OpenApiSignatureMiddleware::class)]
class MovieController extends AbstractOpenApiController
{
    #[Inject]
    protected MovieQueryService $queryService;

    #[Inject]
    protected MovieOrderPlacementService $placementService;

    #[Inject]
    protected MovieOrderService $orderService;

    #[GetMapping(path: 'movie/cities')]
    public function cities(): array
    {
        return $this->success(['cities' => $this->queryService->cities($this->merchant())]);
    }

    #[GetMapping(path: 'movie/regions')]
    public function regions(): array
    {
        $cityId = $this->str('city_id');
        if ($cityId === null) {
            return $this->fail(ErrorCode::InvalidParams, 'city_id 不能为空');
        }

        return $this->success(['regions' => $this->queryService->regions($this->merchant(), $cityId)]);
    }

    #[GetMapping(path: 'movie/cinemas')]
    public function cinemas(): array
    {
        $cityId = $this->str('city_id');
        if ($cityId === null) {
            return $this->fail(ErrorCode::InvalidParams, 'city_id 不能为空');
        }

        return $this->success($this->queryService->cinemas(
            $this->merchant(),
            $cityId,
            $this->str('region_id'),
            (int) $this->request->input('page', 1),
            (int) $this->request->input('per_page', 20)
        ));
    }

    #[GetMapping(path: 'movie/films')]
    public function films(): array
    {
        $cityId = $this->str('city_id');
        if ($cityId === null) {
            return $this->fail(ErrorCode::InvalidParams, 'city_id 不能为空');
        }

        return $this->success(['films' => $this->queryService->films($this->merchant(), $cityId)]);
    }

    #[GetMapping(path: 'movie/shows')]
    public function shows(): array
    {
        $cinemaId = $this->str('cinema_id');
        $filmId = $this->str('film_id');
        if ($cinemaId === null || $filmId === null) {
            return $this->fail(ErrorCode::InvalidParams, 'cinema_id / film_id 不能为空');
        }

        return $this->success(['shows' => $this->queryService->shows($this->merchant(), $cinemaId, $filmId)]);
    }

    #[GetMapping(path: 'movie/seats')]
    public function seats(): array
    {
        $showId = $this->str('show_id');
        if ($showId === null) {
            return $this->fail(ErrorCode::InvalidParams, 'show_id 不能为空');
        }

        return $this->success(['seats' => $this->queryService->seats($this->merchant(), $showId)]);
    }

    #[PostMapping(path: 'movie/lock')]
    public function lock(): array
    {
        $merchantOrderNo = $this->str('merchant_order_no');
        $callbackUrl = $this->str('callback_url');
        $cinemaId = $this->str('cinema_id');
        $filmId = $this->str('film_id');
        $showId = $this->str('show_id');
        $seatCodes = array_values(array_filter(array_map('trim', explode(',', (string) $this->str('seat_codes'))), static fn ($c) => $c !== ''));
        $mobile = $this->str('mobile');

        if ($merchantOrderNo === null || mb_strlen($merchantOrderNo) > 64
            || $callbackUrl === null || filter_var($callbackUrl, FILTER_VALIDATE_URL) === false
            || $cinemaId === null || $filmId === null || $showId === null
            || $mobile === null || preg_match('/^1\d{10}$/', $mobile) !== 1
        ) {
            return $this->fail(ErrorCode::InvalidParams, 'merchant_order_no/callback_url/cinema_id/film_id/show_id/mobile 缺失或格式错误');
        }
        if ($seatCodes === [] || count($seatCodes) > SeatSelectionValidator::MAX_SEATS) {
            return $this->fail(ErrorCode::InvalidParams, 'seat_codes 需要 1 ~ ' . SeatSelectionValidator::MAX_SEATS . ' 个座位编码，用英文逗号分隔');
        }

        return $this->success($this->placementService->lock(
            $this->merchant(),
            $merchantOrderNo,
            $cinemaId,
            $filmId,
            $showId,
            $seatCodes,
            $mobile,
            $callbackUrl
        ));
    }

    #[PostMapping(path: 'movie/confirm')]
    public function confirm(): array
    {
        [$orderNo, $merchantOrderNo] = [$this->str('order_no'), $this->str('merchant_order_no')];
        if ($orderNo === null && $merchantOrderNo === null) {
            return $this->fail(ErrorCode::InvalidParams, 'order_no 和 merchant_order_no 必须传一个');
        }

        return $this->success($this->orderService->confirm($this->merchant(), $orderNo, $merchantOrderNo));
    }

    #[PostMapping(path: 'movie/release')]
    public function release(): array
    {
        [$orderNo, $merchantOrderNo] = [$this->str('order_no'), $this->str('merchant_order_no')];
        if ($orderNo === null && $merchantOrderNo === null) {
            return $this->fail(ErrorCode::InvalidParams, 'order_no 和 merchant_order_no 必须传一个');
        }

        return $this->success($this->orderService->release($this->merchant(), $orderNo, $merchantOrderNo));
    }

    private function merchant(): Merchant
    {
        return $this->request->getAttribute('merchant');
    }

    private function str(string $key): ?string
    {
        $value = $this->request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
