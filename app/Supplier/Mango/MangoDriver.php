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

namespace App\Supplier\Mango;

use App\Supplier\DriverResult;
use App\Supplier\UnifiedResult;
use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\Context\ApplicationContext;
use Hyperf\Guzzle\ClientFactory;
use InvalidArgumentException;
use RuntimeException;

/**
 * 芒果电影驱动（mango.md、requirements.md 7.3），平台第三个供应商驱动。只负责"跟芒果说话"：
 * 签名、gzip、发请求、把响应翻译成平台的形状。加价、冻结、锁座前置校验（App\Movie\SeatSelectionValidator）、
 * 缓存都不在这里。
 *
 * 接电影票业务流程时要知道的几点：
 *
 * 1. **纯 API 转发**（mango.md 开头）：H5 嵌入、小程序中间页、客服系统接口都不对接。
 * 2. **请求体 gzip**：统一 POST JSON，`Content-Encoding: gzip`，三家供应商里只有它这样要求。
 * 3. **不暴露成本字段**：场次数据里的价格全是平台成本或芒果上游的参考价。归一化时只留一个
 *    `cost`（不分区取 `settle_price`，分区取该区的 `user_price`，mango.md 第 5 节已确认），
 *    `net_price`/`price`/`supplier_price`/`agent_rebate`/`limit_price` 一律丢掉——业务层拿到的
 *    `attributes` 里也剥掉了这些键，不会顺手透传给商户。
 * 4. **场次记录里的 `cinemaid`/`film_id` 不可信**（多数据源，可能跟请求的对不上）：归一化后的场次
 *    带的是**调用方查询时传入的**影院/影片 ID，记录自带的两个 ID 直接丢掉，免得被拿去发起后续请求。
 * 5. **锁座必须带分区**：座位有分区时锁座不带 `area_id` 会"订单溢价并退款"。lockSeats() 要求每个座位
 *    条目都带 `area_id` 这个键（值可以是 null 表示不分区），少了就直接抛异常——代码层面没法漏。
 * 6. **锁座没有防重复单号**：超时/解析不了一律结果未知，调用方**不能重试**；平台订单号放 `attach`
 *    （查询订单详情会带回来，用来认领）。只有 10040/10036"订单溢价"是确定的明确失败；
 *    其它失败码芒果没给完整表，按结果未知（MangoStatusMapper）。
 * 7. **快速通道、自动换座本期不支持**（mango.md 第 5 节）：锁座和确认下单固定 `auto_check_seat=0`，
 *    确认下单固定 `fast_buy=0`，没有给调用方留参数。
 * 8. **回调没有签名**：parseCallback() 只取 `order_number`，再调带签名的订单详情拿权威数据；
 *    出票后改票根会再回调一次 `000`，调用方按订单状态幂等处理。回调成功回复 {@see CALLBACK_REPLY}。
 *    影院更新回调（parseCinemaUpdate()）只发一次、不用回复。
 * 9. **返佣取查询订单详情的 `total_rebate`**，回调里的 `rebate` 可能早于最终值，不用。
 *
 * 【接口路径、响应信封和大部分字段名是推断】mango.md 只整理了能力和关键字段含义，域名和报文样例
 * 都隐藏了：每个接口的路径（PATH_* 常量）、响应信封 `{code, message, data}` 及成功码 CODE_OK、
 * 座位图坐标/可售状态字段、城市影院影片的字段名，全部集中在常量区和 normalize* 方法里，
 * 联调对不上只改这几处（同 KasushouDriver、YunyangDriver 的处理方式）。文档里明确写出的字段
 * （`settle_price`、`area_price.user_price`、`SeatCode`、`areaId`、`lovestatus`、`order_info.order_number`、
 * `final_price`、`handle_step`、`pay_state`、`refundmes`、`sytime`、`tickets`、`total_rebate`、
 * `edit_position_seat`、`credit`、`cinemaId`/`cinemaName`/`cinemaCode`）照文档拼写。
 */
class MangoDriver
{
    /** 订单回调成功必须回复这个（mango.md 第 1 节） */
    public const CALLBACK_REPLY = '{"code":1}';

