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
            ->with('POST', Mockery::pattern('#/api/v1/order/create$#'), Mockery::type('array'))
            ->andReturn(new Response(400, [], json_encode(['code' => 400, 'msg' => 'unknown error', 'data' => []])));
        $client->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::pattern('#/api/v1/order/query$#'), Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode([
                'code' => 200,
                'msg' => '',
                'data' => ['status' => 3, 'ordersn' => 'KS1'],
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
            ->with('POST', Mockery::pattern('#/api/v1/order/create$#'), Mockery::type('array'))
            ->andReturn(new Response(400, [], json_encode(['code' => 400, 'msg' => 'safe_price too low', 'data' => []])));
        $client->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::pattern('#/api/v1/order/query$#'), Mockery::type('array'))
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
            ->andThrow(new ConnectException('timed out', new Request('POST', self::BASE_URL . '/api/v1/order/create')));

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
            ->with('POST', Mockery::pattern('#/api/v1/order/query$#'), Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode([
                'code' => 200,
                'msg' => '',
                'data' => [
                    'status' => 3,
                    'ordersn' => 'KS1',
                    'card_list' => [['card_no' => '1111', 'card_password' => '2222']],
                ],
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
            ->andThrow(new ConnectException('timed out', new Request('POST', self::BASE_URL . '/api/v1/order/query')));

        $driver = $this->makeDriver($client);
        $result = $driver->queryOrder('EO-1');

        $this->assertSame(UnifiedResult::Unknown, $result->result);
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
            ->with('POST', Mockery::pattern('#/api/v1/order/query$#'), Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode([
                'code' => 200,
                'msg' => '',
                'data' => ['status' => 3, 'ordersn' => 'KS1', 'card_list' => [['card_no' => 'authoritative', 'card_password' => 'authoritative']]],
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
            ->with('POST', Mockery::pattern('#/api/v1/goods/detail$#'), Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode([
                'code' => 200,
                'msg' => '',
                'data' => ['id' => 'GOODS-1', 'goods_price' => '8.50', 'status' => 'active', 'stock' => 100],
            ])));

        $driver = $this->makeDriver($client);
        $detail = $driver->queryProductDetail('GOODS-1');

        $this->assertSame('GOODS-1', $detail['supplier_product_code']);
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
                'data' => ['id' => 'GOODS-1', 'goods_price' => '8.50', 'status' => 'some-unknown-code', 'stock' => null],
            ])));

        $driver = $this->makeDriver($client);
        $detail = $driver->queryProductDetail('GOODS-1');

        $this->assertSame('paused', $detail['status']);
        $this->assertNull($detail['stock']);
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
            ->with('POST', Mockery::pattern('#/api/v1/goods/list$#'), Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode([
                'code' => 200,
                'msg' => '',
                'data' => [
                    'list' => [
                        ['id' => 'GOODS-1', 'goods_price' => '8.50', 'status' => 'active', 'stock' => 100],
                        ['id' => 'GOODS-2', 'goods_price' => '9.90', 'status' => 'banned', 'stock' => 0],
                    ],
                    'total' => 2,
                ],
            ])));

        $driver = $this->makeDriver($client);
        $entries = $driver->syncAllProducts(1, 100);

        $this->assertCount(2, $entries);
        $this->assertSame('GOODS-1', $entries[0]['supplier_product_code']);
        $this->assertSame('active', $entries[0]['status']);
        $this->assertSame('GOODS-2', $entries[1]['supplier_product_code']);
        $this->assertSame('banned', $entries[1]['status']);
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
     * @param array<string, mixed> $orderData
     */
    private function placeOrderWithOrderResponse(int $httpStatus, array $orderData, bool $isCardProduct = false): DriverResult
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::pattern('#/api/v1/order/create$#'), Mockery::type('array'))
            ->andReturn(new Response($httpStatus, [], json_encode(['code' => $httpStatus, 'msg' => '', 'data' => $orderData])));

        $driver = $this->makeDriver($client);

        return $driver->placeOrder('EO-' . uniqid('', true), 'GOODS-1', '10.00', 'https://merchant.example.com/notify', isCardProduct: $isCardProduct);
    }

    /**
     * @param array<string, mixed> $orderData
     */
    private function queryOrderWithResponse(array $orderData, bool $isCardProduct = false): DriverResult
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::pattern('#/api/v1/order/query$#'), Mockery::type('array'))
            ->andReturn(new Response(200, [], json_encode(['code' => 200, 'msg' => '', 'data' => $orderData])));

        $driver = $this->makeDriver($client);

        return $driver->queryOrder('EO-' . uniqid('', true), $isCardProduct);
    }

    private function makeDriver(ClientInterface $client): KasushouDriver
    {
        return new class(self::BASE_URL, self::USER_ID, self::API_KEY, $client) extends KasushouDriver {
            private ClientInterface $stubClient;

            public function __construct(string $baseUrl, string $userId, string $apiKey, ClientInterface $client)
            {
                parent::__construct($baseUrl, $userId, $apiKey);
                $this->stubClient = $client;
            }

            protected function httpClient(): ClientInterface
            {
                return $this->stubClient;
            }
        };
    }
}
