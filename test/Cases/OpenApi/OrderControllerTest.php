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

namespace HyperfTest\Cases\OpenApi;

use App\Crypto\Encryptor;
use App\Model\Merchant;
use App\Model\Order;
use App\Model\OrderRecharge;
use App\OpenApi\ErrorCode;
use App\Signature\SignatureSigner;
use HyperfTest\HttpTestCase;

/**
 * 真实 HTTP 派发的端到端测试，用法跟 test/Cases/OpenApi/BalanceControllerTest.php
 * 一致（用 test/HttpTestCase.php 包的 Hyperf\Testing\Client，走真实路由 + 中间件栈，
 * 不能用 Hyperf\Testing\TestCase 自带的 get()，原因见 BalanceControllerTest 的类注释）。
 *
 * 最重要的一条是跨商户越权测试：商户 A 签名请求查询商户 B 的 order_no，必须拿到
 * 「查不到」的失败信封，而不是商户 B 的订单数据或卡密——这条测试真正经过了
 * OrderController -> OrderQueryService -> OrderDao::findByOrderNoForMerchant()
 * 完整链路和真实数据库查询，不是只在 Dao 单测里断言 SQL 拼得对。
 *
 * @internal
 * @coversNothing
 */
class OrderControllerTest extends HttpTestCase
{
    private array $merchantIds = [];