    /** 锁座有效期，芒果写死 10 分钟（mango.md 第 1 节），不是平台可配的 */
    public const LOCK_TTL_SECONDS = 600;

    /** 分页上限（mango.md 第 1 节） */
    public const MAX_PAGE_SIZE = 100;

    private const PATH_CITIES = '/api/city/list';

    private const PATH_REGIONS = '/api/city/regions';

    private const PATH_CINEMAS = '/api/cinema/list';

    private const PATH_CINEMAS_BATCH = '/api/cinema/batch';

    private const PATH_FILMS_HOT = '/api/film/hot';

    private const PATH_SHOWS = '/api/show/list';

    private const PATH_SHOWS_BATCH = '/api/show/batch';

    private const PATH_SEATS = '/api/show/seats';

    private const PATH_LOCK = '/api/order/lock';

    private const PATH_CONFIRM = '/api/order/confirm';

    private const PATH_RELEASE = '/api/order/release';

    private const PATH_ORDER_DETAIL = '/api/order/detail';

    private const PATH_BALANCE = '/api/account/balance';

    /** 调用日志动作名，跟另外两家驱动同类调用用同一个词 */
    private const ACTIONS = [
        self::PATH_CITIES => 'query_cities',
        self::PATH_REGIONS => 'query_regions',
        self::PATH_CINEMAS => 'query_cinemas',
        self::PATH_CINEMAS_BATCH => 'sync_cinemas',
        self::PATH_FILMS_HOT => 'query_films',
        self::PATH_SHOWS => 'query_shows',
        self::PATH_SHOWS_BATCH => 'sync_shows',
        self::PATH_SEATS => 'query_seats',
        self::PATH_LOCK => 'place_order',
        self::PATH_CONFIRM => 'confirm_order',
        self::PATH_RELEASE => 'cancel',
        self::PATH_ORDER_DETAIL => 'query',
        self::PATH_BALANCE => 'query_balance',
    ];

    /** 成功码（推断，见类注释） */
    private const CODE_OK = '200';

    /** 限流响应里的标志文案（mango.md 第 1 节） */
    private const RATE_LIMITED_MARKER = 'rate limited';

    /** attach 透传上限 300 字节（mango.md 第 1 节） */
    private const ATTACH_MAX_BYTES = 300;

    /** 场次/座位里属于成本或不可信的键，归一化后的 attributes 里一律剥掉（见类注释第 3、4 条） */
    private const HIDDEN_KEYS = [
        'settle_price', 'net_price', 'area_price', 'price', 'user_price', 'supplier_price', 'agent_rebate',
        'limit_price', 'rule_price', 'final_price', 'maoyan_price', 'cinemaid', 'cinema_id', 'film_id',
    ];

    private readonly MangoSigner $signer;

    private readonly MangoStatusMapper $statusMapper;

    /**
     * `$tel`：芒果账户手机号，查询余额按它查（mango.md 第 1 节"余额"）。
     * `$callRecorder`：同 YunyangDriver，每次调用结束记一条调用日志。
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $agentId,
        private readonly string $appId,
        private readonly string $token,
        private readonly string $tel = '',
        private readonly ?Closure $callRecorder = null,
    ) {
        if ($this->baseUrl === '' || $this->agentId === '' || $this->appId === '' || $this->token === '') {
            throw new InvalidArgumentException('MangoDriver: base_url / agent_id / app_id / token are required.');
        }
        $this->signer = new MangoSigner();
        $this->statusMapper = new MangoStatusMapper();
    }

    // ---- 基础数据（城市、影院、影片）：原样转发，不含价格 ----

    /**
     * 全国所有城市。
     *
     * @return list<array{city_id: string, city_name: string, first_letter: null|string, is_hot: bool}>
     */
    public function queryCities(): array
    {
        return $this->normalizeAll($this->list(self::PATH_CITIES, []), $this->normalizeCity(...));
    }

    /**
     * 城市下的行政区/县。
     *
     * @return list<array{region_id: string, region_name: string}>
     */
    public function queryRegions(string $cityId): array
    {
        return $this->normalizeAll($this->list(self::PATH_REGIONS, ['city_id' => $cityId]), $this->normalizeRegion(...));
    }

