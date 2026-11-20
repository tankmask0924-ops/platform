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

namespace HyperfTest\Cases\Supplier\Kasushou;

use App\Supplier\DriverResult;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\UnifiedResult;
use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hyperf\Testing\TestCase;
use Mockery;
use RuntimeException;

/**
 * KasushouDriver 全部走 Mockery 双重的 GuzzleHttp\ClientInterface，不发真实网络请求——
 * 卡速售没有测试环境域名/账号（kasushou.md 第 5 节）。HTTP 客户端替换方式跟
 * App\Job\NotifyMerchantJob 的测试模式一样：匿名子类覆盖 protected httpClient()。
 *
 * 覆盖 kasushou.md 第 2 节订单状态映射表 + 第 3 节错误处理表的关键分支。
 *
 * @internal
 * @coversNothing
 */
class KasushouDriverTest extends TestCase
{
    private const BASE_URL = 'https://kasushou.example.invalid';

    private const USER_ID = 'test-user-id';

    private const API_KEY = 'test-api-key';

    // ---- placeOrder: HTTP 200，按状态映射 ----

    public function testPlaceOrderHttp200Status1MapsToProcessing()
    {
        $result = $this->placeOrderWithOrderResponse(200, ['status' => 1, 'ordersn' => 'KS1']);

        $this->assertSame(UnifiedResult::Processing, $result->result);
    }

    public function testPlaceOrderHttp200Status2MapsToProcessing()
    {
        $result = $this->placeOrderWithOrderResponse(200, ['status' => 2, 'ordersn' => 'KS1']);

        $this->assertSame(UnifiedResult::Processing, $result->result);
    }

    public function testPlaceOrderHttp200Status3NonCardProductMapsToSuccess()
    {
        $result = $this->placeOrderWithOrderResponse(200, ['status' => 3, 'ordersn' => 'KS1'], isCardProduct: false);

        $this->assertSame(UnifiedResult::Success, $result->result);
        $this->assertSame('KS1', $result->supplierOrderNo);
    }

    public function testPlaceOrderHttp200Status3CardProductWithoutCardListStaysProcessing()
    {
        $result = $this->placeOrderWithOrderResponse(200, ['status' => 3, 'ordersn' => 'KS1'], isCardProduct: true);

        $this->assertSame(UnifiedResult::Processing, $result->result);
    }

    public function testPlaceOrderHttp200Status4FullRefundMapsToDefiniteFailure()
    {
        $result = $this->placeOrderWithOrderResponse(200, [
            'status' => 4,
            'ordersn' => 'KS1',
            'has_back_money' => '10.00',
            'total_price' => '10.00',
        ]);

        $this->assertSame(UnifiedResult::DefiniteFailure, $result->result);
        $this->assertSame('10.00', $result->refundAmount);
        $this->assertFalse($result->supplierBalanceInsufficient, '其它明确失败不算预存款不足');
    }

    public function testPlaceOrderHttp200Status5PartialRefundMapsToUnknown()
    {
        $result = $this->placeOrderWithOrderResponse(200, [
            'status' => 5,
            'ordersn' => 'KS1',
            'has_back_money' => '4.00',
            'total_price' => '10.00',
        ]);

        $this->assertSame(UnifiedResult::Unknown, $result->result);
    }

    public function testPlaceOrderHttp200StatusMinusOneMapsToDefiniteFailure()
    {
        $result = $this->placeOrderWithOrderResponse(200, ['status' => -1, 'ordersn' => null]);

        $this->assertSame(UnifiedResult::DefiniteFailure, $result->result);
        $this->assertTrue($result->supplierBalanceInsufficient, '预存款不足要单独标出来，路由据此告警并刷新余额');
    }

    public function testPlaceOrderHttp200UnrecognizedStatusMapsToUnknown()
    {
        $result = $this->placeOrderWithOrderResponse(200, ['status' => 99, 'ordersn' => 'KS1']);

        $this->assertSame(UnifiedResult::Unknown, $result->result);
    }

    // ---- placeOrder: HTTP 400，先查订单再判断 ----

