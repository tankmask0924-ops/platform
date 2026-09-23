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

namespace HyperfTest\Cases\Supplier\Mango;

use App\Supplier\Mango\MangoDriver;
use App\Supplier\Mango\MangoRateLimitedException;
use App\Supplier\Mango\MangoSigner;
use App\Supplier\UnifiedResult;
use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hyperf\Testing\TestCase;
use InvalidArgumentException;
use Mockery;
use RuntimeException;

/**
 * 芒果电影驱动（mango.md）：HTTP 客户端 mock，不发真实请求。重点钉住 mango.md 里写成风险的几条：
 * gzip + 签名信封、成本字段不外泄、场次挂在查询时的 ID 下、锁座必须带分区、锁座失败只有"订单溢价"
 * 是明确失败、快速通道和自动换座固定关闭、返佣只取出票成功后的 total_rebate、回调不可信。
 *
 * @internal
 * @coversNothing
 */
class MangoDriverTest extends TestCase
{
    private const BASE_URL = 'https://mango.example.invalid';

    private const TOKEN = 'test-token';

    public function testRequestIsGzippedAndSigned()
    {
        $captured = null;
        $driver = $this->driverReturning(['code' => 200, 'data' => []], $captured);

        $driver->queryCities();

        $this->assertSame('gzip', $captured['headers']['Content-Encoding']);
        $body = json_decode(gzdecode($captured['body']), true);
        $this->assertSame('agent-1', $body['agent_id']);
        $this->assertSame('app-1', $body['app_id']);
        $signid = $body['signid'];
        unset($body['signid']);
        $this->assertSame((new MangoSigner())->sign($body, self::TOKEN), $signid);
    }

    /**
     * 城市、影院、影片归一化成平台自己的字段名，影片里的价格类字段也剥掉。
     */
    public function testBaseDataIsNormalized()
    {
        $cities = $this->driverReturning(['code' => 200, 'data' => [['cityId' => 440300, 'cityName' => '深圳', 'pinyin' => 'shenzhen', 'isHot' => 1], ['cityName' => '没有 ID']]])->queryCities();
        $this->assertSame([['city_id' => '440300', 'city_name' => '深圳', 'first_letter' => 'S', 'is_hot' => true]], $cities);

        $cinema = $this->driverReturning(['code' => 200, 'data' => ['list' => [[
            'cinemaId' => 1001, 'cinemaName' => '万达影城', 'cinemaCode' => '44001', 'regionId' => 'NS', 'address' => '某路 1 号', 'lng' => '113.93', 'lat' => '22.53',
        ]]]])->queryCinemas('440300')[0];
        $this->assertSame('1001', $cinema['cinema_id']);
        $this->assertSame('44001', $cinema['cinema_code']);
        $this->assertSame(['113.93', '22.53'], [$cinema['longitude'], $cinema['latitude']]);

        $film = $this->driverReturning(['code' => 200, 'data' => [['film_id' => 'F1', 'film_name' => '长安三万里', 'poster' => 'p.jpg', 'price' => '60']]])->queryFilms('440300')[0];
        $this->assertSame(['film_id' => 'F1', 'film_name' => '长安三万里', 'attributes' => ['poster' => 'p.jpg']], $film);
    }