    /**
     * 影院列表（分页）。
     *
     * @return list<array<string, mixed>> 形状见 normalizeCinema()
     */
    public function queryCinemas(string $cityId, int $page = 1, int $pageSize = self::MAX_PAGE_SIZE): array
    {
        return $this->normalizeAll($this->list(self::PATH_CINEMAS, ['city_id' => $cityId] + $this->paging($page, $pageSize)), $this->normalizeCinema(...));
    }

    /**
     * 批量拉取影院数据（需要芒果商务单独开权限，300 次/分钟）。限流时抛 MangoRateLimitedException。
     *
     * @return list<array<string, mixed>> 形状见 normalizeCinema()
     */
    public function batchCinemas(int $page = 1, int $pageSize = self::MAX_PAGE_SIZE): array
    {
        return $this->normalizeAll($this->list(self::PATH_CINEMAS_BATCH, $this->paging($page, $pageSize)), $this->normalizeCinema(...));
    }

    /**
     * 热映 & 待上映影片。没有批量接口，业务层按需实时查、可以短期缓存。
     *
     * @return list<array{film_id: string, film_name: null|string, attributes: array<string, mixed>}>
     */
    public function queryFilms(string $cityId): array
    {
        return $this->normalizeAll($this->list(self::PATH_FILMS_HOT, ['city_id' => $cityId]), $this->normalizeFilm(...));
    }

    // ---- 场次、座位：价格是成本，归一化 ----

    /**
     * 某影院某影片的场次。返回的场次一律挂在**传进来的** `$cinemaId`/`$filmId` 下（类注释第 4 条）。
     *
     * @return list<array{show_id: string, cinema_id: string, film_id: string, show_time: null|string,
     *     cost: null|string, areas: list<array{area_id: string, area_name: null|string, cost: null|string}>,
     *     attributes: array<string, mixed>}>
     */
    public function queryShows(string $cinemaId, string $filmId): array
    {
        return array_values(array_filter(array_map(
            fn (array $raw) => $this->normalizeShow($raw, $cinemaId, $filmId),
            $this->list(self::PATH_SHOWS, ['cinemaid' => $cinemaId, 'film_id' => $filmId])
        )));
    }

    /**
     * 批量拉取某影院全部场次（需要商务权限，1200 次/分钟，建议每小时半点起同步）。
     * 批量接口的场次不区分影片查询，影片 ID 只能用记录自带的——文档说它仅供参考，
     * 所以这里的 `film_id` 可能为 null，业务层缓存时要按影片查询接口的 ID 重新对应。
     *
     * @return list<array<string, mixed>>
     */
    public function batchShows(string $cinemaId, int $page = 1, int $pageSize = self::MAX_PAGE_SIZE): array
    {
        $shows = [];
        foreach ($this->list(self::PATH_SHOWS_BATCH, ['cinemaid' => $cinemaId] + $this->paging($page, $pageSize)) as $raw) {
            $show = $this->normalizeShow($raw, $cinemaId, null);
            if ($show !== null) {
                $show['reference_film_id'] = $this->str($raw['film_id'] ?? null);
                $shows[] = $show;
            }
        }

        return $shows;
    }

    /**
     * 场次座位图，始终实时（mango.md 第 5 节：座位不缓存）。
     *
     * @return list<array{seat_code: string, row: int, col: int, row_label: null|string, col_label: null|string,
     *     area_id: null|string, love_status: int, available: bool}>
     */
    public function querySeats(string $showId): array
    {
        $seats = [];
        foreach ($this->list(self::PATH_SEATS, ['showid' => $showId]) as $raw) {
            $seat = $this->normalizeSeat($raw);
            if ($seat !== null) {
                $seats[] = $seat;
            }
        }

        return $seats;
    }

    // ---- 下单 ----

