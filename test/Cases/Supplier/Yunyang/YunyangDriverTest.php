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

namespace HyperfTest\Cases\Supplier\Yunyang;

use App\Supplier\UnifiedResult;
use App\Supplier\Yunyang\YunyangDriver;
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
 * YunyangDriver 全部走 Mockery 双重的 GuzzleHttp\ClientInterface，不发真实网络请求
 * （云洋沙箱只验签名、没有回调推送，yunyang.md 第 1 节）。HTTP 客户端替换方式跟
 * KasushouDriverTest 一样：匿名子类覆盖 protected httpClient()。
 *
 * 覆盖 yunyang.md 第 1 节的"返回格式"（成功码不统一）、第 2 节状态对应、
 * 第 3 节风险与对策（不重试、回调只作触发、只走 https）。
 *
 * @internal
 * @coversNothing
 */
class YunyangDriverTest extends TestCase
{
    private const BASE_URL = 'https://yunyang.example.invalid';

    private const APP_ID = 'test-appid';

    private const SECRET_KEY = 'test-secret';

    /**
     * 签名不覆盖请求内容，http 等于谁都能改收件地址和重量，所以构造时就拒绝
     * （yunyang.md 第 3 节）。
     */
    public function testNonHttpsBaseUrlIsRejectedAtConstruction()
    {
        $this->expectException(InvalidArgumentException::class);
        new YunyangDriver('http://yunyang.example.invalid', self::APP_ID, self::SECRET_KEY);
    }

    public function testEveryRequestCarriesAFreshSignedEnvelope()
    {
        $sent = [];
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->twice()
            ->andReturnUsing(function (string $method, string $url, array $options) use (&$sent) {
                $sent[] = $options['json'];

                return new Response(200, [], json_encode(['code' => '1', 'message' => '', 'result' => []]));
            });

        $driver = $this->makeDriver($client);
        $driver->queryOrder('SB-1');
        $driver->queryOrder('SB-1');

        $this->assertNotSame($sent[0]['requestId'], $sent[1]['requestId'], 'requestId 每次都要新的');
        foreach ($sent as $body) {
            $this->assertSame(self::APP_ID, $body['appid']);
            $this->assertSame('orderDetail', $body['serviceCode']);
            $this->assertSame(
                md5(self::APP_ID . $body['requestId'] . $body['timeStamp'] . self::SECRET_KEY),
                $body['sign']
            );
            $this->assertStringContainsString('SB-1', $body['content'], 'content 是 JSON 字符串');
        }
    }

    // ---- 下单：yunyang.md 第 3 节「下单没有防重复单号」 ----

    public function testPlaceOrderPutsPlatformOrderNoIntoExtendField1()
    {
        $sent = null;
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturnUsing(function (string $method, string $url, array $options) use (&$sent) {
                $sent = json_decode($options['json']['content'], true);

                return new Response(200, [], json_encode([
                    'code' => '1',
                    'message' => '',
                    'result' => ['shopbill' => 'SB-1', 'waybill' => 'WB-1', 'freight' => '12.50', 'feeOver' => 0, 'typeCode' => 1],
                ]));
            });

        $result = $this->makeDriver($client)->placeOrder('R20260921000001', 'CH-9', ['weight' => 3]);

        $this->assertSame('R20260921000001', $sent['extendField1'], '回调带回来时靠它认订单');
        $this->assertSame('智能', $sent['channelTag']);
        $this->assertSame('CH-9', $sent['channelId']);
        $this->assertSame(UnifiedResult::Processing, $result->result, 'feeOver=0 只是冻结，还没结算');
        $this->assertSame('SB-1', $result->supplierOrderNo);
        $this->assertSame('12.50', $result->actualCost, '没扣费时用冻结运费');
        $this->assertSame(0, $result->expressFees['fee_over']);
        $this->assertSame('WB-1', $result->expressFees['waybill']);
    }

    /**
     * 超时不能当失败：云洋没有防重复单号，重试就是重复下单、重复扣钱，
     * 所以只能是"结果未知"（yunyang.md 第 3 节）。
     */
    public function testPlaceOrderTimeoutIsUnknownNeverFailure()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andThrow(new ConnectException('timeout', new Request('POST', self::BASE_URL)));