    /**
     * 场次价格只留一个 cost（不分区 settle_price，分区 user_price），其余价格字段和场次记录自带的
     * 影院/影片 ID 都不能出现在归一化结果里；场次挂在查询时传入的 ID 下。
     */
    public function testShowsExposeOnlyCostAndTheQueriedIds()
    {
        $driver = $this->driverReturning(['code' => 200, 'data' => [[
            'showid' => 'S#1',
            'show_time' => '2026-09-30 19:30:00',
            'cinemaid' => 'WRONG-CINEMA',
            'film_id' => 'WRONG-FILM',
            'settle_price' => '35.5',
            'net_price' => '60',
            'hall_name' => '1 号厅',
            'area_price' => [
                ['area_id' => 'A1', 'area_name' => '中心区', 'user_price' => '42.00', 'supplier_price' => '30', 'agent_rebate' => '2', 'price' => '70', 'limit_price' => '50'],
            ],
        ]]]);

        $show = $driver->queryShows('C-1', 'F-1')[0];

        $this->assertSame('S#1', $show['show_id']);
        $this->assertSame(['C-1', 'F-1'], [$show['cinema_id'], $show['film_id']]);
        $this->assertSame('35.50', $show['cost']);
        $this->assertSame([['area_id' => 'A1', 'area_name' => '中心区', 'cost' => '42.00']], $show['areas']);
        $this->assertSame(['hall_name' => '1 号厅'], $show['attributes']);
        $encoded = json_encode($show);
        foreach (['WRONG-CINEMA', 'WRONG-FILM', '"60"', 'supplier_price', 'agent_rebate', 'limit_price'] as $leak) {
            $this->assertStringNotContainsString($leak, $encoded);
        }
    }

    public function testSeatsAreNormalized()
    {
        $driver = $this->driverReturning(['code' => 200, 'data' => [
            ['SeatCode' => '01-05', 'GraphRow' => 1, 'GraphCol' => 5, 'RowId' => '1', 'ColumnId' => '5', 'areaId' => 'A1', 'lovestatus' => 1, 'Status' => 'N'],
            ['SeatCode' => '01-06', 'GraphRow' => 1, 'GraphCol' => 6, 'RowId' => '1', 'ColumnId' => '6', 'lovestatus' => '2', 'Status' => 'LK'],
            ['GraphRow' => 1, 'GraphCol' => 7],
        ]]);

        $seats = $driver->querySeats('S#1');

        $this->assertCount(2, $seats, '没有座位编码的格子丢掉');
        $this->assertSame(['seat_code' => '01-05', 'row' => 1, 'col' => 5, 'row_label' => '1', 'col_label' => '5', 'area_id' => 'A1', 'love_status' => 1, 'available' => true], $seats[0]);
        $this->assertNull($seats[1]['area_id']);
        $this->assertSame(2, $seats[1]['love_status']);
        $this->assertFalse($seats[1]['available']);
    }

    /**
     * 分区座位锁座必须带 area_id；快速通道、自动换座固定关；平台单号放 attach。
     */
    public function testLockSeatsCarriesAreaIdAndFixedFlags()
    {
        $captured = null;
        $driver = $this->driverReturning(['code' => 200, 'data' => ['order_info' => ['order_number' => 'MG-1', 'final_price' => '84']]], $captured);

        $result = $driver->lockSeats('S#1', [
            ['seat_code' => '01-05', 'area_id' => 'A1', 'love_status' => 1],
            ['seat_code' => '01-06', 'area_id' => 'A1', 'love_status' => 2],
        ], '13800000000', 'M20260923000001');

        $body = json_decode(gzdecode($captured['body']), true);
        $this->assertSame([
            ['SeatCode' => '01-05', 'lovestatus' => 1, 'area_id' => 'A1'],
            ['SeatCode' => '01-06', 'lovestatus' => 2, 'area_id' => 'A1'],
        ], $body['seat_data']);
        $this->assertSame(0, $body['auto_check_seat']);
        $this->assertSame('M20260923000001', $body['attach']);
        $this->assertSame('S#1', $body['room_id']);

        $this->assertSame(UnifiedResult::Processing, $result->result);
        $this->assertSame('MG-1', $result->supplierOrderNo);
        $this->assertSame('84.00', $result->actualCost);
        $this->assertSame(600, $result->movieDetails['lock_ttl_seconds']);
    }

    public function testLockSeatsRejectsSeatWithoutAreaKey()
    {
        $driver = $this->driverReturning(['code' => 200, 'data' => []]);

        $this->expectException(InvalidArgumentException::class);
        $driver->lockSeats('S#1', [['seat_code' => '01-05', 'love_status' => 0]], '13800000000', 'M1');
    }