    /**
     * 锁座下单。`$seats` 是 querySeats() 的条目（通常是 SeatSelectionValidator::validate() 的返回值），
     * **每个都必须带 `area_id` 键**（见类注释第 5 条）。`$mobile` 是取票手机号；`$platformOrderNo`
     * 放 `attach`，查询订单详情会原样带回。
     *
     * 成功：处理中，`supplierOrderNo` = 芒果 `order_number`，`actualCost` = 芒果确认的结算价 `final_price`
     * （调用方拿它跟冻结时算的成本核对）。
     *
     * @param list<array<string, mixed>> $seats
     */
    public function lockSeats(string $showId, array $seats, string $mobile, string $platformOrderNo): DriverResult
    {
        if ($seats === []) {
            throw new InvalidArgumentException('MangoDriver::lockSeats: no seats.');
        }
        if (strlen($platformOrderNo) > self::ATTACH_MAX_BYTES) {
            throw new InvalidArgumentException('MangoDriver::lockSeats: attach exceeds 300 bytes.');
        }

        $seatData = [];
        foreach ($seats as $seat) {
            if (! array_key_exists('area_id', $seat) || ! isset($seat['seat_code'])) {
                // 分区座位漏传 area_id 会"订单溢价并退款"（mango.md 第 4 节），不允许调用方漏掉这个键
                throw new InvalidArgumentException('MangoDriver::lockSeats: every seat must carry seat_code and area_id (null when not partitioned).');
            }
            $item = ['SeatCode' => (string) $seat['seat_code'], 'lovestatus' => (int) ($seat['love_status'] ?? 0)];
            if ($seat['area_id'] !== null && $seat['area_id'] !== '') {
                $item['area_id'] = (string) $seat['area_id'];
            }
            $seatData[] = $item;
        }

        $params = [
            'room_id' => $showId,
            'tel' => $mobile,
            'seat_data' => $seatData,
            'auto_check_seat' => 0,
            'attach' => $platformOrderNo,
        ];
        $response = $this->send(self::PATH_LOCK, $params);

        if ($response['httpStatus'] !== 200 || $response['code'] === null) {
            return new DriverResult(
                result: UnifiedResult::Unknown,
                failReason: $this->describeTransportFailure($response['httpStatus']),
                rawRequest: $params,
                rawResponse: $response['raw'],
            );
        }

        if (! $response['ok']) {
            $priceChanged = $this->statusMapper->isPriceChanged($response['code']);

            return new DriverResult(
                // 只有"订单溢价"是确定的失败，其它码没有文档，拿不准就是结果未知（类注释第 6 条）
                result: $priceChanged ? UnifiedResult::DefiniteFailure : UnifiedResult::Unknown,
                failReason: 'mango: ' . $this->describeBusinessFailure($response),
                rawRequest: $params,
                rawResponse: $response['raw'],
                movieDetails: ['price_changed' => $priceChanged],
            );
        }

        $data = is_array($response['data']) ? $response['data'] : [];
        $orderInfo = is_array($data['order_info'] ?? null) ? $data['order_info'] : $data;
        $orderNumber = $this->str($orderInfo['order_number'] ?? null);
        if ($orderNumber === null) {
            // 说成功却没给单号：没法确认也没法释放，只能当结果未知
            return new DriverResult(
                result: UnifiedResult::Unknown,
                failReason: 'mango: lock succeeded without order_number',
                rawRequest: $params,
                rawResponse: $response['raw'],
            );
        }

        return new DriverResult(
            result: UnifiedResult::Processing,
            supplierOrderNo: $orderNumber,
            actualCost: $this->money($orderInfo['final_price'] ?? $data['final_price'] ?? null),
            rawRequest: $params,
            rawResponse: $response['raw'],
            movieDetails: [
                'handle_step' => MangoStatusMapper::STEP_PENDING_PAY,
                'price_changed' => false,
                'lock_ttl_seconds' => self::LOCK_TTL_SECONDS,
            ],
        );
    }

