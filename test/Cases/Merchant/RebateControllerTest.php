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
 * 商户后台「返佣明细」`GET /merchant/rebates`（requirements.md 8.2）。
 * 返佣行直接插 Model（生成逻辑见 OrderResultApplierRebateTest），这里只测查询：
 * 只能看到自己的、筛选、按状态汇总、不返回平台内部字段。
 *
 * @internal
 * @coversNothing
 */
class RebateControllerTest extends HttpTestCase
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

    public function testListsOwnRebatesWithSummaryAndHidesInternalFields()
    {
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        $this->createRebate($merchant, 'pending', '1.20');
        $this->createRebate($merchant, 'pending', '0.30');
        $settled = $this->createRebate($merchant, 'settled', '2.00');
        $this->createRebate($other, 'pending', '9.99');

        $body = $this->get($this->loginAndGetToken($merchant));

        $this->assertSame(3, $body['total']);
        $this->assertSame($settled->id, $body['data'][0]['id']);
        $this->assertSame(['count' => 2, 'amount' => '1.50'], $body['summary']['pending']);
        $this->assertSame(['count' => 1, 'amount' => '2.00'], $body['summary']['settled']);
        $this->assertSame(['count' => 0, 'amount' => '0.00'], $body['summary']['voided']);

        $row = $body['data'][0];
        $this->assertSame('2.00', $row['amount']);
        $this->assertNotNull($row['order_no']);
        $this->assertNotNull($row['due_at']);
        foreach (['merchant_id', 'rebate_base', 'rebate_base_source', 'rebate_rate_source', 'level_id'] as $internal) {
            $this->assertArrayNotHasKey($internal, $row);
        }
    }

    public function testFilters()
    {
        $merchant = $this->createMerchant();
        $token = $this->loginAndGetToken($merchant);
        $this->createRebate($merchant, 'pending', '1.00');
        $voided = $this->createRebate($merchant, 'voided', '3.00');
        $orderNo = Order::find($voided->order_id)->order_no;

        $byStatus = $this->get($token, ['status' => 'voided']);
        $this->assertSame([$voided->id], array_column($byStatus['data'], 'id'));
        $this->assertSame(['count' => 0, 'amount' => '0.00'], $byStatus['summary']['pending']);

        $this->assertSame([$voided->id], array_column($this->get($token, ['order_no' => $orderNo])['data'], 'id'));
        $this->assertSame(0, $this->get($token, ['business_line' => 'card'])['total']);
        $this->assertSame(0, $this->get($token, ['created_to' => date('Y-m-d', time() - 86400)])['total']);
        $this->assertSame(2, $this->get($token, ['created_from' => date('Y-m-d')])['total']);
    }

    public function testCannotSeeAnotherMerchantsRebateByOrderNo()
    {
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        $rebate = $this->createRebate($other, 'pending', '1.00');

        $body = $this->get($this->loginAndGetToken($merchant), ['order_no' => Order::find($rebate->order_id)->order_no]);

        $this->assertSame(0, $body['total']);
    }

    public function testInvalidFilterReturns422()
    {
        $token = $this->loginAndGetToken($this->createMerchant());

        foreach ([['status' => 'nope'], ['business_line' => 'nope'], ['created_from' => 'not a date']] as $query) {
            $response = $this->client->request('GET', '/merchant/rebates', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query' => $query,
            ]);
            $this->assertSame(422, $response->getStatusCode(), json_encode($query));
        }
    }

    public function testNoTokenReturns401()
    {
        $this->assertSame(401, $this->client->request('GET', '/merchant/rebates')->getStatusCode());
    }

    private function get(string $token, array $query = []): array
    {
        $response = $this->client->request('GET', '/merchant/rebates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'query' => $query,
        ]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function createRebate(Merchant $merchant, string $status, string $amount): MerchantRebate
    {
        $completedAt = date('Y-m-d H:i:s', time() - 3600);
        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => 'success',
            'sale_price' => '100.00',
            'cost_price' => '98.00',
            'frozen_amount' => '100.00',
            'deducted_amount' => '100.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
            'completed_at' => $completedAt,
            'finished_at' => $completedAt,
        ]);
        $this->orderIds[] = $order->id;

        return MerchantRebate::create([
            'order_id' => $order->id,
            'merchant_id' => $merchant->id,
            'business_line' => 'recharge',
            'level_id' => 1,
            'rebate_base' => '5.00',
            'rebate_base_source' => 'product',
            'rebate_rate' => '0.5000',
            'rebate_rate_source' => 'level',
            'amount' => $amount,
            'status' => $status,
            'order_completed_at' => $completedAt,
            'due_at' => date('Y-m-d H:i:s', strtotime($completedAt) + 7 * 86400),
            'settled_at' => $status === 'settled' ? date('Y-m-d H:i:s') : null,
            'voided_at' => $status === 'voided' ? date('Y-m-d H:i:s') : null,
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
