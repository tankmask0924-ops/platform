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
use App\Model\MerchantRebate;
use App\Model\Order;
use HyperfTest\HttpTestCase;

/**
 * 商户后台首页统计 `GET /merchant/dashboard`（requirements.md 8.2），口径见 DashboardService 类注释。
 *
 * @internal
 * @coversNothing
 */
class DashboardControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $merchantIds = [];

    private array $orderIds = [];

    protected function tearDown(): void
    {
        MerchantRebate::whereIn('order_id', $this->orderIds)->delete();
        Order::destroy($this->orderIds);
        Merchant::destroy($this->merchantIds);
        $this->orderIds = [];
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testStats()
    {
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();

        // 今天：成功 10、失败、成功后退款、处理中、取消
        $this->createOrder($merchant, 0, 'success', '10.00');
        $this->createOrder($merchant, 0, 'failed', null);
        $this->createOrder($merchant, 0, 'refunded', '10.00', '10.00');
        $this->createOrder($merchant, 0, 'processing', null);
        $this->createOrder($merchant, 0, 'cancelled', null);
        // 3 天前成功 20；8 天前的不在 7 天范围内；别的商户的不算
        $this->createOrder($merchant, 3, 'success', '20.00');
        $this->createOrder($merchant, 8, 'success', '99.00');
        $this->createOrder($other, 0, 'success', '50.00');

        $pending = $this->createOrder($merchant, 3, 'success', '5.00');
        $this->createRebate($merchant, $pending, 'pending', '1.50');
        $settled = $this->createOrder($merchant, 3, 'success', '5.00');
        $this->createRebate($merchant, $settled, 'settled', '9.00');

        $body = $this->get($this->loginAndGetToken($merchant));

        $this->assertSame('1.50', $body['pending_rebate']);
        $this->assertSame(5, $body['today']['order_count']);
        $this->assertSame('10.00', $body['today']['amount']);
        $this->assertSame(1, $body['today']['success_count']);
        $this->assertSame(3, $body['today']['finished_count']);
        $this->assertEquals(33.3, $body['today']['success_rate']);

        $this->assertCount(7, $body['trend']);
        $this->assertSame(date('Y-m-d'), $body['trend'][6]['date']);
        $this->assertSame(date('Y-m-d', strtotime('-6 days')), $body['trend'][0]['date']);
        $this->assertSame(['order_count' => 3, 'amount' => '30.00'], array_intersect_key($body['trend'][3], ['order_count' => 0, 'amount' => 0]));
        $this->assertSame(0, $body['trend'][5]['order_count']);
        $this->assertSame('0.00', $body['trend'][5]['amount']);
    }

    public function testNoFinishedOrdersTodayGivesNullSuccessRate()
    {
        $merchant = $this->createMerchant();
        $this->createOrder($merchant, 0, 'processing', null);

        $body = $this->get($this->loginAndGetToken($merchant));

        $this->assertSame(1, $body['today']['order_count']);
        $this->assertNull($body['today']['success_rate']);
        $this->assertSame('0.00', $body['pending_rebate']);
    }

    public function testNoTokenReturns401()
    {
        $this->assertSame(401, $this->client->request('GET', '/merchant/dashboard')->getStatusCode());
    }

    private function get(string $token): array
    {
        $response = $this->client->request('GET', '/merchant/dashboard', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function createOrder(Merchant $merchant, int $daysAgo, string $status, ?string $deducted, string $refunded = '0.00'): Order
    {
        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => $status,
            'sale_price' => '10.00',
            'cost_price' => '9.00',
            'frozen_amount' => '10.00',
            'deducted_amount' => $deducted,
            'refunded_amount' => $refunded,
            'callback_url' => 'https://merchant.example.com/notify',
        ]);
        $this->orderIds[] = $order->id;
        // 下单时间放在那天的中午，不受跑测试的时刻影响
        Order::query()->where('id', $order->id)->update(['created_at' => date('Y-m-d 12:00:00', strtotime("-{$daysAgo} days"))]);

        return $order;
    }

    private function createRebate(Merchant $merchant, Order $order, string $status, string $amount): void
    {
        MerchantRebate::create([
            'order_id' => $order->id,
            'merchant_id' => $merchant->id,
            'business_line' => 'recharge',
            'level_id' => 1,
            'rebate_base' => $amount,
            'rebate_base_source' => 'product',
            'rebate_rate' => '1.0000',
            'rebate_rate_source' => 'level',
            'amount' => $amount,
            'status' => $status,
        ]);
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

    private function loginAndGetToken(Merchant $merchant): string
    {
        $response = $this->client->request('POST', '/merchant/auth/login', [
            'form_params' => ['username' => $merchant->phone, 'password' => self::PASSWORD],
        ]);

        return (string) json_decode((string) $response->getBody(), true)['token'];
    }
}