    /**
     * 确认下单（出票）。受理成功是处理中，出票结果靠回调/查询订单详情。
     * 失败码芒果没给表，除了传输失败都按结果未知——确认下单被拒的真实原因（比如锁座已超时）
     * 查询订单详情会给出确定的 `handle_step`。
     */
    public function confirmOrder(string $orderNumber): DriverResult
    {
        $params = ['order_number' => $orderNumber, 'fast_buy' => 0, 'auto_check_seat' => 0];
        $response = $this->send(self::PATH_CONFIRM, $params);

        return new DriverResult(
            result: $response['ok'] ? UnifiedResult::Processing : UnifiedResult::Unknown,
            supplierOrderNo: $orderNumber,
            failReason: $response['ok'] ? null : (
                $response['httpStatus'] !== 200 || $response['code'] === null
                    ? $this->describeTransportFailure($response['httpStatus'])
                    : 'mango: ' . $this->describeBusinessFailure($response)
            ),
            rawRequest: $params,
            rawResponse: $response['raw'],
        );
    }

    /**
     * 释放座位（商户放弃，或锁座超时前主动释放）。
     *
     * @return array{released: bool, message: string}
     */
    public function releaseSeats(string $orderNumber): array
    {
        $response = $this->send(self::PATH_RELEASE, ['order_number' => $orderNumber]);

        return [
            'released' => $response['ok'],
            'message' => $response['ok'] ? '' : $this->describeBusinessFailure($response),
        ];
    }

    /**
     * 查询订单详情（带签名，权威数据）。查询本身失败是结果未知，接着查；芒果明确说查不到这个单
     * 也只按结果未知——文档没给"查无此单"的码，不能把它当出票失败去解冻。
     */
    public function queryOrder(string $orderNumber): DriverResult
    {
        $params = ['order_number' => $orderNumber];
        $response = $this->send(self::PATH_ORDER_DETAIL, $params);

        if (! $response['ok']) {
            return new DriverResult(
                result: UnifiedResult::Unknown,
                supplierOrderNo: $orderNumber,
                failReason: $response['httpStatus'] !== 200 || $response['code'] === null
                    ? $this->describeTransportFailure($response['httpStatus'])
                    : 'mango: ' . $this->describeBusinessFailure($response),
                rawRequest: $params,
                rawResponse: $response['raw'],
            );
        }

        $data = is_array($response['data']) ? $response['data'] : [];
        $handleStep = $this->int($data['handle_step'] ?? null);
        $result = $this->statusMapper->map($handleStep);

        return new DriverResult(
            result: $result,
            supplierOrderNo: $this->str($data['order_number'] ?? null) ?? $orderNumber,
            failReason: $result === UnifiedResult::DefiniteFailure
                ? 'mango: handle_step ' . $handleStep . ', refundmes ' . ($this->str($data['refundmes'] ?? null) ?? 'null')
                : null,
            actualCost: $this->money($data['final_price'] ?? null),
            // 返佣以查询接口的 total_rebate 为准，只在出票成功后才是实际到账值（类注释第 9 条）
            supplierRebate: $result === UnifiedResult::Success ? $this->money($data['total_rebate'] ?? null) : null,
            rawRequest: $params,
            rawResponse: $response['raw'],
            movieDetails: [
                'handle_step' => $handleStep,
                'pay_state' => $this->int($data['pay_state'] ?? null),
                'refundmes' => $this->str($data['refundmes'] ?? null),
                'remaining_pay_seconds' => $this->int($data['sytime'] ?? null),
                'tickets' => is_array($data['tickets'] ?? null) ? array_values($data['tickets']) : [],
                'seats' => $data['edit_position_seat'] ?? null,
                'platform_order_no' => $this->str($data['attach'] ?? null),
                'lock_expired' => $this->statusMapper->isLockExpired($handleStep),
            ],
        );
    }

    /**
     * 订单回调：不信 payload，只取单号去查（类注释第 8 条）。取不到单号返回 null，调用方不能回复成功。
     *
     * @param array<string, mixed> $payload
     */
    public function parseCallback(array $payload): ?DriverResult
    {
        $orderNumber = $this->str($payload['order_number'] ?? null);

        return $orderNumber === null ? null : $this->queryOrder($orderNumber);
    }

