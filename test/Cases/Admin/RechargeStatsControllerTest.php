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

namespace HyperfTest\Cases\Admin;

use App\Model\Merchant;
use App\Model\Order;
use App\Model\OrderRecharge;
use App\Model\Product;
use HyperfTest\HttpTestCase;

/**
 * 话费订单的耗时与成功率（App\Service\Order\RechargeStatsService），系统后台 `GET /admin/recharge-stats`
 * 和商户后台 `GET /merchant/recharge-stats` 两个入口。
 *
 * 测试库是共享的：数据都建在一段远离今天的历史日期上，并按本用例的商户筛选。
 *
 * @internal
 * @coversNothing
 */
class RechargeStatsControllerTest extends HttpTestCase
{
    use CreatesAdmins;

    private const PASSWORD = 'correct-password';

    private const DAY = '2019-03-05';

    private array $merchantIds = [];

    private array $productIds = [];

    private array $orderIds = [];

    protected function tearDown(): void
    {
        OrderRecharge::whereIn('order_id', $this->orderIds ?: [0])->delete();
        Order::destroy($this->orderIds);
        Product::destroy($this->productIds);
        Merchant::destroy($this->merchantIds);
        $this->orderIds = $this->productIds = $this->merchantIds = [];
        $this->cleanUpAdmins();

        parent::tearDown();
    }