    public function testUnpartitionedSeatOmitsAreaId()
    {
        $captured = null;
        $driver = $this->driverReturning(['code' => 200, 'data' => ['order_info' => ['order_number' => 'MG-1']]], $captured);

        $driver->lockSeats('S#1', [['seat_code' => '01-05', 'area_id' => null, 'love_status' => 0]], '13800000000', 'M1');

        $this->assertArrayNotHasKey('area_id', json_decode(gzdecode($captured['body']), true)['seat_data'][0]);
    }

    /**
     * 只有 10040 / 10036（订单溢价）是明确失败；其它芒果没给含义的码、超时都是结果未知（不能重试）。
     */
    public function testLockFailureClassification()
    {
        $priceChanged = $this->driverReturning(['code' => 10040, 'message' => '订单溢价'])
            ->lockSeats('S#1', [['seat_code' => '01-05', 'area_id' => null]], '13800000000', 'M1');
        $this->assertSame(UnifiedResult::DefiniteFailure, $priceChanged->result);
        $this->assertTrue($priceChanged->movieDetails['price_changed']);

        $undocumented = $this->driverReturning(['code' => 99999, 'message' => '未知错误'])
            ->lockSeats('S#1', [['seat_code' => '01-05', 'area_id' => null]], '13800000000', 'M1');
        $this->assertSame(UnifiedResult::Unknown, $undocumented->result);

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->once()->andThrow(new ConnectException('timeout', new Request('POST', self::BASE_URL)));
        $timeout = $this->makeDriver($client)->lockSeats('S#1', [['seat_code' => '01-05', 'area_id' => null]], '13800000000', 'M1');
        $this->assertSame(UnifiedResult::Unknown, $timeout->result);
    }

    public function testConfirmOrderFixesFastBuyOff()
    {
        $captured = null;
        $result = $this->driverReturning(['code' => 200, 'data' => []], $captured)->confirmOrder('MG-1');

        $body = json_decode(gzdecode($captured['body']), true);
        $this->assertSame(0, $body['fast_buy']);
        $this->assertSame(0, $body['auto_check_seat']);
        $this->assertSame(UnifiedResult::Processing, $result->result);

        $rejected = $this->driverReturning(['code' => 500, 'message' => 'x'])->confirmOrder('MG-1');
        $this->assertSame(UnifiedResult::Unknown, $rejected->result, '确认被拒的原因以查询订单详情为准');
    }

    /**
     * 返佣只在出票成功后取 total_rebate；handle_step 按映射表。
     */
    public function testQueryOrderMapsHandleStepAndRebate()
    {
        $issued = $this->driverReturning(['code' => 200, 'data' => [
            'order_number' => 'MG-1', 'handle_step' => 3, 'pay_state' => 1, 'total_rebate' => '3.2', 'final_price' => '84',
            'tickets' => [['code' => 'T-123', 'verify' => '8888']], 'attach' => 'M1',
        ]])->queryOrder('MG-1');
        $this->assertSame(UnifiedResult::Success, $issued->result);
        $this->assertSame('3.20', $issued->supplierRebate);
        $this->assertSame([['code' => 'T-123', 'verify' => '8888']], $issued->movieDetails['tickets']);
        $this->assertSame('M1', $issued->movieDetails['platform_order_no']);

        $issuing = $this->driverReturning(['code' => 200, 'data' => ['handle_step' => 2, 'total_rebate' => '3.2']])->queryOrder('MG-1');
        $this->assertSame(UnifiedResult::Processing, $issuing->result);
        $this->assertNull($issuing->supplierRebate, '出票前的返佣不是最终值');

        $timeout = $this->driverReturning(['code' => 200, 'data' => ['handle_step' => -1]])->queryOrder('MG-1');
        $this->assertSame(UnifiedResult::DefiniteFailure, $timeout->result);
        $this->assertTrue($timeout->movieDetails['lock_expired']);

        $notFound = $this->driverReturning(['code' => 404, 'message' => '订单不存在'])->queryOrder('MG-1');
        $this->assertSame(UnifiedResult::Unknown, $notFound->result, '查不到不等于出票失败，不能据此解冻');
    }

