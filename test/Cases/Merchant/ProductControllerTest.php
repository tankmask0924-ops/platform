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

namespace HyperfTest\Cases\Merchant;

use App\Model\Merchant;
use App\Model\MerchantBusinessSubscription;
use App\Model\MerchantLevelBusinessRate;
use App\Model\Product;
use App\Model\ProductLevelRebate;
use HyperfTest\HttpTestCase;

/**
 * 商户后台「商品价格」`GET /merchant/products`（requirements.md 8.2）：
 * 已开通业务线的在架商品 + 按本等级算的每单返佣（含商品单独覆盖），没开通不返回商品，
 * 电影票/快递给等级比例，不泄露返佣基数。
 *
 * @internal
 * @coversNothing
 */
class ProductControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $merchantIds = [];

    private array $productIds = [];

    private array $levelRateIds = [];

    protected function tearDown(): void
    {
        ProductLevelRebate::whereIn('product_id', $this->productIds)->delete();
        Product::destroy($this->productIds);
        MerchantLevelBusinessRate::destroy($this->levelRateIds);
        MerchantBusinessSubscription::whereIn('merchant_id', $this->merchantIds)->delete();
        Merchant::destroy($this->merchantIds);

        parent::tearDown();
    }

    public function testSubscribedLineListsOnShelfProductsWithLevelRebate()
    {
        $levelId = random_int(200000, 299999);
        $this->createLevelRate($levelId, 'recharge', '0.6000');
        $merchant = $this->createMerchant($levelId, ['recharge']);

        $plain = $this->createProduct('0.50', 'on_shelf');
        $override = $this->createProduct('1.00', 'on_shelf');
        ProductLevelRebate::create(['product_id' => $override->id, 'level_id' => $levelId, 'rebate_rate' => '0.9000']);
        $offShelf = $this->createProduct('0.50', 'off_shelf');

        $body = $this->get($merchant, 'recharge');

        $this->assertTrue($body['subscribed']);
        $this->assertSame('0.6000', $body['level_rate']);
        // 共享库里可能有别的在架商品，只看本用例建的
        $rows = array_column($body['data'], null, 'id');
        $this->assertSame('0.30', $rows[$plain->id]['rebate']);
        $this->assertSame('0.90', $rows[$override->id]['rebate']);
        $this->assertSame('99.20', $rows[$plain->id]['sale_price']);
        $this->assertArrayNotHasKey($offShelf->id, $rows);
        $this->assertArrayNotHasKey('rebate_amount', $rows[$plain->id]);
    }

    public function testUnsubscribedLineReturnsNoProducts()
    {
        $merchant = $this->createMerchant(null, []);
        $this->createProduct('0.50', 'on_shelf');

        $body = $this->get($merchant, 'recharge');

        $this->assertFalse($body['subscribed']);
        $this->assertTrue($body['available']);
        $this->assertSame([], $body['data']);
    }

    public function testRateOnlyLineShowsLevelRate()
    {
        $levelId = random_int(200000, 299999);
        $this->createLevelRate($levelId, 'express', '0.3000');
        $merchant = $this->createMerchant($levelId, []);

        $body = $this->get($merchant, 'express');

        $this->assertFalse($body['available']);
        $this->assertSame('0.3000', $body['level_rate']);
        $this->assertSame([], $body['data']);
    }

    public function testInvalidBusinessLineAndNoToken()
    {
        $response = $this->client->request('GET', '/merchant/products', [
            'headers' => ['Authorization' => 'Bearer ' . $this->login($this->createMerchant(null, []))],
            'query' => ['business_line' => 'nope'],
        ]);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(401, $this->client->request('GET', '/merchant/products')->getStatusCode());
    }

    private function get(Merchant $merchant, string $businessLine): array
    {
        $response = $this->client->request('GET', '/merchant/products', [
            'headers' => ['Authorization' => 'Bearer ' . $this->login($merchant)],
            'query' => ['business_line' => $businessLine],
        ]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function createProduct(string $rebateAmount, string $status): Product
    {
        $product = Product::create([
            'business_line' => 'recharge',
            'name' => '移动 100 元快充 #' . uniqid(),
            'operator' => 'mobile',
            'face_value' => '100.00',
            'sale_price' => '99.20',
            'rebate_amount' => $rebateAmount,
            'status' => $status,
        ]);
        $this->productIds[] = $product->id;

        return $product;
    }

    private function createLevelRate(int $levelId, string $businessLine, string $rate): void
    {
        $this->levelRateIds[] = MerchantLevelBusinessRate::create([
            'level_id' => $levelId,
            'business_line' => $businessLine,
            'rebate_rate' => $rate,
        ])->id;
    }

    /**
     * @param list<string> $subscribedLines
     */
    private function createMerchant(?int $levelId, array $subscribedLines): Merchant
    {
        $merchant = Merchant::create([
            'type' => 'company',
            'phone' => '186' . random_int(10000000, 99999999),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'status' => 'active',
            'level_id' => $levelId,
        ]);
        $this->merchantIds[] = $merchant->id;
        foreach ($subscribedLines as $line) {
            MerchantBusinessSubscription::create(['merchant_id' => $merchant->id, 'business_line' => $line, 'status' => 'approved', 'applied_at' => date('Y-m-d H:i:s')]);
        }

        return $merchant;
    }

    private function login(Merchant $merchant): string
    {
        $response = $this->client->request('POST', '/merchant/auth/login', [
            'form_params' => ['username' => $merchant->phone, 'password' => self::PASSWORD],
        ]);

        return (string) json_decode((string) $response->getBody(), true)['token'];
    }
}