    public function testPlaceOrderHttp400ThenQueryFindsOrderMapsToItsStatus()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::pattern('#/api/v1/order/buy$#'), Mockery::type('array'))
            ->andReturn(new Response(400, [], json_encode(['code' => 400, 'msg' => 'unknown error', 'data' => []])));
        $client->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::pattern('#/api/v1/order/info$#'), Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode([
                'code' => 200,
                'msg' => '',
                'data' => [['external_orderno' => 'EO-1', 'status' => 3, 'ordersn' => 'KS1']],
            ])));

        $driver = $this->makeDriver($client);
        $result = $driver->placeOrder('EO-1', 'GOODS-1', '10.00', 'https://merchant.example.com/notify', quantity: 1, isCardProduct: false);

        $this->assertSame(UnifiedResult::Success, $result->result);
        $this->assertSame('KS1', $result->supplierOrderNo);
    }

    public function testPlaceOrderHttp400ThenQueryNotFoundMapsToDefiniteFailure()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::pattern('#/api/v1/order/buy$#'), Mockery::type('array'))
            ->andReturn(new Response(400, [], json_encode(['code' => 400, 'msg' => 'safe_price too low', 'data' => []])));
        $client->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::pattern('#/api/v1/order/info$#'), Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode(['code' => 200, 'msg' => '', 'data' => []])));

        $driver = $this->makeDriver($client);
        $result = $driver->placeOrder('EO-2', 'GOODS-1', '10.00', 'https://merchant.example.com/notify');

        $this->assertSame(UnifiedResult::DefiniteFailure, $result->result);
    }

    // ---- placeOrder: 500 / 超时 / 解析不出结构 -> 结果未知 ----

    public function testPlaceOrderHttp500MapsToUnknown()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(500, [], json_encode(['code' => 500, 'msg' => 'internal error', 'data' => null])));

        $driver = $this->makeDriver($client);
        $result = $driver->placeOrder('EO-3', 'GOODS-1', '10.00', 'https://merchant.example.com/notify');

        $this->assertSame(UnifiedResult::Unknown, $result->result);
    }

    public function testPlaceOrderNetworkTimeoutMapsToUnknown()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andThrow(new ConnectException('timed out', new Request('POST', self::BASE_URL . '/api/v1/order/buy')));

        $driver = $this->makeDriver($client);
        $result = $driver->placeOrder('EO-4', 'GOODS-1', '10.00', 'https://merchant.example.com/notify');

        $this->assertSame(UnifiedResult::Unknown, $result->result);
    }

    public function testPlaceOrderUnparseableResponseMapsToUnknown()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], 'not-json'));

        $driver = $this->makeDriver($client);
        $result = $driver->placeOrder('EO-5', 'GOODS-1', '10.00', 'https://merchant.example.com/notify');

        $this->assertSame(UnifiedResult::Unknown, $result->result);
    }

    // ---- queryOrder ----

    public function testQueryOrderStatus3WithCardListMapsToSuccessAndCarriesCardList()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::pattern('#/api/v1/order/info$#'), Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode([
                'code' => 200,
                'msg' => '',
                'data' => [[
                    'external_orderno' => 'EO-1',
                    'status' => 3,
                    'ordersn' => 'KS1',
                    'card_list' => [['card_no' => '1111', 'card_password' => '2222']],
                ]],
            ])));

        $driver = $this->makeDriver($client);
        $result = $driver->queryOrder('EO-1', isCardProduct: true);

        $this->assertSame(UnifiedResult::Success, $result->result);
        $this->assertSame([['card_no' => '1111', 'card_password' => '2222']], $result->cardList);
    }

    public function testQueryOrderStatus3WithoutCardListForCardProductStaysProcessing()
    {
        $result = $this->queryOrderWithResponse(['status' => 3, 'ordersn' => 'KS1'], isCardProduct: true);

        $this->assertSame(UnifiedResult::Processing, $result->result);
    }

    public function testQueryOrderStatus4FullRefundMapsToDefiniteFailure()
    {
        $result = $this->queryOrderWithResponse([
            'status' => 4,
            'ordersn' => 'KS1',
            'has_back_money' => '10.00',
            'total_price' => '10.00',
        ]);

        $this->assertSame(UnifiedResult::DefiniteFailure, $result->result);
    }

    public function testQueryOrderStatus4PartialRefundMapsToUnknown()
    {
        $result = $this->queryOrderWithResponse([
            'status' => 4,
            'ordersn' => 'KS1',
            'has_back_money' => '3.50',
            'total_price' => '10.00',
        ]);

        $this->assertSame(UnifiedResult::Unknown, $result->result);
    }

    public function testQueryOrderStatusMinusOneMapsToDefiniteFailure()
    {
        $result = $this->queryOrderWithResponse(['status' => -1, 'ordersn' => null]);

        $this->assertSame(UnifiedResult::DefiniteFailure, $result->result);
        $this->assertTrue($result->supplierBalanceInsufficient);
    }

    public function testQueryOrderUnrecognizedStatusMapsToUnknown()
    {
        $result = $this->queryOrderWithResponse(['status' => 42, 'ordersn' => 'KS1']);

        $this->assertSame(UnifiedResult::Unknown, $result->result);
    }

    public function testQueryOrderHttp400OnQueryItselfMapsToUnknown()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(400, [], json_encode(['code' => 400, 'msg' => 'bad request', 'data' => []])));

        $driver = $this->makeDriver($client);
        $result = $driver->queryOrder('EO-1');

        $this->assertSame(UnifiedResult::Unknown, $result->result);
    }

    public function testQueryOrderHttp500OrTimeoutMapsToUnknown()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andThrow(new ConnectException('timed out', new Request('POST', self::BASE_URL . '/api/v1/order/info')));

        $driver = $this->makeDriver($client);
        $result = $driver->queryOrder('EO-1');

        $this->assertSame(UnifiedResult::Unknown, $result->result);
    }

    /**
     * 文档没写查不到订单时怎么返回：空数组、data 为 null、400 且提示订单不存在都算查不到；
     * 其它 400（签名错误等）、500、看不懂的响应仍是结果未知，不能当查不到去换供应商。
     */
    public function testQueryOrderNotFoundVariants()
    {
        $cases = [
            'empty list' => [200, ['code' => 200, 'msg' => '成功', 'data' => []], UnifiedResult::DefiniteFailure],
            'data null' => [200, ['code' => 200, 'msg' => '成功', 'data' => null], UnifiedResult::DefiniteFailure],
            '400 订单不存在' => [400, ['code' => 400, 'msg' => '订单不存在'], UnifiedResult::DefiniteFailure],
            '400 未找到订单' => [400, ['code' => 400, 'msg' => '未找到该订单信息'], UnifiedResult::DefiniteFailure],
            '400 签名错误' => [400, ['code' => 400, 'msg' => '签名错误'], UnifiedResult::Unknown],
            '400 没有 msg' => [400, ['code' => 400], UnifiedResult::Unknown],
            '500 订单不存在' => [500, ['code' => 500, 'msg' => '订单不存在'], UnifiedResult::Unknown],
            'not json' => [200, null, UnifiedResult::Unknown],
        ];
        foreach ($cases as $label => [$status, $body, $expected]) {
            $client = Mockery::mock(ClientInterface::class);
            $client->shouldReceive('request')->once()->andReturn(new Response($status, [], $body === null ? '<html>gateway</html>' : json_encode($body)));

            $this->assertSame($expected, $this->makeDriver($client)->queryOrder('EO-NF')->result, $label);
        }
    }

    public function testPlaceOrderHttp400ThenQuerySaysNotExistSwitchesSupplier()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->once()->with('POST', Mockery::pattern('#/api/v1/order/buy$#'), Mockery::type('array'))
            ->andReturn(new Response(400, [], json_encode(['code' => 400, 'msg' => '安全价格低于售价'])));
        $client->shouldReceive('request')->once()->with('POST', Mockery::pattern('#/api/v1/order/info$#'), Mockery::type('array'))
            ->andReturn(new Response(400, [], json_encode(['code' => 400, 'msg' => '订单不存在'])));

        $result = $this->makeDriver($client)->placeOrder('EO-NF-2', '2909', '10.00', 'https://merchant.example.com/notify');

        $this->assertSame(UnifiedResult::DefiniteFailure, $result->result);
    }

    public function testQueryOrderNotFoundMapsToDefiniteFailure()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode(['code' => 200, 'msg' => '', 'data' => []])));

        $driver = $this->makeDriver($client);
        $result = $driver->queryOrder('EO-does-not-exist');

        $this->assertSame(UnifiedResult::DefiniteFailure, $result->result);
    }

    // ---- queryBalance ----

    public function testQueryBalanceHappyPath()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::pattern('#/api/v1/user/info$#'), Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode(['code' => 200, 'msg' => '', 'data' => ['balance' => '1234.56']])));

        $driver = $this->makeDriver($client);

        $this->assertSame('1234.56', $driver->queryBalance());
    }

    // ---- 调用日志回调 ----

    public function testEveryHttpCallIsReportedToCallRecorder()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode(['code' => 200, 'msg' => '', 'data' => ['balance' => '1234.56']])));
        $client->shouldReceive('request')
            ->once()
            ->andThrow(new ConnectException('connection refused', new Request('POST', self::BASE_URL)));

        $calls = [];
        $driver = $this->makeDriver($client, static function (string $action, array $request, array $response, int $durationMs) use (&$calls) {
            $calls[] = compact('action', 'request', 'response', 'durationMs');
        });

        $driver->queryBalance();
        $driver->queryOrder('EO-LOG-1');

        $this->assertCount(2, $calls);
        $this->assertSame('query_balance', $calls[0]['action']);
        $this->assertSame('/api/v1/user/info', $calls[0]['request']['path']);
        // 响应体解析成数组记下来，方便后台展示和打码
        $this->assertSame(['code' => 200, 'msg' => '', 'data' => ['balance' => '1234.56']], $calls[0]['response']['body']);
        $this->assertSame(200, $calls[0]['response']['http_status']);
        $this->assertGreaterThanOrEqual(0, $calls[0]['durationMs']);

        $this->assertSame('query', $calls[1]['action']);
        $this->assertSame('EO-LOG-1', $calls[1]['request']['body']['external_orderno']);
        $this->assertSame('connection refused', $calls[1]['response']['exception']);
    }

    // ---- parseCallback ----

    public function testParseCallbackValidSignatureDelegatesToQueryOrderAndReturnsItsResult()
    {
        $time = '1700000000';
        $signed = ['external_orderno' => 'EO-1', 'status' => 3, 'time' => $time];
        ksort($signed);
        $json = json_encode($signed, JSON_UNESCAPED_UNICODE);
        $sign = sha1($time . $json . self::API_KEY);

        $payload = [
            'external_orderno' => 'EO-1',
            'status' => 3,
            'time' => $time,
            'sign' => $sign,
            // 回调里的卡密不可信，即便这里带了伪造值，也不应该被直接采信——
            // parseCallback 必须调用 queryOrder() 拿权威结果，而不是直接用 payload 里的这些字段。
            'card_list' => [['card_no' => 'forged', 'card_password' => 'forged']],
        ];

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::pattern('#/api/v1/order/info$#'), Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode([
                'code' => 200,
                'msg' => '',
                'data' => [['external_orderno' => 'EO-1', 'status' => 3, 'ordersn' => 'KS1', 'card_list' => [['card_no' => 'authoritative', 'card_password' => 'authoritative']]]],
            ])));

        $driver = $this->makeDriver($client);
        $result = $driver->parseCallback($payload, []);

        $this->assertInstanceOf(DriverResult::class, $result);
        $this->assertSame(UnifiedResult::Success, $result->result);
        // 权威卡密来自 queryOrder() 的响应，不是回调 payload 里的伪造值
        $this->assertSame([['card_no' => 'authoritative', 'card_password' => 'authoritative']], $result->cardList);
    }

    public function testParseCallbackInvalidSignatureReturnsNullWithoutCallingQueryOrder()
    {
        $payload = [
            'external_orderno' => 'EO-1',
            'status' => 3,
            'time' => '1700000000',
            'sign' => 'not-the-real-signature',
        ];

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldNotReceive('request');

        $driver = $this->makeDriver($client);
        $result = $driver->parseCallback($payload, []);

        $this->assertNull($result);
    }

    // ---- parseProductChangeNotification ----

    public function testParseProductChangeNotificationValidSignatureReturnsId()
    {
        $time = '1700000000';
        $id = 'GOODS-1';
        $signed = ['id' => $id, 'time' => $time];
        ksort($signed);
        $sign = sha1($time . json_encode($signed, JSON_UNESCAPED_UNICODE) . self::API_KEY);

        $payload = ['id' => $id, 'time' => $time, 'sign' => $sign];

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldNotReceive('request');

        $driver = $this->makeDriver($client);
        $result = $driver->parseProductChangeNotification($payload);

        $this->assertSame('GOODS-1', $result);
    }

    public function testParseProductChangeNotificationInvalidSignatureReturnsNull()
    {
        $payload = ['id' => 'GOODS-1', 'time' => '1700000000', 'sign' => 'not-the-real-signature'];

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldNotReceive('request');

        $driver = $this->makeDriver($client);
        $result = $driver->parseProductChangeNotification($payload);

        $this->assertNull($result);
    }

    /**
     * 核心安全断言：验签只覆盖 id+time，返回值必须只是 id 这个字符串本身，绝不
     * 能把 payload 里伪造的 price/status/stock 字段透出来给调用方——即便验签依然
     * 通过（因为这些字段本来就不在签名保护范围内）。
     */
    public function testParseProductChangeNotificationDoesNotSurfaceUnsignedPriceStatusStockFields()
    {
        $time = '1700000000';
        $id = 'GOODS-1';
        $signed = ['id' => $id, 'time' => $time];
        ksort($signed);
        $sign = sha1($time . json_encode($signed, JSON_UNESCAPED_UNICODE) . self::API_KEY);

        $payload = [
            'id' => $id,
            'time' => $time,
            'sign' => $sign,
            'price' => '0.01',
            'status' => 'banned',
            'stock' => 0,
        ];

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldNotReceive('request');

        $driver = $this->makeDriver($client);
        $result = $driver->parseProductChangeNotification($payload);

        // 返回值就是一个裸字符串 id，不是数组/DTO，天然没有地方能夹带 price/status/
        // stock——这本身就是"不信任这些字段"的证明：接口设计上根本不给它们出路。
        $this->assertSame('GOODS-1', $result);
        $this->assertIsString($result);
    }

    // ---- queryProductDetail ----

    public function testQueryProductDetailHappyPath()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::pattern('#/api/v1/goods/info$#'), Mockery::on(function (array $options) {
                // 文档：商品 ID 是 int
                return json_decode($options['body'], true) === ['id' => 2909];
            }))
            ->andReturn(new Response(200, [], json_encode([
                'code' => 200,
                'msg' => '',
                'data' => [
                    'id' => 2909, 'goods_name' => '移动 100 元快充', 'goods_type' => 2, 'face_value' => '100.00', 'goods_price' => '8.50', 'status' => 1, 'stock_num' => 100,
                    'start_count' => 1, 'end_count' => 10, 'can_back' => 1,
                    'can_buy' => '拼多多,京东', 'can_no_buy' => '抖音', 'can_price' => '2.00', 'need_balance' => '2000',
                    'attach' => [
                        ['key' => 'recharge_account', 'type' => 'text', 'tip' => '请填写手机号', 'name' => '手机号'],
                        ['type' => 'text', 'name' => '没有 key 的条目跳过'],
                    ],
                ],
            ])));

        $driver = $this->makeDriver($client);
        $detail = $driver->queryProductDetail('2909');

        $this->assertSame('2909', $detail['supplier_product_code']);
        $this->assertSame([['key' => 'recharge_account', 'name' => '手机号', 'type' => 'text']], $detail['attach_template']);
        $this->assertSame(1, $detail['min_quantity']);
        $this->assertSame(10, $detail['max_quantity']);
        $this->assertTrue($detail['can_back']);
        $this->assertSame(['can_buy' => '拼多多,京东', 'can_no_buy' => '抖音', 'can_price' => '2.00', 'need_balance' => '2000'], $detail['sale_restrictions']);
        $this->assertSame('8.50', $detail['cost_price']);
        $this->assertSame('active', $detail['status']);
        $this->assertSame(100, $detail['stock']);
    }

    public function testQueryProductDetailUnrecognizedStatusMapsToPaused()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode([
                'code' => 200,
                'msg' => '',
                'data' => ['id' => 'GOODS-1', 'goods_price' => '8.50', 'status' => 9],
            ])));

        $driver = $this->makeDriver($client);
        $detail = $driver->queryProductDetail('GOODS-1');

        $this->assertSame('paused', $detail['status']);
        $this->assertNull($detail['stock']);
        // 详情没有 attach（卡密商品）：空模板，不是"没查过"
        $this->assertSame([], $detail['attach_template']);
        $this->assertNull($detail['sale_restrictions']);
    }

    public function testQueryProductDetailHttp500Throws()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(500, [], json_encode(['code' => 500, 'msg' => 'internal error', 'data' => null])));

        $driver = $this->makeDriver($client);

        $this->expectException(RuntimeException::class);
        $driver->queryProductDetail('GOODS-1');
    }

    // ---- syncAllProducts ----

    public function testSyncAllProductsHappyPath()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::pattern('#/api/v1/goods/list$#'), Mockery::on(function (array $options) {
                return json_decode($options['body'], true) === ['limit' => 100, 'page' => 1];
            }))
            ->andReturn(new Response(200, [], json_encode([
                'code' => 200,
                'msg' => '',
                'data' => [
                    'list' => [
                        ['id' => 'GOODS-1', 'goods_price' => '8.50', 'status' => 1, 'stock_num' => 100, 'goods_name' => '移动 10 元快充', 'face_value' => '10', 'goods_type' => 2],
                        ['id' => 'GOODS-2', 'goods_price' => '9.90', 'status' => 3, 'stock_num' => 0, 'face_value' => 'n/a', 'goods_type' => '1'],
                        ['id' => 'GOODS-3', 'goods_price' => '9.90', 'status' => '2', 'stock_num' => '5'],
                    ],
                    'total' => 2,
                ],
            ])));

        $driver = $this->makeDriver($client);
        $entries = $driver->syncAllProducts(1, 100);

        $this->assertCount(3, $entries);
        $this->assertSame('GOODS-1', $entries[0]['supplier_product_code']);
        $this->assertSame(100, $entries[0]['stock']);
        $this->assertSame('paused', $entries[2]['status']);
        // 商品类型：1=卡密，2=虚拟；没给或不认识就是 null
        $this->assertSame('virtual', $entries[0]['goods_type']);
        $this->assertSame('card_secret', $entries[1]['goods_type']);
        $this->assertNull($entries[2]['goods_type']);
        $this->assertSame(5, $entries[2]['stock']);
        $this->assertSame('active', $entries[0]['status']);
        $this->assertSame('GOODS-2', $entries[1]['supplier_product_code']);
        $this->assertSame('banned', $entries[1]['status']);
        // 名称、面值只用来在后台辨认，缺了或格式不对就是 null，不影响这一条同步
        $this->assertSame('移动 10 元快充', $entries[0]['name']);
        $this->assertSame('10.00', $entries[0]['face_value']);
        $this->assertNull($entries[1]['name']);
        $this->assertNull($entries[1]['face_value']);
    }

    public function testSyncAllProductsEmptyListReturnsEmptyArray()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode(['code' => 200, 'msg' => '', 'data' => ['list' => [], 'total' => 0]])));

        $driver = $this->makeDriver($client);

        $this->assertSame([], $driver->syncAllProducts(2, 100));
    }

    public function testSyncAllProductsHttp500Throws()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(500, [], json_encode(['code' => 500, 'msg' => 'internal error', 'data' => null])));

        $driver = $this->makeDriver($client);

        $this->expectException(RuntimeException::class);
        $driver->syncAllProducts();
    }

    /**
     * 撤单（订单撤单接口）：先按 external_orderno 查出卡速售订单号，再按 ordersn 撤、带 callbackUrl；
     * 200 是受理，400 带回卡速售的文案给客服看；查不到订单不发撤单请求。
     */
    public function testCancelOrderLooksUpOrdersnThenClosesByIt()
    {
        $sent = [];
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->times(5)->andReturnUsing(function (string $method, string $url, array $options) use (&$sent) {
            $sent[] = [$url, json_decode($options['body'], true)];

            return match (count($sent)) {
                1, 3 => new Response(200, [], json_encode(['code' => 200, 'msg' => '', 'data' => [['external_orderno' => 'R1-1', 'ordersn' => 'D100759274105949519872', 'status' => 2]]])),
                2 => new Response(200, [], json_encode(['code' => 200, 'msg' => '撤单成功'])),
                4 => new Response(400, [], json_encode(['code' => 400, 'msg' => '该商品不支持撤单'])),
                default => new Response(200, [], json_encode(['code' => 200, 'msg' => '', 'data' => []])),
            };
        });
        $driver = $this->makeDriver($client);

        $this->assertSame(['accepted' => true, 'message' => '撤单成功'], $driver->cancelOrder('R1-1', 'https://platform.example/notify/k/t'));
        $this->assertStringEndsWith('/api/v1/order/info', $sent[0][0]);
        $this->assertSame(['day' => 0, 'external_orderno' => 'R1-1'], $sent[0][1]);
        $this->assertStringEndsWith('/api/v1/order/close', $sent[1][0]);
        $this->assertSame(['callbackUrl' => 'https://platform.example/notify/k/t', 'ordersn' => 'D100759274105949519872'], $sent[1][1]);

        $this->assertSame(['accepted' => false, 'message' => '该商品不支持撤单'], $driver->cancelOrder('R1-1'));
        $this->assertArrayNotHasKey('callbackUrl', $sent[3][1]);

        $notFound = $driver->cancelOrder('R1-1');
        $this->assertFalse($notFound['accepted']);
        $this->assertStringContainsString('查不到', $notFound['message']);
        $this->assertCount(5, $sent, '查不到订单就不发撤单请求');
    }

    public function testSubmitAftersaleOutcomes()
    {
        $sent = [];
        $lookup = new Response(200, [], json_encode(['code' => 200, 'msg' => '', 'data' => [['external_orderno' => 'R1-1', 'ordersn' => 'D0622285344446634000384', 'status' => 3]]]));
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->times(6)->andReturnUsing(function (string $method, string $url, array $options) use (&$sent, $lookup) {
            $sent[] = [$url, json_decode($options['body'], true)];

            return match (count($sent)) {
                1, 3, 5 => $lookup,
                2 => new Response(200, [], json_encode(['code' => 200, 'msg' => '操作成功'])),
                4 => new Response(400, [], json_encode(['code' => 400, 'msg' => '订单已超过售后期'])),
                default => new Response(500, [], json_encode(['code' => 500, 'msg' => '系统繁忙'])),
            };
        });
        $driver = $this->makeDriver($client);

        // 卡速售不给售后单号，用 ordersn 当售后单号（回调只带 ordersn）
        $this->assertSame(
            ['accepted' => true, 'unknown' => false, 'aftersale_no' => 'D0622285344446634000384', 'message' => '操作成功'],
            $driver->submitAftersale('R1-1', '用户称未到账', ['https://img.example/1.png', 'https://img.example/2.png'], 'https://platform.example/notify/k/t/aftersale')
        );
        $this->assertStringEndsWith('/api/v1/order/complain', $sent[1][0]);
        $this->assertSame([
            'content' => '用户称未到账',
            'ordersn' => 'D0622285344446634000384',
            'screenshot' => 'https://img.example/1.png,https://img.example/2.png',
            'url' => 'https://platform.example/notify/k/t/aftersale',
        ], $sent[1][1]);

        $refused = $driver->submitAftersale('R1-1', 'x', [], 'https://platform.example/notify/k/t/aftersale');
        $this->assertFalse($refused['accepted']);
        $this->assertFalse($refused['unknown']);
        $this->assertSame('订单已超过售后期', $refused['message']);
        $this->assertArrayNotHasKey('screenshot', $sent[3][1]);

        $this->assertTrue($driver->submitAftersale('R1-1', 'x', [], 'https://platform.example/notify/k/t/aftersale')['unknown'], '500 是未知错误，不当拒绝');

        $timeout = Mockery::mock(ClientInterface::class);
        $timeout->shouldReceive('request')->once()->andThrow(new ConnectException('timeout', new Request('POST', self::BASE_URL)));
        $this->assertTrue($this->makeDriver($timeout)->submitAftersale('R1-1', 'x', [], 'https://p.example/a')['unknown'], '查订单号超时也是结果未知');
    }

    public function testParseAftersaleCallbackVerifiesSignature()
    {
        $time = '1695072521534';
        $signed = ['ordersn' => 'D0622285344446634000384', 'status' => '3', 'content' => '已核实，号码已到账', 'screenshot' => '', 'time' => $time];
        ksort($signed);
        $payload = $signed + ['sign' => sha1($time . json_encode($signed, JSON_UNESCAPED_UNICODE) . self::API_KEY)];
        $driver = $this->makeDriver(Mockery::mock(ClientInterface::class));

        $this->assertSame(
            ['aftersale_no' => 'D0622285344446634000384', 'external_orderno' => null, 'status' => 'completed', 'reply' => '已核实，号码已到账'],
            $driver->parseAftersaleCallback($payload)
        );

        $payload['content'] = '伪造：未到账';
        $this->assertNull($driver->parseAftersaleCallback($payload), '内容被改过，验签失败');
    }

    /**
     * 下单请求体按文档：商品字段是 `id`（int），卡密商品不传 attach；实际发送的请求体就是签名用的字符串。
     */
    public function testPlaceOrderBodyMatchesDocAndIsSentExactlyAsSigned()
    {
        $captured = [];
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->twice()->andReturnUsing(function (string $method, string $url, array $options) use (&$captured) {
            $captured[] = $options;

            return new Response(200, [], json_encode(['code' => 200, 'msg' => '下单成功', 'data' => ['ordersn' => 'API1', 'status' => 1]]));
        });
        $driver = $this->makeDriver($client);

        $driver->placeOrder('EO-1', '2909', '99.20', 'https://platform.example/notify/k/t', ['recharge_account' => '13800000000']);
        $driver->placeOrder('EO-2', '2909', '99.20', 'https://platform.example/notify/k/t', [], isCardProduct: true);

        $this->assertSame(
            '{"attach":{"recharge_account":"13800000000"},"external_orderno":"EO-1","id":2909,"quantity":1,"safe_price":"99.20","url":"https://platform.example/notify/k/t"}',
            $captured[0]['body']
        );
        $this->assertArrayNotHasKey('json', $captured[0]);
        $headers = $captured[0]['headers'];
        $this->assertSame(sha1($headers['Timestamp'] . $captured[0]['body'] . self::API_KEY), $headers['Sign']);
        $this->assertArrayNotHasKey('attach', json_decode($captured[1]['body'], true));
    }

    /**
     * 订单详情返回的是数组：按 external_orderno 取对应那条；有数据但没有这一条时拿不准，不能当查不到。
     */
    public function testQueryOrderPicksMatchingEntryAndTreatsMismatchAsUnknown()
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->twice()->andReturn(
            new Response(200, [], json_encode(['code' => 200, 'msg' => '', 'data' => [
                ['external_orderno' => 'EO-other', 'status' => 5, 'has_back_money' => '1', 'total_price' => '1'],
                ['external_orderno' => 'EO-1', 'status' => '3', 'ordersn' => 'D1'],
            ]])),
            new Response(200, [], json_encode(['code' => 200, 'msg' => '', 'data' => [
                ['external_orderno' => 'EO-other', 'status' => 3],
            ]])),
        );
        $driver = $this->makeDriver($client);

        $found = $driver->queryOrder('EO-1');
        $this->assertSame(UnifiedResult::Success, $found->result);
        $this->assertSame('D1', $found->supplierOrderNo);

        $this->assertSame(UnifiedResult::Unknown, $driver->queryOrder('EO-1')->result);
    }

    private function placeOrderWithOrderResponse(int $httpStatus, array $orderData, bool $isCardProduct = false): DriverResult
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::pattern('#/api/v1/order/buy$#'), Mockery::type('array'))
            ->andReturn(new Response($httpStatus, [], json_encode(['code' => $httpStatus, 'msg' => '', 'data' => $orderData])));

        $driver = $this->makeDriver($client);

        return $driver->placeOrder('EO-' . uniqid('', true), 'GOODS-1', '10.00', 'https://merchant.example.com/notify', isCardProduct: $isCardProduct);
    }

    /**
     * @param array<string, mixed> $orderData
     */
    private function queryOrderWithResponse(array $orderData, bool $isCardProduct = false): DriverResult
    {
        $externalOrderNo = 'EO-' . uniqid('', true);
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::pattern('#/api/v1/order/info$#'), Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode(['code' => 200, 'msg' => '', 'data' => [['external_orderno' => $externalOrderNo] + $orderData]])));

        $driver = $this->makeDriver($client);

        return $driver->queryOrder($externalOrderNo, $isCardProduct);
    }

    private function makeDriver(ClientInterface $client, ?Closure $callRecorder = null): KasushouDriver
    {
        return new class(self::BASE_URL, self::USER_ID, self::API_KEY, $client, $callRecorder) extends KasushouDriver {
            private ClientInterface $stubClient;

            public function __construct(string $baseUrl, string $userId, string $apiKey, ClientInterface $client, ?Closure $callRecorder)
            {
                parent::__construct($baseUrl, $userId, $apiKey, $callRecorder);
                $this->stubClient = $client;
            }

            protected function httpClient(): ClientInterface
            {
                return $this->stubClient;
            }
        };
    }
}