    /**
     * 回调没有签名：payload 里的状态不采信，只拿单号去查。
     */
    public function testCallbackIsOnlyATrigger()
    {
        $captured = null;
        $driver = $this->driverReturning(['code' => 200, 'data' => ['order_number' => 'MG-1', 'handle_step' => 2]], $captured);

        $result = $driver->parseCallback(['order_number' => 'MG-1', 'code' => '000', 'rebate' => '99']);

        $this->assertSame(UnifiedResult::Processing, $result->result, '伪造的出票成功回调不被采信');
        $this->assertSame('MG-1', json_decode(gzdecode($captured['body']), true)['order_number']);
        $this->assertNull($driver->parseCallback(['code' => '000']));
    }

    public function testCinemaUpdateCallback()
    {
        $driver = $this->driverReturning(['code' => 200]);

        $this->assertSame(
            ['cinema_id' => '1001', 'cinema_name' => '万达影城', 'cinema_code' => '44001'],
            $driver->parseCinemaUpdate(['cinemaId' => 1001, 'cinemaName' => '万达影城', 'cinemaCode' => '44001'])
        );
        $this->assertNull($driver->parseCinemaUpdate([]));
    }

    public function testQueryBalanceReadsCredit()
    {
        $captured = null;
        $this->assertSame('1234.50', $this->driverReturning(['code' => 200, 'data' => ['credit' => '1234.5']], $captured)->queryBalance());
        $this->assertSame('13900000000', json_decode(gzdecode($captured['body']), true)['tel']);

        $this->expectException(RuntimeException::class);
        $this->driverReturning(['code' => 200, 'data' => []])->queryBalance();
    }

    public function testRateLimitIsASeparateException()
    {
        $this->expectException(MangoRateLimitedException::class);
        $this->driverReturning(['message' => 'Requests rate limited. stage:trafficcontrol'])->batchCinemas();
    }

    public function testListFailureThrows()
    {
        $this->expectException(RuntimeException::class);
        $this->driverReturning(['code' => 500, 'message' => '场次不存在'])->queryShows('C-1', 'F-1');
    }

    public function testCallsAreRecordedWithActionNames()
    {
        $recorded = [];
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->andReturn(new Response(200, [], json_encode(['code' => 200, 'data' => []])));
        $driver = $this->makeDriver($client, static function (string $action, array $request) use (&$recorded) {
            $recorded[] = [$action, $request['body']['attach'] ?? null];
        });

        $driver->querySeats('S#1');
        $driver->lockSeats('S#1', [['seat_code' => '01-05', 'area_id' => null]], '13800000000', 'M1');

        $this->assertSame([['query_seats', null], ['place_order', 'M1']], $recorded);
    }

    public function testMissingCredentialsAreRejected()
    {
        $this->expectException(InvalidArgumentException::class);
        new MangoDriver(self::BASE_URL, '', 'app-1', self::TOKEN);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function driverReturning(array $body, ?array &$captured = null): MangoDriver
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->andReturnUsing(function (string $method, string $url, array $options) use ($body, &$captured) {
            $captured = $options;

            return new Response(200, [], json_encode($body));
        });

        return $this->makeDriver($client);
    }

    private function makeDriver(ClientInterface $client, ?Closure $callRecorder = null): MangoDriver
    {
        return new class(self::BASE_URL, 'agent-1', 'app-1', self::TOKEN, '13900000000', $callRecorder, $client) extends MangoDriver {
            public function __construct(string $baseUrl, string $agentId, string $appId, string $token, string $tel, ?Closure $callRecorder, private ClientInterface $stubClient)
            {
                parent::__construct($baseUrl, $agentId, $appId, $token, $tel, $callRecorder);
            }

            protected function httpClient(): ClientInterface
            {
                return $this->stubClient;
            }
        };
    }
}
