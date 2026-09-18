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

use App\Crypto\Encryptor;
use App\Job\NotifyMerchantJob;
use App\Model\Merchant;
use App\Model\MerchantNotifyLog;
use App\Model\Order;
use App\Model\OrderRecharge;
use App\Model\Supplier;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\HttpTestCase;
use Mockery;

use function Hyperf\Support\make;

/**
 * 商户管理后台「订单管理」：App\Controller\Merchant\OrderController +
 * App\Service\Merchant\OrderService。最重要的是商户之间互相看不到、动不了对方的订单。
 * 队列换成 Mockery 双重，每个用例重建容器的原因见
 * test/Cases/Controller/NotifySupplierControllerTest.php 类注释。
 *
 * @internal
 * @coversNothing
 */
class OrderControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $merchantIds = [];

    private array $orderIds = [];

    private array $supplierIds = [];

    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->client = make(Client::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $id) {
            MerchantNotifyLog::where('order_id', $id)->delete();
            OrderRecharge::where('order_id', $id)->delete();
            Order::destroy($id);
        }
        foreach ($this->supplierIds as $id) {
            Supplier::destroy($id);
        }
        foreach ($this->merchantIds as $id) {
            Merchant::destroy($id);
        }
        $this->orderIds = $this->merchantIds = $this->supplierIds = [];

        Mockery::close();
        parent::tearDown();
    }

    public function testListShowsOnlyOwnOrdersWithMerchantFacingFields()
    {
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        $processing = $this->createOrder($merchant, 'processing');
        $abnormal = $this->createOrder($merchant, 'abnormal');
        $success = $this->createOrder($merchant, 'success');
        $this->createOrder($other, 'success');
        $token = $this->login($merchant);

        $body = $this->json($this->get('/merchant/orders', $token));

        $this->assertSame(3, $body['total']);
        $this->assertSame([$success->order_no, $abnormal->order_no, $processing->order_no], array_column($body['data'], 'order_no'));
        $this->assertSame('processing', $body['data'][1]['status'], '异常单对商户显示为处理中');
        foreach (['cost_price', 'supplier_id', 'supplier_order_no', 'id', 'merchant_id'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $body['data'][0]);
        }

        $processingOnly = $this->json($this->get('/merchant/orders?status=processing', $token));
        $this->assertEqualsCanonicalizing([$processing->order_no, $abnormal->order_no], array_column($processingOnly['data'], 'order_no'));

        $byNo = $this->json($this->get('/merchant/orders?order_no=' . $success->order_no, $token));
        $this->assertSame([$success->order_no], array_column($byNo['data'], 'order_no'));

        $this->assertSame(422, $this->get('/merchant/orders?status=abnormal', $token)->getStatusCode());
        $this->assertSame(422, $this->get('/merchant/orders?created_from=not-a-date', $token)->getStatusCode());
    }

    public function testMerchantIdQueryParameterCannotWidenTheList()
    {
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        $this->createOrder($other, 'success');
        $token = $this->login($merchant);

        $body = $this->json($this->get('/merchant/orders?merchant_id=' . $other->id, $token));

        $this->assertSame(0, $body['total']);
    }

    public function testDetailIncludesCardSecretAndNotifyLogsButNotOtherMerchantsOrders()
    {
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        $order = $this->createOrder($merchant, 'success', cardPwd: 'CARD-PWD-9');
        MerchantNotifyLog::create([
            'order_id' => $order->id,
            'url' => 'https://merchant.example.com/notify',
            'payload' => ['order_no' => $order->order_no],
            'response_body' => 'fail',
            'http_status' => 500,
            'attempt_no' => 1,
            'success' => false,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $othersOrder = $this->createOrder($other, 'success');
        $token = $this->login($merchant);

        $response = $this->get('/merchant/orders/' . $order->order_no, $token);
        $body = $this->json($response);

        $this->assertSame('success', $body['status']);
        $this->assertSame('CARD-PWD-9', $body['card_pwd']);
        $this->assertSame('13800000600', $body['recharge_account']);
        $this->assertCount(1, $body['notify_logs']);
        $this->assertSame(500, $body['notify_logs'][0]['http_status']);
        $this->assertFalse($body['notify_logs'][0]['success']);
        $this->assertStringNotContainsString('cost_price', (string) $response->getBody());

        $this->assertSame(404, $this->get('/merchant/orders/' . $othersOrder->order_no, $token)->getStatusCode());
    }

    public function testRenotifyRules()
    {
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        $finished = $this->createOrder($merchant, 'failed');
        $abnormal = $this->createOrder($merchant, 'abnormal');
        $recentlyNotified = $this->createOrder($merchant, 'success');
        MerchantNotifyLog::create([
            'order_id' => $recentlyNotified->id,
            'url' => 'https://merchant.example.com/notify',
            'payload' => [],
            'attempt_no' => 1,
            'success' => true,
            'created_at' => date('Y-m-d H:i:s', time() - 10),
        ]);
        $othersOrder = $this->createOrder($other, 'success');
        $token = $this->login($merchant);
        $this->expectNotify(1);

        $this->assertSame(200, $this->post('/merchant/orders/' . $finished->order_no . '/renotify', $token)->getStatusCode());
        $this->assertSame(409, $this->post('/merchant/orders/' . $abnormal->order_no . '/renotify', $token)->getStatusCode());
        $this->assertSame(429, $this->post('/merchant/orders/' . $recentlyNotified->order_no . '/renotify', $token)->getStatusCode());
        $this->assertSame(404, $this->post('/merchant/orders/' . $othersOrder->order_no . '/renotify', $token)->getStatusCode());
    }

    public function testNoTokenReturns401()
    {
        $this->assertSame(401, $this->client->request('GET', '/merchant/orders')->getStatusCode());
        $this->assertSame(401, $this->client->request('POST', '/merchant/orders/R1/renotify')->getStatusCode());
    }

    private function expectNotify(int $times): void
    {
        $queue = Mockery::mock(DriverInterface::class);
        $queue->shouldReceive('push')->times($times)->with(Mockery::type(NotifyMerchantJob::class))->andReturnTrue();
        $factory = Mockery::mock(DriverFactory::class);
        $factory->shouldReceive('get')->times($times)->with('default')->andReturn($queue);
        ApplicationContext::getContainer()->set(DriverFactory::class, $factory);
    }

    private function get(string $path, string $token)
    {
        return $this->client->request('GET', $path, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
    }

    private function post(string $path, string $token)
    {
        return $this->client->request('POST', $path, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
    }

    private function json($response): array
    {
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
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

    private function createOrder(Merchant $merchant, string $status, ?string $cardPwd = null): Order
    {
        $unique = uniqid('merchant_order_test_supplier_', true);
        $supplier = Supplier::create([
            'name' => $unique,
            'code' => substr(md5($unique), 0, 24),
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => 'unused',
            'status' => 'active',
        ]);
        $this->supplierIds[] = $supplier->id;

        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => $status,
            'sale_price' => '10.00',
            'cost_price' => '8.00',
            'supplier_id' => $supplier->id,
            'supplier_order_no' => 'SUP-HIDDEN',
            'frozen_amount' => '10.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);
        $this->orderIds[] = $order->id;

        OrderRecharge::create([
            'order_id' => $order->id,
            'product_id' => 1,
            'recharge_account' => '13800000600',
            'card_pwd' => $cardPwd !== null ? make(Encryptor::class)->encrypt($cardPwd) : null,
            'rebate_amount' => '0.00',
        ]);

        return $order;
    }
}
