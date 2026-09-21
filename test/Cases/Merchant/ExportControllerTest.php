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

use App\Export\ExportLimit;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantRebate;
use App\Model\Order;
use Hyperf\HttpMessage\Exception\HttpException;
use HyperfTest\HttpTestCase;

/**
 * 商户后台三处「导出」（requirements.md 7.2 资金流水 / 返佣、8.2 订单）：
 * `GET /merchant/balance-logs/export`、`/merchant/rebates/export`、`/merchant/orders/export`。
 *
 * 导出接口返回 JSON（前端拼 CSV，理由见 App\Service\Merchant\BalanceLogService::export()），
 * 跟对应列表接口共用同一套筛选和同一份行格式化，所以这里只测导出特有的部分：
 * 不分页（能拿到超过一页的行数）、只给自己的数据、行数上限。
 *
 * @internal
 * @coversNothing
 */
class ExportControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $merchantIds = [];

    private array $orderIds = [];

    protected function tearDown(): void
    {
        MerchantRebate::whereIn('order_id', $this->orderIds)->delete();
        MerchantBalanceLog::whereIn('merchant_id', $this->merchantIds)->delete();
        Order::destroy($this->orderIds);
        Merchant::destroy($this->merchantIds);
        $this->orderIds = [];
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testBalanceLogExportIsNotPaginatedAndFiltersByType()
    {
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        // 超过默认每页 15 条，确认导出拿的是全量而不是第一页
        for ($i = 0; $i < 18; ++$i) {
            $this->createBalanceLog($merchant, 'recharge');
        }
        $this->createBalanceLog($merchant, 'deduct');
        $this->createBalanceLog($other, 'recharge');

        $token = $this->loginAndGetToken($merchant);

        $all = $this->getJson('/merchant/balance-logs/export', $token);
        $this->assertSame(19, $all['total']);
        $this->assertCount(19, $all['data'], '导出不分页');

        $recharges = $this->getJson('/merchant/balance-logs/export', $token, ['type' => 'recharge']);
        $this->assertSame(18, $recharges['total']);
        $this->assertSame(['recharge'], array_values(array_unique(array_column($recharges['data'], 'type'))));

        $this->assertSame(422, $this->get('/merchant/balance-logs/export', $token, ['type' => 'nope'])->getStatusCode());
    }

    public function testRebateExportGivesOwnRowsWithoutInternalFields()
    {
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        $this->createRebate($merchant, 'pending');
        $this->createRebate($merchant, 'settled');
        $this->createRebate($other, 'pending');

        $token = $this->loginAndGetToken($merchant);

        $body = $this->getJson('/merchant/rebates/export', $token);
        $this->assertSame(2, $body['total']);
        $this->assertCount(2, $body['data']);
        foreach (['merchant_id', 'rebate_base', 'rebate_base_source', 'rebate_rate_source', 'level_id'] as $internal) {
            $this->assertArrayNotHasKey($internal, $body['data'][0]);
        }
        // 导出里没有列表才有的分页和汇总字段
        $this->assertArrayNotHasKey('summary', $body);
        $this->assertArrayNotHasKey('page', $body);

        $settled = $this->getJson('/merchant/rebates/export', $token, ['status' => 'settled']);
        $this->assertSame(1, $settled['total']);
        $this->assertSame(422, $this->get('/merchant/rebates/export', $token, ['status' => 'nope'])->getStatusCode());
    }

    public function testOrderExportGivesOwnOrdersAndFilters()
    {
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        $this->createOrder($merchant, 'success');
        $this->createOrder($merchant, 'failed');
        $this->createOrder($other, 'success');

        $token = $this->loginAndGetToken($merchant);

        $body = $this->getJson('/merchant/orders/export', $token);
        $this->assertSame(2, $body['total']);
        // 商户看不到供应商和成本价，跟列表接口同一份 formatOrder()
        $this->assertArrayNotHasKey('cost_price', $body['data'][0]);
        $this->assertArrayNotHasKey('supplier_id', $body['data'][0]);

        $this->assertSame(1, $this->getJson('/merchant/orders/export', $token, ['status' => 'failed'])['total']);
        $this->assertSame(422, $this->get('/merchant/orders/export', $token, ['status' => 'nope'])->getStatusCode());
    }

    /**
     * `/merchant/orders/export` 跟 `/merchant/orders/{orderNo}` 同前缀，静态段必须优先匹配，
     * 否则导出会被当成"查订单号 export 的详情"。
     */
    public function testExportRouteIsNotShadowedByOrderNo()
    {
        $merchant = $this->createMerchant();
        $token = $this->loginAndGetToken($merchant);

        $body = $this->getJson('/merchant/orders/export', $token);

        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('total', $body);
        // 订单详情的响应没有 total，走错路由时这里会是 404（订单号 export 不存在）
        $this->assertSame(0, $body['total']);
    }

    public function testExportsRequireLogin()
    {
        foreach (['/merchant/balance-logs/export', '/merchant/rebates/export', '/merchant/orders/export'] as $path) {
            $this->assertSame(401, $this->client->request('GET', $path)->getStatusCode(), $path);
        }
    }

    /**
     * 行数上限：造一万条数据太慢，直接测守卫本身。超限必须报错而不是截断，
     * 理由见 App\Export\ExportLimit 类注释。
     */
    public function testExportLimitRejectsOversizedResultInsteadOfTruncating()
    {
        ExportLimit::assertWithinLimit(ExportLimit::MAX_ROWS);

        try {
            ExportLimit::assertWithinLimit(ExportLimit::MAX_ROWS + 1);
            $this->fail('超过上限应该抛 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString((string) (ExportLimit::MAX_ROWS + 1), $e->getMessage());
        }
    }

    private function getJson(string $path, string $token, array $query = []): array
    {
        $response = $this->get($path, $token, $query);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function get(string $path, string $token, array $query = [])
    {
        return $this->client->request('GET', $path, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'query' => $query,
        ]);
    }

    private function createBalanceLog(Merchant $merchant, string $type): MerchantBalanceLog
    {
        return MerchantBalanceLog::create([
            'merchant_id' => $merchant->id,
            'type' => $type,
            'amount' => '10.00',
            'available_before' => '100.00',
            'available_after' => '110.00',
            'frozen_before' => '0.00',
            'frozen_after' => '0.00',
            'reason' => '测试流水',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function createOrder(Merchant $merchant, string $status): Order
    {
        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => $status,
            'sale_price' => '100.00',
            'cost_price' => '98.00',
            'frozen_amount' => '100.00',
            'deducted_amount' => $status === 'success' ? '100.00' : null,
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);
        $this->orderIds[] = $order->id;

        return $order;
    }

    private function createRebate(Merchant $merchant, string $status): MerchantRebate
    {
        $order = $this->createOrder($merchant, 'success');
        $completedAt = date('Y-m-d H:i:s', time() - 3600);

        return MerchantRebate::create([
            'order_id' => $order->id,
            'merchant_id' => $merchant->id,
            'business_line' => 'recharge',
            'level_id' => 1,
            'rebate_base' => '5.00',
            'rebate_base_source' => 'product',
            'rebate_rate' => '0.5000',
            'rebate_rate_source' => 'level',
            'amount' => '2.50',
            'status' => $status,
            'order_completed_at' => $completedAt,
            'due_at' => date('Y-m-d H:i:s', strtotime($completedAt) + 7 * 86400),
            'settled_at' => $status === 'settled' ? date('Y-m-d H:i:s') : null,
        ]);
    }

    private function createMerchant(): Merchant
    {
        $merchant = Merchant::create([
            'type' => 'company',
            'phone' => '187' . random_int(10000000, 99999999),
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