    private array $orderIds = [];

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $id) {
            OrderRecharge::where('order_id', $id)->delete();
            Order::destroy($id);
        }
        $this->orderIds = [];

        foreach ($this->merchantIds as $id) {
            Merchant::destroy($id);
        }
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testQueryByOrderNoReturnsOrder()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret);
        $order = $this->createOrder($merchant->id, 'card', 'success');

        $response = $this->client->request('GET', '/open-api/order', [
            'query' => $this->signedParams($merchant->app_key, $secret, ['order_no' => $order->order_no]),
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertSame($order->order_no, $body['data']['order_no']);
        $this->assertSame($order->merchant_order_no, $body['data']['merchant_order_no']);
        $this->assertSame('card', $body['data']['business_line']);
        $this->assertSame('success', $body['data']['status']);
    }

    public function testQueryByMerchantOrderNoReturnsOrder()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret);
        $order = $this->createOrder($merchant->id, 'recharge', 'processing');

        $response = $this->client->request('GET', '/open-api/order', [
            'query' => $this->signedParams($merchant->app_key, $secret, [
                'merchant_order_no' => $order->merchant_order_no,
            ]),
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertSame($order->order_no, $body['data']['order_no']);
    }

    public function testCrossMerchantQueryByOrderNoIsRejected()
    {
        $secretA = 'plain-secret-a-' . uniqid('', true);
        $merchantA = $this->createMerchant($secretA);

        $secretB = 'plain-secret-b-' . uniqid('', true);
        $merchantB = $this->createMerchant($secretB);
        $orderB = $this->createOrder($merchantB->id, 'card', 'success');
        $this->attachCardSecrets($orderB->id, 'card-no-secret', 'card-pwd-secret');

        // 商户 A 用自己合法的签名，去查商户 B 的 order_no。
        $response = $this->client->request('GET', '/open-api/order', [
            'query' => $this->signedParams($merchantA->app_key, $secretA, ['order_no' => $orderB->order_no]),
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(42005, $body['code']);
        $this->assertNull($body['data']);
        $this->assertArrayNotHasKey('card_no', $body);
    }

    public function testCardBusinessLineReturnsDecryptedCardSecrets()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret);
        $order = $this->createOrder($merchant->id, 'card', 'success');
        $this->attachCardSecrets($order->id, '6222021234567890', '123456');

        $response = $this->client->request('GET', '/open-api/order', [
            'query' => $this->signedParams($merchant->app_key, $secret, ['order_no' => $order->order_no]),
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(0, $body['code']);
        $this->assertSame('6222021234567890', $body['data']['card_no']);
        $this->assertSame('123456', $body['data']['card_pwd']);
    }

    public function testRechargeOrderWithoutOrderRechargeRowHasNoCardFields()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret);
        $order = $this->createOrder($merchant->id, 'recharge', 'success');

        $response = $this->client->request('GET', '/open-api/order', [
            'query' => $this->signedParams($merchant->app_key, $secret, ['order_no' => $order->order_no]),
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertArrayNotHasKey('card_no', $body['data']);
        $this->assertArrayNotHasKey('card_pwd', $body['data']);
    }

    public function testOrderNotFoundReturnsFailureEnvelopeNot500()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret);

        $response = $this->client->request('GET', '/open-api/order', [
            'query' => $this->signedParams($merchant->app_key, $secret, [
                'order_no' => 'no-such-order-' . uniqid('', true),
            ]),
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(42005, $body['code']);
        $this->assertNull($body['data']);
    }

    public function testMissingBothOrderIdentifiersIsRejected()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret);

        $response = $this->client->request('GET', '/open-api/order', [
            'query' => $this->signedParams($merchant->app_key, $secret, []),
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(41001, $body['code']);
    }

    public function testSupplyingBothOrderIdentifiersIsRejected()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret);
        $order = $this->createOrder($merchant->id, 'recharge', 'success');

        $response = $this->client->request('GET', '/open-api/order', [
            'query' => $this->signedParams($merchant->app_key, $secret, [
                'order_no' => $order->order_no,
                'merchant_order_no' => $order->merchant_order_no,
            ]),
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(41001, $body['code']);
    }

    /**
     * 历史数据或其它路径写进 orders.fail_reason 的供应商原始信息不能透传给商户。
     */
    public function testFailedOrderExposesOnlyPlatformFailCodeAndMessage()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret);
        $order = $this->createOrder($merchant->id, 'recharge', 'failed');
        $order->fill(['fail_reason' => 'kasushou: order status 4, upstream msg'])->save();

        $response = $this->client->request('GET', '/open-api/order', [
            'query' => $this->signedParams($merchant->app_key, $secret, ['order_no' => $order->order_no]),
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(0, $body['code']);
        $this->assertSame(ErrorCode::OrderFailed->value, $body['data']['fail_code']);
        $this->assertSame(ErrorCode::OrderFailed->message(), $body['data']['fail_reason']);
        $this->assertStringNotContainsString('kasushou', (string) $response->getBody());
    }

    public function testSuccessfulOrderHasNullFailFields()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret);
        $order = $this->createOrder($merchant->id, 'recharge', 'success');

        $response = $this->client->request('GET', '/open-api/order', [
            'query' => $this->signedParams($merchant->app_key, $secret, ['order_no' => $order->order_no]),
        ]);

        $data = json_decode((string) $response->getBody(), true)['data'];

        $this->assertArrayHasKey('fail_code', $data);
        $this->assertNull($data['fail_code']);
        $this->assertNull($data['fail_reason']);
    }

    private function signedParams(string $appKey, string $secret, array $extra): array
    {
        $params = [
            'app_key' => $appKey,
            'timestamp' => (string) time(),
            'nonce' => uniqid('nonce_', true),
        ] + $extra;
        $params['sign'] = (new SignatureSigner())->sign($params, $secret);

        return $params;
    }

    private function createMerchant(string $plainSecret): Merchant
    {
        $unique = uniqid('order_ctrl_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => (new Encryptor())->encrypt($plainSecret),
            'available_balance' => '0.00',
            'frozen_balance' => '0.00',
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createOrder(int $merchantId, string $businessLine, string $status): Order
    {
        $unique = uniqid('', true);

        $order = Order::create([
            'order_no' => 'PF' . $unique,
            'merchant_id' => $merchantId,
            'merchant_order_no' => 'MO' . $unique,
            'business_line' => $businessLine,
            'status' => $status,
            'sale_price' => '10.00',
            'cost_price' => '8.00',
            'frozen_amount' => '10.00',
            'deducted_amount' => $status === 'success' ? '10.00' : null,
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
            'completed_at' => $status === 'success' ? '2026-09-14 10:00:00' : null,
        ]);

        $this->orderIds[] = $order->id;

        return $order;
    }

    private function attachCardSecrets(int $orderId, string $cardNo, string $cardPwd): OrderRecharge
    {
        $encryptor = new Encryptor();

        return OrderRecharge::create([
            'order_id' => $orderId,
            'product_id' => 1,
            'card_no' => $encryptor->encrypt($cardNo),
            'card_pwd' => $encryptor->encrypt($cardPwd),
            'rebate_amount' => '0.50',
        ]);
    }
}
