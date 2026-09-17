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
use App\Model\MerchantLevelBusinessRate;
use App\Model\Product;
use App\Model\ProductLevelRebate;
use App\Signature\SignatureSigner;
use HyperfTest\HttpTestCase;

/**
 * 真实 HTTP 派发的端到端测试，用法跟 test/Cases/OpenApi/BalanceControllerTest.php 一致
 * （用 test/HttpTestCase.php 包的 Hyperf\Testing\Client，走真实路由 + 中间件栈，原因见
 * 该类注释，这里不重复）。
 *
 * @internal
 * @coversNothing
 */
class ProductControllerTest extends HttpTestCase
{
    private array $merchantIds = [];

    private array $productIds = [];

    private array $merchantLevelBusinessRateIds = [];

    private array $productLevelRebateIds = [];

    protected function tearDown(): void
    {
        foreach ($this->productLevelRebateIds as $id) {
            ProductLevelRebate::destroy($id);
        }
        $this->productLevelRebateIds = [];

        foreach ($this->merchantLevelBusinessRateIds as $id) {
            MerchantLevelBusinessRate::destroy($id);
        }
        $this->merchantLevelBusinessRateIds = [];

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

    public function testListsOnShelfRechargeProductsWithComputedRebateAndExcludesOffShelf()
    {
        $levelId = random_int(100000, 199999);

        // 该等级话费业务线默认比例 60%。
        $this->createLevelBusinessRate($levelId, '0.6000');

        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, $levelId);

        // 共享测试库里可能有别的用例留下的上架话费商品，名字带上唯一后缀，
        // 断言时只看本用例自己建的商品，不依赖全库只有这几条。
        $suffix = ' #' . uniqid('', true);
        $nameA = '移动 100 元快充' . $suffix;
        $nameB = '联通 200 元快充' . $suffix;
        $nameC = '电信 50 元快充（已下架）' . $suffix;

        // 商品 A：没有单独覆盖，用等级默认 60%：0.50 × 0.60 = 0.30。
        $this->createProduct('recharge', '0.50', 'on_shelf', [
            'name' => $nameA,
            'operator' => 'mobile',
        ]);

        // 商品 B：对该等级单独覆盖成 90%（等级默认是 60%），验证覆盖优先：1.00 × 0.90 = 0.90。
        $productB = $this->createProduct('recharge', '1.00', 'on_shelf', [
            'name' => $nameB,
            'operator' => 'unicom',
        ]);
        $this->createProductOverride($productB->id, $levelId, '0.9000');

        // 商品 C：下架商品，必须被排除在列表之外。
        $this->createProduct('recharge', '0.50', 'off_shelf', [
            'name' => $nameC,
            'operator' => 'telecom',
        ]);

        $response = $this->client->request('GET', '/open-api/products', [
            'query' => $this->signedParams($merchant->app_key, $secret, ['business_line' => 'recharge']),
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);

        $rows = array_column(
            array_filter($body['data'], fn (array $row) => str_ends_with($row['name'], $suffix)),
            null,
            'name'
        );
        $this->assertSame([$nameA, $nameB], array_keys($rows));

        $this->assertSame('mobile', $rows[$nameA]['operator']);
        $this->assertSame('100.00', $rows[$nameA]['face_value']);
        $this->assertSame('99.20', $rows[$nameA]['sale_price']);
        $this->assertSame('0.30', $rows[$nameA]['rebate']);

        $this->assertSame('unicom', $rows[$nameB]['operator']);
        $this->assertSame('0.90', $rows[$nameB]['rebate']);
    }

    public function testCardBusinessLineIsRejectedWithCleanFailureEnvelope()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, null);

        $response = $this->client->request('GET', '/open-api/products', [
            'query' => $this->signedParams($merchant->app_key, $secret, ['business_line' => 'card']),
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(41002, $body['code']);
        $this->assertNull($body['data']);
    }

    public function testMissingBusinessLineIsRejectedWithCleanFailureEnvelope()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, null);

        $response = $this->client->request('GET', '/open-api/products', [
            'query' => $this->signedParams($merchant->app_key, $secret, []),
        ]);

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(41002, $body['code']);
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

    private function createMerchant(string $plainSecret, ?int $levelId): Merchant
    {
        $unique = uniqid('product_ctrl_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'level_id' => $levelId,
            'app_key' => 'app_key_' . $unique,
            'app_secret' => (new Encryptor())->encrypt($plainSecret),
            'available_balance' => '0.00',
            'frozen_balance' => '0.00',
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createProduct(string $businessLine, string $rebateAmount, string $status, array $extra): Product
    {
        $product = Product::create([
            'business_line' => $businessLine,
            'name' => $extra['name'],
            'operator' => $extra['operator'] ?? null,
            'face_value' => '100.00',
            'sale_price' => '99.20',
            'rebate_amount' => $rebateAmount,
            'status' => $status,
        ]);

        $this->productIds[] = $product->id;

        return $product;
    }

    private function createLevelBusinessRate(int $levelId, string $rate): MerchantLevelBusinessRate
    {
        $row = MerchantLevelBusinessRate::create([
            'level_id' => $levelId,
            'business_line' => 'recharge',
            'rebate_rate' => $rate,
        ]);

        $this->merchantLevelBusinessRateIds[] = $row->id;

        return $row;
    }

    private function createProductOverride(int $productId, int $levelId, string $rate): ProductLevelRebate
    {
        $row = ProductLevelRebate::create([
            'product_id' => $productId,
            'level_id' => $levelId,
            'rebate_rate' => $rate,
        ]);

        $this->productLevelRebateIds[] = $row->id;

        return $row;
    }
}