    /**
     * 影院更新回调（只发一次、不补发、不用回复）。只是"这家影院变了"的通知，拿到后由业务层
     * 重新拉这家影院的数据；字段照文档拼写。取不到影院 ID 返回 null。
     *
     * @param array<string, mixed> $payload
     * @return null|array{cinema_id: string, cinema_name: null|string, cinema_code: null|string}
     */
    public function parseCinemaUpdate(array $payload): ?array
    {
        $cinemaId = $this->str($payload['cinemaId'] ?? null);
        if ($cinemaId === null) {
            return null;
        }

        return [
            'cinema_id' => $cinemaId,
            'cinema_name' => $this->str($payload['cinemaName'] ?? null),
            'cinema_code' => $this->str($payload['cinemaCode'] ?? null),
        ];
    }

    /**
     * 账户余额，取 `credit`。
     *
     * @throws RuntimeException 接口失败或字段解析不出来，同 YunyangDriver::queryBalance()
     */
    public function queryBalance(): string
    {
        if ($this->tel === '') {
            throw new RuntimeException('MangoDriver::queryBalance: tel is not configured.');
        }
        $response = $this->send(self::PATH_BALANCE, ['tel' => $this->tel]);
        if (! $response['ok']) {
            throw new RuntimeException('MangoDriver::queryBalance: ' . $this->describeBusinessFailure($response));
        }

        $credit = is_array($response['data']) ? $this->money($response['data']['credit'] ?? null) : null;
        if ($credit === null) {
            throw new RuntimeException('MangoDriver::queryBalance: credit field missing or unparseable in response.');
        }

        return $credit;
    }

    protected function httpClient(): ClientInterface
    {
        return ApplicationContext::getContainer()->get(ClientFactory::class)->create();
    }

