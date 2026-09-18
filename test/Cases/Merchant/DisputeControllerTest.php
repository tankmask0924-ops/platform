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

use App\Model\AftersaleDispute;
use App\Model\Merchant;
use App\Model\Order;
use HyperfTest\HttpTestCase;

/**
 * 商户管理后台「售后：未到账争议提交与查看」：App\Controller\Merchant\DisputeController +
 * App\Service\Merchant\DisputeService。
 *
 * @internal
 * @coversNothing
 */
class DisputeControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $merchantIds = [];

    private array $orderIds = [];

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $id) {
            AftersaleDispute::where('order_id', $id)->delete();
            Order::destroy($id);
        }
        foreach ($this->merchantIds as $id) {
            Merchant::destroy($id);
        }
        $this->orderIds = $this->merchantIds = [];

        parent::tearDown();
    }

    public function testSubmitForOwnRecentSuccessOrderOnlyOnce()
    {
        $merchant = $this->createMerchant();
        $order = $this->createOrder($merchant, 'success', completedDaysAgo: 6);
        $token = $this->login($merchant);

        $response = $this->post('/merchant/disputes', $token, ['order_no' => $order->order_no]);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('processing', $body['status']);
        $this->assertSame($order->order_no, $body['order_no']);
        $this->assertArrayNotHasKey('handler_id', $body);

        $this->assertSame(409, $this->post('/merchant/disputes', $token, ['order_no' => $order->order_no])->getStatusCode());
        $this->assertSame(1, AftersaleDispute::where('order_id', $order->id)->count());
    }

    public function testSubmitIsRejectedForIneligibleOrders()
    {
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        $token = $this->login($merchant);

        $processing = $this->createOrder($merchant, 'processing');
        $expired = $this->createOrder($merchant, 'success', completedDaysAgo: 8);
        $express = $this->createOrder($merchant, 'success', businessLine: 'express');
        $othersOrder = $this->createOrder($other, 'success');

        $this->assertSame(422, $this->post('/merchant/disputes', $token, ['order_no' => $processing->order_no])->getStatusCode());
        $this->assertSame(422, $this->post('/merchant/disputes', $token, ['order_no' => $expired->order_no])->getStatusCode());
        $this->assertSame(422, $this->post('/merchant/disputes', $token, ['order_no' => $express->order_no])->getStatusCode());
        $this->assertSame(404, $this->post('/merchant/disputes', $token, ['order_no' => $othersOrder->order_no])->getStatusCode());
        $this->assertSame(422, $this->post('/merchant/disputes', $token, [])->getStatusCode());
        $this->assertSame(0, AftersaleDispute::whereIn('order_id', $this->orderIds)->count());
    }

    public function testListAndDetailShowOnlyOwnDisputesWithResult()
    {
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        $mine = $this->createDispute($merchant, 'rejected', ['供应商查询：已到账']);
        $theirs = $this->createDispute($other, 'processing');
        $token = $this->login($merchant);

        $list = json_decode((string) $this->get('/merchant/disputes', $token)->getBody(), true);
        $this->assertSame(1, $list['total']);
        $this->assertSame($mine->id, $list['data'][0]['id']);
        $this->assertSame(['供应商查询：已到账'], $list['data'][0]['evidence']);

        $filtered = json_decode((string) $this->get('/merchant/disputes?status=processing', $token)->getBody(), true);
        $this->assertSame(0, $filtered['total']);

        $detail = $this->get('/merchant/disputes/' . $mine->id, $token);
        $this->assertSame(200, $detail->getStatusCode());
        $this->assertSame('rejected', json_decode((string) $detail->getBody(), true)['status']);

        $this->assertSame(404, $this->get('/merchant/disputes/' . $theirs->id, $token)->getStatusCode());
        $this->assertSame(401, $this->client->request('GET', '/merchant/disputes')->getStatusCode());
    }

    private function createDispute(Merchant $merchant, string $status, ?array $evidence = null): AftersaleDispute
    {
        $order = $this->createOrder($merchant, 'success');

        return AftersaleDispute::create([
            'order_id' => $order->id,
            'merchant_id' => $merchant->id,
            'status' => $status,
            'evidence' => $evidence,
            'result_remark' => $status === 'processing' ? null : '已核实',
            'submitted_at' => date('Y-m-d H:i:s'),
            'resolved_at' => $status === 'processing' ? null : date('Y-m-d H:i:s'),
        ]);
    }

    private function get(string $path, string $token)
    {
        return $this->client->request('GET', $path, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
    }

    private function post(string $path, string $token, array $data)
    {
        return $this->client->request('POST', $path, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'json' => $data,
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

    private function login(Merchant $merchant): string
    {
        $response = $this->client->request('POST', '/merchant/auth/login', [
            'form_params' => ['username' => $merchant->phone, 'password' => self::PASSWORD],
        ]);

        return (string) json_decode((string) $response->getBody(), true)['token'];
    }

    private function createOrder(Merchant $merchant, string $status, int $completedDaysAgo = 1, string $businessLine = 'recharge'): Order
    {
        $completedAt = $status === 'success' ? date('Y-m-d H:i:s', time() - $completedDaysAgo * 86400) : null;
        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => $businessLine,
            'status' => $status,
            'sale_price' => '10.00',
            'cost_price' => '8.00',
            'frozen_amount' => '10.00',
            'deducted_amount' => $status === 'success' ? '10.00' : null,
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
            'completed_at' => $completedAt,
            'finished_at' => $completedAt,
        ]);
        $this->orderIds[] = $order->id;

        return $order;
    }
}
