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
use App\Model\MerchantBalanceLog;
use App\Model\MerchantBusinessSubscription;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderRecharge;
use App\Model\Product;
use App\Model\Supplier;
use App\Model\SupplierProduct;
use App\Signature\SignatureSigner;
use HyperfTest\HttpTestCase;

/**
 * 真实 HTTP 派发的端到端测试，跟 `RechargeOrderControllerTest`（本类的直接参照
 * 对象）同一个套路：不重复 `test/Cases/Service/Order/CardOrderPlacementServiceTest.php`
 * 已经覆盖的编排细节，这里只验证 Controller 这一层——签名中间件把 Merchant 传下去、
 * 输入校验（包括 recharge_account 按 card_type 该不该传这条业务规则真的能通过完整
 * 中间件栈传导成 422，不是 500）、响应信封形状。
 *
 * @internal
 * @coversNothing
 */
class CardOrderControllerTest extends HttpTestCase
{
    private array $merchantIds = [];

    private array $productIds = [];

    private array $supplierIds = [];

    private array $supplierProductIds = [];

    private array $orderIds = [];

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $id) {
            OrderAttempt::where('order_id', $id)->delete();
            OrderRecharge::where('order_id', $id)->delete();
            MerchantBalanceLog::where('order_id', $id)->delete();
            Order::destroy($id);
        }
        $this->orderIds = [];

        foreach ($this->supplierProductIds as $id) {
            SupplierProduct::destroy($id);
        }
        $this->supplierProductIds = [];

        foreach ($this->supplierIds as $id) {
            Supplier::destroy($id);
        }
        $this->supplierIds = [];

        foreach ($this->productIds as $id) {
            Product::destroy($id);
        }
        $this->productIds = [];

        foreach ($this->merchantIds as $id) {
            MerchantBusinessSubscription::where('merchant_id', $id)->delete();
            Merchant::destroy($id);
        }
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testNoEligibleSupplierIsRejectedWithoutCreatingOrderOrFreezing()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, '100.00');
        $product = $this->createProduct('10.00', 'direct');
        // 故意不建 supplier_products 映射行。

        $merchantOrderNo = 'MO-CARD-' . uniqid('', true);
        $response = $this->postCard($merchant->app_key, $secret, [
            'merchant_order_no' => $merchantOrderNo,
            'product_id' => (string) $product->id,
            'recharge_account' => 'game-account-ctrl-1',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(42006, $body['code']);
        $this->assertNull($body['data']);
        $this->assertNull(Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->first());

        $merchant->refresh();
        $this->assertSame('100.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);
    }

    public function testCardSecretProductWithoutRechargeAccountSucceeds()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, '100.00');
        $product = $this->createProduct('10.00', 'card_secret');
        $this->createUnreachableSupplierFor($product);
        // 只验证请求形状（不传 recharge_account）能通过 Controller 校验并建单，不代表
        // 真的拿到了卡密（完整链路在 CardOrderPlacementServiceTest 用 mock 驱动覆盖）。

        $merchantOrderNo = 'MO-CARD-' . uniqid('', true);
        $response = $this->postCard($merchant->app_key, $secret, [
            'merchant_order_no' => $merchantOrderNo,
            'product_id' => (string) $product->id,
            'callback_url' => 'https://merchant.example.com/notify',
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertSame('processing', $body['data']['status']);

        $order = Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->first();
        $this->assertNotNull($order);
        $this->orderIds[] = $order->id;
    }

    public function testCardSecretProductWithRechargeAccountIsRejectedWithInvalidParams()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, '100.00');
        $product = $this->createProduct('10.00', 'card_secret');

        $merchantOrderNo = 'MO-CARD-' . uniqid('', true);
        $response = $this->postCard($merchant->app_key, $secret, [
            'merchant_order_no' => $merchantOrderNo,
            'product_id' => (string) $product->id,
            'recharge_account' => 'should-not-be-here',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(41001, json_decode((string) $response->getBody(), true)['code']);
        $this->assertNull(Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->first());
    }

    public function testDirectProductMissingRechargeAccountIsRejectedWithInvalidParams()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, '100.00');
        $product = $this->createProduct('10.00', 'direct');

        $merchantOrderNo = 'MO-CARD-' . uniqid('', true);
        $response = $this->postCard($merchant->app_key, $secret, [
            'merchant_order_no' => $merchantOrderNo,
            'product_id' => (string) $product->id,
            'callback_url' => 'https://merchant.example.com/notify',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(41001, json_decode((string) $response->getBody(), true)['code']);
        $this->assertNull(Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->first());
    }

    public function testIdempotentResubmissionThroughRealHttpReturnsSameOrder()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, '100.00');
        $product = $this->createProduct('10.00', 'direct');
        $this->createUnreachableSupplierFor($product);

        $merchantOrderNo = 'MO-CARD-' . uniqid('', true);
        $payload = [
            'merchant_order_no' => $merchantOrderNo,
            'product_id' => (string) $product->id,
            'recharge_account' => 'game-account-ctrl-2',
            'callback_url' => 'https://merchant.example.com/notify',
        ];

        $first = $this->postCard($merchant->app_key, $secret, $payload);
        $firstBody = json_decode((string) $first->getBody(), true);

        $second = $this->postCard($merchant->app_key, $secret, $payload);
        $secondBody = json_decode((string) $second->getBody(), true);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame($firstBody['data'], $secondBody['data']);
        // requirements.md 8.1：对商户不暴露供应商信息（首次下单与幂等重放都不能带）
        $this->assertArrayNotHasKey('supplier_order_no', $firstBody['data']);
        $this->assertArrayNotHasKey('supplier_order_no', $secondBody['data']);

        $orders = Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->get();
        $this->assertCount(1, $orders);
        $this->orderIds[] = $orders->first()->id;
    }

    public function testMissingRequiredFieldIsRejectedWithInvalidParams()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, '100.00');

        $response = $this->postCard($merchant->app_key, $secret, [
            'merchant_order_no' => 'MO-CARD-' . uniqid('', true),
            // 缺 product_id / callback_url
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(41001, $body['code']);
        $this->assertNull($body['data']);
    }

    public function testNonCardProductIsRejectedWithEnvelopeThroughRealMiddlewareStack()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, '100.00');
        $product = $this->createProduct('10.00', null, 'on_shelf', 'recharge');

        $response = $this->postCard($merchant->app_key, $secret, [
            'merchant_order_no' => 'MO-CARD-' . uniqid('', true),
            'product_id' => (string) $product->id,
            'recharge_account' => '13800000000',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(42003, json_decode((string) $response->getBody(), true)['code']);
        $this->assertNull(Order::where('merchant_id', $merchant->id)->where('business_line', 'card')->first());
    }

    private function postCard(string $appKey, string $secret, array $extra)
    {
        return $this->client->request('POST', '/open-api/orders/card', [
            'form_params' => $this->signedParams($appKey, $secret, $extra),
        ]);
    }

    /**
     * 让下单能真正建单但不发网络请求：driver 取一个没实现的值，SupplierDriverFactory
     * 构造驱动时抛异常，SupplierRouter 按"结果未知"处理，订单停在 processing。
     */
    private function createUnreachableSupplierFor(Product $product): void
    {
        $unique = uniqid('card_ctrl_test_supplier_', true);

        $supplier = Supplier::create([
            'name' => $unique,
            'code' => substr(md5($unique), 0, 24),
            'business_line' => $product->business_line,
            'driver' => 'unimplemented',
            'config' => 'unused',
            'status' => 'active',
        ]);
        $this->supplierIds[] = $supplier->id;

        $mapping = SupplierProduct::create([
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_product_code' => 'GOODS-CTRL',
            'cost_price' => '1.00',
            'priority' => 1,
            'status' => 'active',
        ]);
        $this->supplierProductIds[] = $mapping->id;
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

    private function createMerchant(string $plainSecret, string $availableBalance): Merchant
    {
        $unique = uniqid('card_ctrl_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => (new Encryptor())->encrypt($plainSecret),
            'available_balance' => $availableBalance,
            'frozen_balance' => '0.00',
        ]);

        $this->merchantIds[] = $merchant->id;
        // 下单和商品查询要求已开通业务线（requirements.md 4.2）
        foreach (['recharge', 'card'] as $line) {
            MerchantBusinessSubscription::create(['merchant_id' => $merchant->id, 'business_line' => $line, 'status' => 'approved', 'applied_at' => date('Y-m-d H:i:s')]);
        }

        return $merchant;
    }

    private function createProduct(
        string $salePrice,
        ?string $cardType,
        string $status = 'on_shelf',
        string $businessLine = 'card'
    ): Product {
        $unique = uniqid('card_ctrl_test_product_', true);

        $product = Product::create([
            'business_line' => $businessLine,
            'name' => $unique,
            'card_type' => $cardType,
            'face_value' => $salePrice,
            'sale_price' => $salePrice,
            'rebate_amount' => '0.00',
            'status' => $status,
        ]);

        $this->productIds[] = $product->id;

        return $product;
    }
}