        $result = $this->makeDriver($client)->placeOrder('R1', 'CH-1');

        $this->assertSame(UnifiedResult::Unknown, $result->result);
        $this->assertStringContainsString('timeout', $result->failReason);
    }

    public function testPlaceOrderHttp500IsUnknown()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->once()->andReturn(new Response(500, [], 'gateway error'));

        $this->assertSame(UnifiedResult::Unknown, $this->makeDriver($client)->placeOrder('R1', 'CH-1')->result);
    }

    /**
     * 接口明确返回失败（code=0）才是明确失败：云洋没受理，没产生任何费用。
     */
    public function testPlaceOrderBusinessFailureIsDefiniteFailure()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode(['code' => '0', 'message' => '该地区不支持', 'result' => null])));

        $result = $this->makeDriver($client)->placeOrder('R1', 'CH-1');

        $this->assertSame(UnifiedResult::DefiniteFailure, $result->result);
        $this->assertStringContainsString('该地区不支持', $result->failReason);
    }

    // ---- 查询订单详情 ----

    public function testQueryOrderSettledFeeMapsToSuccessWithFeeBreakdown()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode(['code' => '1', 'message' => '', 'result' => [
                'shopbill' => 'SB-1',
                'feeOver' => 1,
                'typeCode' => 3,
                'totalFreight' => '18.30',
                'freight' => '15.00',
                'freightInsured' => '2.00',
                'freightHaocai' => '1.30',
                'changeBillFreight' => '0.00',
                'weight' => '3',
            ]])));

        $result = $this->makeDriver($client)->queryOrder('SB-1');

        $this->assertSame(UnifiedResult::Success, $result->result);
        $this->assertSame('18.30', $result->actualCost, '结算按 totalFreight');
        $this->assertSame('15.00', $result->expressFees['freight']);
        $this->assertSame('2.00', $result->expressFees['freight_insured']);
        $this->assertSame('1.30', $result->expressFees['freight_haocai']);
        $this->assertSame(3, $result->expressFees['type_code']);
        $this->assertNull($result->supplierRebate, '云洋文档里没有返佣字段');
    }

    public function testQueryOrderCancelledBeforeChargingIsDefiniteFailure()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode(['code' => '1', 'message' => '', 'result' => [
                'shopbill' => 'SB-1', 'feeOver' => 0, 'typeCode' => 99,
            ]])));

        $this->assertSame(UnifiedResult::DefiniteFailure, $this->makeDriver($client)->queryOrder('SB-1')->result);
    }

    public function testQueryOrderNotFoundIsDefiniteFailureButTransportErrorIsUnknown()
    {
        $notFound = Mockery::mock(ClientInterface::class);
        $notFound->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode(['code' => '0', 'message' => '订单不存在', 'result' => null])));
        $this->assertSame(UnifiedResult::DefiniteFailure, $this->makeDriver($notFound)->queryOrder('SB-404')->result);

        $broken = Mockery::mock(ClientInterface::class);
        $broken->shouldReceive('request')->once()->andReturn(new Response(200, [], 'not json'));
        $this->assertSame(UnifiedResult::Unknown, $this->makeDriver($broken)->queryOrder('SB-1')->result);
    }

    // ---- 回调：没有签名，只当触发信号 ----

    /**
     * 回调里写什么状态都不算数：驱动拿单号重新调订单详情，返回的是查询结果。
     */
    public function testCallbackIsOnlyATriggerAndTheDetailQueryWins()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturnUsing(function (string $method, string $url, array $options) {
                $this->assertSame('orderDetail', $options['json']['serviceCode'], '回调进来后发的是订单详情查询');

                return new Response(200, [], json_encode(['code' => '1', 'message' => '', 'result' => [
                    'shopbill' => 'SB-1', 'feeOver' => 1, 'typeCode' => 2, 'totalFreight' => '18.30',
                ]]));
            });

        // 伪造的"已取消 + 运费 0.01"回调
        $result = $this->makeDriver($client)->parseCallback([
            'shopbill' => 'SB-1', 'typeCode' => 99, 'feeOver' => 0, 'totalFreight' => '0.01',
        ]);

        $this->assertSame(UnifiedResult::Success, $result->result, '以查询结果为准，不信回调');
        $this->assertSame('18.30', $result->actualCost);
    }

    public function testCallbackWithoutAnyOrderNumberIsRejected()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldNotReceive('request');

        $this->assertNull($this->makeDriver($client)->parseCallback(['typeCode' => 3]));
    }

    public function testPlatformOrderNoIsReadBackFromTheCallbackExtendField()
    {
        $driver = $this->makeDriver(Mockery::mock(ClientInterface::class));

        $this->assertSame('R1', $driver->platformOrderNoFromCallback(['extendField1' => 'R1']));
        $this->assertNull($driver->platformOrderNoFromCallback([]));
    }

    // ---- 查价 ----

    public function testCheckChannelsNormalizesEveryChannel()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturnUsing(function (string $method, string $url, array $options) {
                $this->assertSame('智能', json_decode($options['json']['content'], true)['channelTag']);

                return new Response(200, [], json_encode(['code' => '1', 'message' => '', 'result' => ['list' => [
                    ['channelId' => 'CH-1', 'channelName' => '顺丰', 'freight' => '15.00', 'totalFreight' => '15.00', 'allowInsured' => 1],
                    ['channelId' => 'CH-2', 'channelName' => '中通', 'freight' => '8.00', 'totalFreight' => '8.00', 'allowInsured' => 0],
                ]]]));
            });

        $channels = $this->makeDriver($client)->checkChannels(['weight' => 3]);

        $this->assertCount(2, $channels);
        $this->assertSame('CH-1', $channels[0]['channel_id']);
        $this->assertTrue($channels[0]['allow_insured']);
        $this->assertFalse($channels[1]['allow_insured'], '只有 allowInsured=1 的渠道能选保价');
        $this->assertSame('8.00', $channels[1]['freight']);
    }

    public function testCheckChannelsReturnsEmptyOnBusinessFailure()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode(['code' => '0', 'message' => '地址不全', 'result' => null])));

        $this->assertSame([], $this->makeDriver($client)->checkChannels([]));
    }

    // ---- 取消、轨迹、余额 ----

    public function testCancelCarriesBackTheRefusalReason()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode(['code' => '0', 'message' => '已揽收，无法取消', 'result' => null])));

        $cancelled = $this->makeDriver($client)->cancelOrder('SB-1');

        $this->assertFalse($cancelled['cancelled']);
        $this->assertStringContainsString('已揽收', $cancelled['message']);
    }

    public function testTraceReturnsEmptyWhenQueryFails()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->once()->andReturn(new Response(200, [], json_encode(['code' => '0', 'message' => 'x', 'result' => null])));

        $this->assertSame([], $this->makeDriver($client)->queryTrace('SB-1'));
    }

    /**
     * 余额接口的成功码是 "200"，不是订单类接口的 "1"（yunyang.md 第 1 节）。
     */
    public function testBalanceUsesTheAccountSuccessCodeAndAvailableAmount()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturnUsing(function (string $method, string $url, array $options) {
                $this->assertSame('queryBalance', $options['json']['serviceCode']);

                return new Response(200, [], json_encode(['code' => '200', 'message' => '', 'result' => [
                    'yue' => '1000.00', 'keyong' => '820.50', 'dongjie' => '179.50',
                ]]));
            });

        $this->assertSame('820.50', $this->makeDriver($client)->queryBalance(), '余额监控取可用余额');
    }

    public function testBalanceWithOrderSuccessCodeIsNotAccepted()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode(['code' => '1', 'message' => '', 'result' => ['keyong' => '820.50']])));

        $this->expectException(RuntimeException::class);
        $this->makeDriver($client)->queryBalance();
    }

    public function testBalanceThrowsWhenFieldMissing()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode(['code' => '200', 'message' => '', 'result' => ['yue' => '1000.00']])));

        $this->expectException(RuntimeException::class);
        $this->makeDriver($client)->queryBalance();
    }

    /**
     * 工单走单独路径、不带 serviceCode，类型换成云洋编号，成功码是 "200"；超时是结果未知（不能让客服马上重提）。
     */
    public function testSubmitWorkOrderUsesItsOwnPathAndTypeCode()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturnUsing(function (string $method, string $url, array $options) {
                $this->assertSame(self::BASE_URL . '/api/wuliu/submitWorkOrder', $url);
                $this->assertArrayNotHasKey('serviceCode', $options['json']);
                $this->assertSame(['shopbill' => 'YY-1', 'type' => 2, 'content' => '外箱破损'], json_decode($options['json']['content'], true));

                return new Response(200, [], json_encode(['code' => '200', 'message' => '', 'result' => ['workOrderId' => 'WO-9']]));
            });

        $this->assertSame(
            ['accepted' => true, 'unknown' => false, 'workorder_no' => 'WO-9', 'message' => ''],
            $this->makeDriver($client)->submitWorkOrder('YY-1', 'claim', '外箱破损')
        );

        $refused = Mockery::mock(ClientInterface::class);
        $refused->shouldReceive('request')->once()
            ->andReturn(new Response(200, [], json_encode(['code' => '1', 'message' => '工单已存在', 'result' => null])));
        $outcome = $this->makeDriver($refused)->submitWorkOrder('YY-1', 'weight_verify', '重量不对');
        $this->assertFalse($outcome['accepted'], '订单接口的成功码 "1" 不算工单成功');
        $this->assertFalse($outcome['unknown']);

        $timeout = Mockery::mock(ClientInterface::class);
        $timeout->shouldReceive('request')->once()->andThrow(new ConnectException('timeout', new Request('POST', self::BASE_URL)));
        $this->assertTrue($this->makeDriver($timeout)->submitWorkOrder('YY-1', 'urge_pickup', '催一下')['unknown']);
    }

    public function testWorkOrderCallbackIsRecognizedByWorkOrderNo()
    {
        $driver = $this->makeDriver(Mockery::mock(ClientInterface::class));

        $this->assertNull($driver->parseWorkOrderCallback(['shopbill' => 'YY-1', 'typeCode' => 3]), '普通订单回调不是工单回调');
        $this->assertSame([
            'workorder_no' => 'WO-9',
            'shopbill' => 'YY-1',
            'status' => '已处理',
            'reply' => '核实超重，退回 3 元',
            'amount' => '3.00',
        ], $driver->parseWorkOrderCallback([
            'workOrderId' => 'WO-9', 'shopbill' => 'YY-1', 'status' => '已处理', 'reply' => '核实超重，退回 3 元', 'weightAmount' => '3.00',
        ]));
    }

    public function testCallsAreRecordedWithTheSharedActionNames()
    {
        $recorded = [];
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode(['code' => '1', 'message' => '', 'result' => []])));

        $driver = $this->makeDriver($client, function (string $action, array $request, array $response, int $durationMs) use (&$recorded) {
            $recorded[] = [$action, $request, $response, $durationMs];
        });
        $driver->queryOrder('SB-1');

        $this->assertCount(1, $recorded);
        $this->assertSame('query', $recorded[0][0], '跟卡速售用同一套动作名');
        $this->assertSame('orderDetail', $recorded[0][1]['service_code']);
        $this->assertGreaterThanOrEqual(0, $recorded[0][3]);
    }

    private function makeDriver(ClientInterface $client, ?Closure $callRecorder = null): YunyangDriver
    {
        return new class(self::BASE_URL, self::APP_ID, self::SECRET_KEY, $client, $callRecorder) extends YunyangDriver {
            private ClientInterface $stubClient;

            public function __construct(string $baseUrl, string $appId, string $secretKey, ClientInterface $client, ?Closure $callRecorder)
            {
                parent::__construct($baseUrl, $appId, $secretKey, $callRecorder);
                $this->stubClient = $client;
            }

            protected function httpClient(): ClientInterface
            {
                return $this->stubClient;
            }
        };
    }
}