    /**
     * 成功率 = 成功 ÷（成功 + 失败 + 已退款），处理中不进分母；耗时只算成功订单，
     * 10、20、300 秒 → 平均 110、中位数 20、90 分位 300。
     */
    public function testSuccessRateAndDurationPercentiles()
    {
        $merchant = $this->createMerchant();
        $mobile = $this->createProduct('mobile', '移动 100 元');
        foreach ([10, 20, 300] as $seconds) {
            $this->createOrder($merchant, $mobile, 'success', $seconds);
        }
        $this->createOrder($merchant, $mobile, 'failed');
        $this->createOrder($merchant, $mobile, 'refunded', 15);
        $this->createOrder($merchant, $mobile, 'processing');
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));

        $body = $this->body($this->jsonRequest('GET', '/admin/recharge-stats?created_from=' . self::DAY . '&created_to=' . self::DAY . '&merchant_id=' . $merchant->id, $token));

        // JSON 会把 60.0 编码成 60，按值比较
        $this->assertEquals([
            'key' => null, 'label' => null, 'total' => 6, 'success' => 3, 'failed' => 1, 'refunded' => 1, 'pending' => 1,
            'success_rate' => 60.0, 'avg_seconds' => 110.0, 'p50_seconds' => 20, 'p90_seconds' => 300,
        ], $body['summary']);
        $this->assertCount(1, $body['data'], '按天，只有一天');
        $this->assertSame(self::DAY, $body['data'][0]['key']);
    }

    public function testGroupByOperatorAndProductAndFillEmptyDays()
    {
        $merchant = $this->createMerchant();
        $mobile = $this->createProduct('mobile', '移动 50 元');
        $unicom = $this->createProduct('unicom', '联通 50 元');
        $this->createOrder($merchant, $mobile, 'success', 8);
        $this->createOrder($merchant, $unicom, 'failed');
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        $range = '&created_from=' . self::DAY . '&created_to=' . self::DAY . '&merchant_id=' . $merchant->id;

        $byOperator = array_column($this->body($this->jsonRequest('GET', '/admin/recharge-stats?group_by=operator' . $range, $token))['data'], null, 'key');
        $this->assertEquals(100, $byOperator['mobile']['success_rate']);
        $this->assertSame(8, $byOperator['mobile']['p50_seconds']);
        $this->assertEquals(0, $byOperator['unicom']['success_rate']);
        $this->assertNull($byOperator['unicom']['avg_seconds'], '没有成功订单时耗时为 null');

        $byProduct = array_column($this->body($this->jsonRequest('GET', '/admin/recharge-stats?group_by=product' . $range, $token))['data'], null, 'key');
        $this->assertSame('移动 50 元', $byProduct[(string) $mobile->id]['label']);

        $days = $this->body($this->jsonRequest('GET', '/admin/recharge-stats?created_from=2019-03-04&created_to=2019-03-06&merchant_id=' . $merchant->id, $token));
        $this->assertSame(['2019-03-04', '2019-03-05', '2019-03-06'], array_column($days['data'], 'key'));
        $this->assertNull($days['data'][0]['success_rate'], '没有订单的日期成功率为 null，不是 0');
    }

    public function testRejectsBadParamsAndRequiresOrderView()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        foreach (['group_by=supplier', 'created_from=yesterday', 'created_from=2019-03-10&created_to=2019-03-01', 'created_from=2019-01-01&created_to=2019-06-01', 'merchant_id=abc'] as $query) {
            $this->assertSame(422, $this->jsonRequest('GET', '/admin/recharge-stats?' . $query, $token)->getStatusCode(), $query);
        }
        $this->assertSame(403, $this->jsonRequest('GET', '/admin/recharge-stats', $this->loginAs($this->createAdminWithPermissions(['merchant.view'])))->getStatusCode());
    }

    /**
     * 商户后台只能看自己的订单，传别人的 merchant_id 也没用。
     */
    public function testMerchantSeesOnlyOwnOrders()
    {
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        $product = $this->createProduct('telecom', '电信 30 元');
        $this->createOrder($merchant, $product, 'success', 5);
        $this->createOrder($other, $product, 'success', 5);
        $this->createOrder($other, $product, 'failed');
        $merchantToken = $this->merchantLogin($merchant);

        $response = $this->client->request('GET', '/merchant/recharge-stats', [
            'headers' => ['Authorization' => 'Bearer ' . $merchantToken],
            'query' => ['created_from' => self::DAY, 'created_to' => self::DAY, 'merchant_id' => $other->id],
        ]);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($merchant->id, $body['merchant_id']);
        $this->assertSame(1, $body['summary']['total']);
        $this->assertSame(401, $this->client->request('GET', '/merchant/recharge-stats')->getStatusCode());
    }

    private function createOrder(Merchant $merchant, Product $product, string $status, ?int $seconds = null): Order
    {
        $createdAt = self::DAY . ' 10:00:00';
        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => $status,
            'sale_price' => '10.00',
            'cost_price' => '9.80',
            'frozen_amount' => '10.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);
        // created_at 不在 fillable 里，直接按主键写
        Order::query()->whereKey($order->id)->update([
            'created_at' => $createdAt,
            'completed_at' => $seconds === null ? null : date('Y-m-d H:i:s', strtotime($createdAt) + $seconds),
        ]);
        OrderRecharge::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'recharge_account' => '13800000000',
            'rebate_amount' => '0.00',
        ]);
        $this->orderIds[] = $order->id;

        return $order;
    }

    private function createProduct(string $operator, string $name): Product
    {
        $product = Product::create([
            'business_line' => 'recharge',
            'name' => $name,
            'operator' => $operator,
            'face_value' => '50.00',
            'sale_price' => '49.50',
            'rebate_amount' => '0.00',
            'status' => 'on_shelf',
        ]);
        $this->productIds[] = $product->id;

        return $product;
    }

    private function createMerchant(): Merchant
    {
        $merchant = Merchant::create([
            'type' => 'company',
            'phone' => '189' . random_int(10000000, 99999999),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'status' => 'active',
        ]);
        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function merchantLogin(Merchant $merchant): string
    {
        $response = $this->client->request('POST', '/merchant/auth/login', [
            'form_params' => ['username' => $merchant->phone, 'password' => self::PASSWORD],
        ]);

        return (string) json_decode((string) $response->getBody(), true)['token'];
    }
}
