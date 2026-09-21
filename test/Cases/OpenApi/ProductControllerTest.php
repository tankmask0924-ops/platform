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
use App\Model\MerchantBusinessSubscription;
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
            MerchantBusinessSubscription::where('merchant_id', $id)->delete();
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

        // 下单要传 product_id，商品列表必须给出
        $this->assertSame($productB->id, $rows[$nameB]['id']);
        $this->assertSame('mobile', $rows[$nameA]['operator']);
        $this->assertSame('100.00', $rows[$nameA]['face_value']);
        $this->assertSame('99.20', $rows[$nameA]['sale_price']);
        $this->assertSame('0.30', $rows[$nameA]['rebate']);

        $this->assertSame('unicom', $rows[$nameB]['operator']);
        $this->assertSame('0.90', $rows[$nameB]['rebate']);
    }

    public function testMerchantWithoutSubscriptionGetsNotSubscribedError()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, null);
        MerchantBusinessSubscription::where('merchant_id', $merchant->id)->where('business_line', 'recharge')->update(['status' => 'rejected']);

        $response = $this->client->request('GET', '/open-api/products', [
            'query' => $this->signedParams($merchant->app_key, $secret, ['business_line' => 'recharge']),
        ]);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(42007, $body['code']);
        $this->assertNull($body['data']);
    }

    /**
     * 卡券商品列表（二期）。`card_type` 是这条业务线的关键字段：direct 下单必须传
     * recharge_account，card_secret 必须不传（见 CardOrderPlacementService），
     * 商品列表不给就没法下单。
     */
    public function testListsCardProductsWithCardTypeAndCardLineRebateRate()
    {
        $levelId = random_int(200000, 299999);
        // 话费 60%、卡券 40%：卡券的返佣必须按卡券那一行算，不能串到话费的比例上
        $this->createLevelBusinessRate($levelId, '0.6000', 'recharge');
        $this->createLevelBusinessRate($levelId, '0.4000', 'card');

        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, $levelId);

        $suffix = ' #' . uniqid('', true);
        $direct = '视频会员月卡（直充）' . $suffix;
        $secretCard = '游戏点卡 100 元（卡密）' . $suffix;
        $offShelf = '已下架卡券' . $suffix;

        $this->createProduct('card', '2.00', 'on_shelf', ['name' => $direct, 'card_type' => 'direct']);
        $this->createProduct('card', '5.00', 'on_shelf', ['name' => $secretCard, 'card_type' => 'card_secret']);
        $this->createProduct('card', '5.00', 'off_shelf', ['name' => $offShelf, 'card_type' => 'card_secret']);
        // 话费商品不能混进卡券列表
        $this->createProduct('recharge', '0.50', 'on_shelf', ['name' => '移动 100 元快充' . $suffix, 'operator' => 'mobile']);

        $response = $this->client->request('GET', '/open-api/products', [
            'query' => $this->signedParams($merchant->app_key, $secret, ['business_line' => 'card']),
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);

        $rows = array_column(
            array_filter($body['data'], fn (array $row) => str_ends_with($row['name'], $suffix)),
            null,
            'name'
        );
        $this->assertSame([$direct, $secretCard], array_keys($rows), '只有在架卡券商品，话费商品不混入');

        $this->assertSame('direct', $rows[$direct]['card_type']);
        $this->assertSame('card_secret', $rows[$secretCard]['card_type']);
        // 卡券商品没有话费专用字段
        $this->assertNull($rows[$direct]['operator']);
        $this->assertNull($rows[$direct]['charge_speed']);
        // 2.00 × 0.40（卡券比例，不是话费的 0.60）
        $this->assertSame('0.80', $rows[$direct]['rebate']);
        $this->assertSame('2.00', $rows[$secretCard]['rebate']);
    }

    public function testRechargeListStillCarriesCardTypeAsNull()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, null);
        $name = '联通 30 元快充 #' . uniqid('', true);
        $this->createProduct('recharge', '0.10', 'on_shelf', ['name' => $name, 'operator' => 'unicom']);

        $response = $this->client->request('GET', '/open-api/products', [
            'query' => $this->signedParams($merchant->app_key, $secret, ['business_line' => 'recharge']),
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $row = array_values(array_filter($body['data'], fn (array $r) => $r['name'] === $name))[0];

        $this->assertArrayHasKey('card_type', $row, '字段对两条业务线都在，话费给 null');
        $this->assertNull($row['card_type']);
        $this->assertSame('unicom', $row['operator']);
    }

    public function testMerchantWithoutCardSubscriptionGetsNotSubscribedError()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, null);
        MerchantBusinessSubscription::where('merchant_id', $merchant->id)->where('business_line', 'card')->update(['status' => 'pending']);

        $response = $this->client->request('GET', '/open-api/products', [
            'query' => $this->signedParams($merchant->app_key, $secret, ['business_line' => 'card']),
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(42007, $body['code']);
        $this->assertNull($body['data']);
    }

    /**
     * 电影票、快递没有本地商品库，仍然要被这个接口挡住。
     */
    public function testBusinessLinesWithoutLocalCatalogAreRejected()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, null);

        foreach (['movie', 'express', 'nope'] as $line) {
            $response = $this->client->request('GET', '/open-api/products', [
                'query' => $this->signedParams($merchant->app_key, $secret, ['business_line' => $line]),
            ]);
            $body = json_decode((string) $response->getBody(), true);
            $this->assertSame(41002, $body['code'], $line);
            $this->assertNull($body['data'], $line);
        }
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
        // 下单和商品查询要求已开通业务线（requirements.md 4.2）
        foreach (['recharge', 'card'] as $line) {
            MerchantBusinessSubscription::create(['merchant_id' => $merchant->id, 'business_line' => $line, 'status' => 'approved', 'applied_at' => date('Y-m-d H:i:s')]);
        }

        return $merchant;
    }

    private function createProduct(string $businessLine, string $rebateAmount, string $status, array $extra): Product
    {
        $product = Product::create([
            'business_line' => $businessLine,
            'name' => $extra['name'],
            'operator' => $extra['operator'] ?? null,
            'card_type' => $extra['card_type'] ?? null,
            'face_value' => '100.00',
            'sale_price' => '99.20',
            'rebate_amount' => $rebateAmount,
            'status' => $status,
        ]);

        $this->productIds[] = $product->id;

        return $product;
    }

    private function createLevelBusinessRate(int $levelId, string $rate, string $businessLine = 'recharge'): MerchantLevelBusinessRate
    {
        $row = MerchantLevelBusinessRate::create([
            'level_id' => $levelId,
            'business_line' => $businessLine,
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
