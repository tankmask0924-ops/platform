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
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderRecharge;
use App\Model\Product;
use App\Model\Supplier;
use App\Model\SupplierProduct;
use App\Signature\SignatureSigner;
use HyperfTest\HttpTestCase;

/**
 * 真实 HTTP 派发的端到端测试，用法跟 test/Cases/OpenApi/OrderControllerTest.php 一致
 * （走真实路由 + 中间件栈，不用 Hyperf\Testing\TestCase 自带的 get()/post()，原因见
 * BalanceControllerTest 类注释）。
 *
 * 这里不再重复 test/Cases/Service/Order/RechargeOrderPlacementServiceTest.php 已经
 * 覆盖的编排细节（路由/失败切换/幂等的驱动调用次数断言），那些用真实 KasushouDriver
 * 打真实 HTTP 是做不到的（会真的发网络请求）。这里只验证 Controller 这一层：签名
 * 中间件真的把 Merchant 传下去了、输入校验、响应信封的形状——需要真正建单的场景
 * 用一个 driver 未实现的供应商（见 createUnreachableSupplierFor()），不需要 mock
 * 驱动就能走完整链路、不 500。
 *
 * @internal
 * @coversNothing
 */
class RechargeOrderControllerTest extends HttpTestCase
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
            Merchant::destroy($id);
        }
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testNoEligibleSupplierIsRejectedWithoutCreatingOrderOrFreezing()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, '100.00');
        $product = $this->createProduct('10.00');
        // 故意不建 supplier_products 映射行——验证真实 HTTP 链路走到"无可用供应商"
        // 分支时依然是干净的失败响应，不是 500。

        $merchantOrderNo = 'MO-' . uniqid('', true);
        $response = $this->postRecharge($merchant->app_key, $secret, [
            'merchant_order_no' => $merchantOrderNo,
            'product_id' => (string) $product->id,
            'recharge_account' => '13800000000',
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

    public function testIdempotentResubmissionThroughRealHttpReturnsSameOrder()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, '100.00');
        $product = $this->createProduct('10.00');
        $this->createUnreachableSupplierFor($product);

        $merchantOrderNo = 'MO-' . uniqid('', true);
        $payload = [
            'merchant_order_no' => $merchantOrderNo,
            'product_id' => (string) $product->id,
            'recharge_account' => '13800000001',
            'callback_url' => 'https://merchant.example.com/notify',
        ];

        $first = $this->postRecharge($merchant->app_key, $secret, $payload);
        $firstBody = json_decode((string) $first->getBody(), true);

        $second = $this->postRecharge($merchant->app_key, $secret, $payload);
        $secondBody = json_decode((string) $second->getBody(), true);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame($firstBody['data'], $secondBody['data']);
        // requirements.md 8.1：对商户不暴露供应商信息（首次下单与幂等重放都不能带）
        $this->assertArrayNotHasKey('supplier_order_no', $firstBody['data']);
        $this->assertArrayNotHasKey('supplier_order_no', $secondBody['data']);

        $orders = Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->get();
        $this->assertCount(1, $orders, '重复提交不能建出第二条订单');
        $this->orderIds[] = $orders->first()->id;
    }

    public function testMissingRequiredFieldIsRejectedWithInvalidParams()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, '100.00');

        $response = $this->postRecharge($merchant->app_key, $secret, [
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'product_id' => '1',
            // 缺 recharge_account / callback_url
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(41001, $body['code']);
        $this->assertNull($body['data']);
    }

    public function testMalformedCallbackUrlIsRejectedWithInvalidParams()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, '100.00');
        $product = $this->createProduct('10.00');

        $response = $this->postRecharge($merchant->app_key, $secret, [
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'product_id' => (string) $product->id,
            'recharge_account' => '13800000002',
            'callback_url' => 'not-a-url',
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(41001, $body['code']);
    }

    public function testNonRechargeProductIsRejectedWithEnvelopeThroughRealMiddlewareStack()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, '100.00');
        $product = $this->createProduct('10.00', 'card');

        $response = $this->postRecharge($merchant->app_key, $secret, [
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'product_id' => (string) $product->id,
            'recharge_account' => '13800000003',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(42003, json_decode((string) $response->getBody(), true)['code']);

        $this->assertNull(Order::where('merchant_id', $merchant->id)->where('business_line', 'card')->first());
    }

    private function postRecharge(string $appKey, string $secret, array $extra)
    {
        return $this->client->request('POST', '/open-api/orders/recharge', [
            'form_params' => $this->signedParams($appKey, $secret, $extra),
        ]);
    }

    /**
     * 让下单能真正建单但不发网络请求：driver 取一个没实现的值，SupplierDriverFactory
     * 构造驱动时抛异常，SupplierRouter 按"结果未知"处理，订单停在 processing。
     */
    private function createUnreachableSupplierFor(Product $product): void
    {
        $unique = uniqid('recharge_ctrl_test_supplier_', true);

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
        $unique = uniqid('recharge_ctrl_test_', true);

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

        return $merchant;
    }

    private function createProduct(string $salePrice, string $businessLine = 'recharge', string $status = 'on_shelf'): Product
    {
        $unique = uniqid('recharge_ctrl_test_product_', true);

        $product = Product::create([
            'business_line' => $businessLine,
            'name' => $unique,
            'operator' => 'mobile',
            'charge_speed' => 'instant',
            'face_value' => $salePrice,
            'sale_price' => $salePrice,
            'rebate_amount' => '0.00',
            'status' => $status,
        ]);

        $this->productIds[] = $product->id;

        return $product;
    }
}