    /**
     * 发一次带签名、gzip 压缩的请求。
     *
     * @param array<string, mixed> $params
     * @return array{httpStatus: null|int, code: null|string, message: string, data: mixed, ok: bool, raw: array<string, mixed>}
     */
    private function send(string $path, array $params): array
    {
        $body = ['agent_id' => $this->agentId, 'app_id' => $this->appId] + $params;
        $body['signid'] = $this->signer->sign($body, $this->token);
        $json = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $startedAt = microtime(true);

        try {
            $httpResponse = $this->httpClient()->request('POST', rtrim($this->baseUrl, '/') . $path, [
                'headers' => ['Content-Type' => 'application/json; charset=utf-8', 'Content-Encoding' => 'gzip'],
                'body' => gzencode($json),
                'timeout' => 10,
                'connect_timeout' => 5,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            $this->recordCall($path, $body, ['exception' => $e->getMessage()], $startedAt);

            return ['httpStatus' => null, 'code' => null, 'message' => $e->getMessage(), 'data' => null, 'ok' => false, 'raw' => ['exception' => $e->getMessage()]];
        }

        $httpStatus = $httpResponse->getStatusCode();
        $rawBody = (string) $httpResponse->getBody();
        $decoded = json_decode($rawBody, true);
        $code = is_array($decoded) && isset($decoded['code']) && (is_string($decoded['code']) || is_int($decoded['code']))
            ? (string) $decoded['code']
            : null;
        $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['msg'] ?? '') : '';

        $this->recordCall($path, $body, ['http_status' => $httpStatus, 'body' => is_array($decoded) ? $decoded : $rawBody], $startedAt);

        if ($code === null && stripos($message, self::RATE_LIMITED_MARKER) !== false) {
            throw new MangoRateLimitedException('MangoDriver: ' . $message);
        }

        return [
            'httpStatus' => $httpStatus,
            'code' => $code,
            'message' => $message,
            'data' => is_array($decoded) ? ($decoded['data'] ?? null) : null,
            'ok' => $httpStatus === 200 && $code === self::CODE_OK,
            'raw' => ['http_status' => $httpStatus, 'body' => $rawBody],
        ];
    }

    /**
     * 列表类接口：失败直接抛异常（查询是给商户实时转发的，失败了由业务层决定报错还是 30 秒后重试，
     * mango.md "场次下架"那一条），成功返回数据列表（兼容直接是数组和包一层 list 两种形状）。
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function list(string $path, array $params): array
    {
        $response = $this->send($path, $params);
        if (! $response['ok']) {
            throw new RuntimeException('MangoDriver ' . $path . ': ' . ($response['httpStatus'] !== 200 || $response['code'] === null
                ? $this->describeTransportFailure($response['httpStatus'])
                : $this->describeBusinessFailure($response)));
        }

        $data = $response['data'];
        if (is_array($data) && isset($data['list']) && is_array($data['list'])) {
            $data = $data['list'];
        }
        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeAll(array $rows, Closure $normalize): array
    {
        return array_values(array_filter(array_map($normalize, $rows)));
    }

    /**
     * @param array<string, mixed> $raw
     * @return null|array{city_id: string, city_name: string, first_letter: null|string, is_hot: bool}
     */
    private function normalizeCity(array $raw): ?array
    {
        $id = $this->str($raw['city_id'] ?? $raw['cityId'] ?? $raw['id'] ?? null);
        $name = $this->str($raw['city_name'] ?? $raw['cityName'] ?? $raw['name'] ?? null);
        if ($id === null || $name === null) {
            return null;
        }
        $letter = $this->str($raw['first_letter'] ?? $raw['firstLetter'] ?? $raw['pinyin'] ?? null);

        return [
            'city_id' => $id,
            'city_name' => $name,
            'first_letter' => $letter === null ? null : strtoupper(substr($letter, 0, 1)),
            'is_hot' => (int) ($raw['is_hot'] ?? $raw['isHot'] ?? $raw['hot'] ?? 0) === 1,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return null|array{region_id: string, region_name: string}
     */
    private function normalizeRegion(array $raw): ?array
    {
        $id = $this->str($raw['region_id'] ?? $raw['regionId'] ?? $raw['area_id'] ?? $raw['id'] ?? null);
        $name = $this->str($raw['region_name'] ?? $raw['regionName'] ?? $raw['area_name'] ?? $raw['name'] ?? null);

        return $id === null || $name === null ? null : ['region_id' => $id, 'region_name' => $name];
    }

    /**
     * @param array<string, mixed> $raw
     * @return null|array{cinema_id: string, cinema_code: null|string, cinema_name: string, city_id: null|string,
     *     region_id: null|string, address: null|string, tel: null|string, longitude: null|string, latitude: null|string,
     *     service_info: null|array<mixed>}
     */
    private function normalizeCinema(array $raw): ?array
    {
        $id = $this->str($raw['cinemaId'] ?? $raw['cinema_id'] ?? $raw['cinemaid'] ?? $raw['id'] ?? null);
        $name = $this->str($raw['cinemaName'] ?? $raw['cinema_name'] ?? $raw['name'] ?? null);
        if ($id === null || $name === null) {
            return null;
        }
        $coordinate = static fn (mixed $v): ?string => is_numeric($v) ? (string) $v : null;
        $service = $raw['service_info'] ?? $raw['serviceInfo'] ?? null;

        return [
            'cinema_id' => $id,
            'cinema_code' => $this->str($raw['cinemaCode'] ?? $raw['cinema_code'] ?? null),
            'cinema_name' => $name,
            'city_id' => $this->str($raw['city_id'] ?? $raw['cityId'] ?? null),
            'region_id' => $this->str($raw['region_id'] ?? $raw['regionId'] ?? $raw['area_id'] ?? null),
            'address' => $this->str($raw['address'] ?? null),
            'tel' => $this->str($raw['tel'] ?? $raw['phone'] ?? null),
            'longitude' => $coordinate($raw['longitude'] ?? $raw['lng'] ?? null),
            'latitude' => $coordinate($raw['latitude'] ?? $raw['lat'] ?? null),
            'service_info' => is_array($service) ? $service : null,
        ];
    }

    /**
     * 影片信息不含价格，除了统一出 film_id / film_name，其余原样放 attributes 给商户展示。
     *
     * @param array<string, mixed> $raw
     * @return null|array{film_id: string, film_name: null|string, attributes: array<string, mixed>}
     */
    private function normalizeFilm(array $raw): ?array
    {
        $id = $this->str($raw['film_id'] ?? $raw['filmId'] ?? $raw['id'] ?? null);
        if ($id === null) {
            return null;
        }

        return [
            'film_id' => $id,
            'film_name' => $this->str($raw['film_name'] ?? $raw['filmName'] ?? $raw['name'] ?? null),
            'attributes' => array_diff_key($raw, array_flip([...self::HIDDEN_KEYS, 'film_id', 'filmId', 'id', 'film_name', 'filmName', 'name'])),
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return null|array<string, mixed>
     */
    private function normalizeShow(array $raw, string $cinemaId, ?string $filmId): ?array
    {
        $showId = $this->str($raw['showid'] ?? $raw['show_id'] ?? null);
        if ($showId === null) {
            return null;
        }

        $areas = [];
        foreach (is_array($raw['area_price'] ?? null) ? $raw['area_price'] : [] as $area) {
            if (! is_array($area)) {
                continue;
            }
            $areaId = $this->str($area['area_id'] ?? $area['areaId'] ?? null);
            if ($areaId === null) {
                continue;
            }
            $areas[] = [
                'area_id' => $areaId,
                'area_name' => $this->str($area['area_name'] ?? $area['name'] ?? null),
                // 分区成本 = 该区的 user_price（mango.md 第 5 节）
                'cost' => $this->money($area['user_price'] ?? null),
            ];
        }

        return [
            'show_id' => $showId,
            'cinema_id' => $cinemaId,
            'film_id' => $filmId,
            'show_time' => $this->str($raw['show_time'] ?? $raw['showtime'] ?? null),
            // 不分区成本 = settle_price
            'cost' => $this->money($raw['settle_price'] ?? null),
            'areas' => $areas,
            'attributes' => array_diff_key($raw, array_flip([...self::HIDDEN_KEYS, 'showid', 'show_id', 'show_time', 'showtime'])),
        ];
    }

    /**
     * 座位条目。坐标取座位图格子位置（`GraphRow`/`GraphCol`，没有时退到 `RowNum`/`ColumnNum`），
     * 可售看 `Status`（`N` 或 0 为可售）——这几个字段名和取值都是推断，见类注释。
     *
     * @param array<string, mixed> $raw
     * @return null|array<string, mixed>
     */
    private function normalizeSeat(array $raw): ?array
    {
        $code = $this->str($raw['SeatCode'] ?? null);
        $row = $this->int($raw['GraphRow'] ?? $raw['RowNum'] ?? null);
        $col = $this->int($raw['GraphCol'] ?? $raw['ColumnNum'] ?? null);
        if ($code === null || $row === null || $col === null) {
            return null;
        }
        $status = $raw['Status'] ?? null;

        return [
            'seat_code' => $code,
            'row' => $row,
            'col' => $col,
            'row_label' => $this->str($raw['RowId'] ?? null),
            'col_label' => $this->str($raw['ColumnId'] ?? null),
            'area_id' => $this->str($raw['areaId'] ?? $raw['area_id'] ?? null),
            'love_status' => $this->int($raw['lovestatus'] ?? null) ?? 0,
            'available' => $status === 'N' || $status === 0 || $status === '0',
        ];
    }

    /**
     * @return array{page: int, page_size: int}
     */
    private function paging(int $page, int $pageSize): array
    {
        return ['page' => max(1, $page), 'page_size' => min(self::MAX_PAGE_SIZE, max(1, $pageSize))];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $response
     */
    private function recordCall(string $path, array $body, array $response, float $startedAt): void
    {
        if ($this->callRecorder === null) {
            return;
        }

        ($this->callRecorder)(
            self::ACTIONS[$path] ?? $path,
            ['path' => $path, 'body' => $body],
            $response,
            (int) round((microtime(true) - $startedAt) * 1000)
        );
    }

    private function describeTransportFailure(?int $httpStatus): string
    {
        return $httpStatus === null
            ? 'mango: network error or timeout'
            : sprintf('mango: unexpected http status %d or unparseable response', $httpStatus);
    }

    /**
     * @param array{code: null|string, message: string} $response
     */
    private function describeBusinessFailure(array $response): string
    {
        return sprintf('code %s: %s', $response['code'] ?? 'null', $response['message'] !== '' ? $response['message'] : '(no message)');
    }

    private function str(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    private function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : null;
    }

    /**
     * 金额一律字符串两位小数，不转 float。
     */
    private function money(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $value = (string) $value;

        return is_numeric($value) ? bcadd($value, '0', 2) : null;
    }
}
