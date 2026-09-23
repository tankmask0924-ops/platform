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

namespace HyperfTest\Cases\Service\Order;

use App\Job\PlaceSupplierOrderJob;
use App\Model\Merchant;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderRecharge;
use App\Model\Product;
use App\Service\Order\SupplierOrderDispatcher;
use App\Service\Order\SupplierRouter;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\TestCase;
use Mockery;
use RuntimeException;

use function Hyperf\Support\make;

/**
 * 话费卡券"调用供应商下单"异步分派（requirements.md 9）：入队不路由、入队失败不报错、消费者幂等、
 * 兜底只捡没分派出去的订单、开关关掉时同步路由。路由本身见 SupplierRouterTest。
 *
 * @internal
 * @coversNothing
 */
class SupplierOrderDispatcherTest extends TestCase
{
    private array $merchantIds = [];

    private array $productIds = [];

    private array $orderIds = [];

    /** @var list<int> 这次测试里推进队列的订单 id */
    private array $pushed = [];

    protected function setUp(): void
    {
        parent::setUp();
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->config()->set('supplier.dispatch_async', true);
    }

    protected function tearDown(): void
    {
        OrderAttempt::whereIn('order_id', $this->orderIds ?: [0])->delete();
        OrderRecharge::whereIn('order_id', $this->orderIds ?: [0])->delete();
        Order::destroy($this->orderIds);
        Product::destroy($this->productIds);
        Merchant::destroy($this->merchantIds);
        $this->orderIds = $this->productIds = $this->merchantIds = $this->pushed = [];
        Mockery::close();

        parent::tearDown();
    }

    public function testEnqueuePushesAJobInsteadOfRoutingAndSwallowsQueueErrors()
    {
        $order = $this->createOrder();
        $router = Mockery::mock(SupplierRouter::class);
        $router->shouldNotReceive('routeNewOrder');
        $this->bind(SupplierRouter::class, $router);
        $this->bindQueue();

        $this->dispatcher()->enqueue($order, Product::find($this->productIds[0]), '13800000500');
        $this->assertSame([(int) $order->id], $this->pushed);

        $queue = Mockery::mock(DriverInterface::class);
        $queue->shouldReceive('push')->andThrow(new RuntimeException('redis down'));
        $factory = Mockery::mock(DriverFactory::class);
        $factory->shouldReceive('get')->andReturn($queue);
        $this->bind(DriverFactory::class, $factory);

        // 钱已经冻结、订单已经建好：入队失败不能让下单请求报错，由兜底任务捡回来
        $this->dispatcher()->enqueue($order, Product::find($this->productIds[0]), '13800000500');
        $this->assertSame('processing', $order->refresh()->status);
    }

    public function testDispatchRoutesOnlyProcessingOrdersWithoutAttempts()
    {
        $fresh = $this->createOrder();
        $alreadyTried = $this->createOrder(attempted: true);
        $failed = $this->createOrder('failed');

        $router = Mockery::mock(SupplierRouter::class);
        $router->shouldReceive('routeNewOrder')->once()->with(
            Mockery::on(static fn (Order $o) => $o->id === $fresh->id),
            Mockery::on(fn (Product $p) => (int) $p->id === (int) $this->productIds[0]),
            '13800000500'
        );
        $this->bind(SupplierRouter::class, $router);

        $this->assertTrue($this->dispatcher()->dispatch((int) $fresh->id));
        $this->assertFalse($this->dispatcher()->dispatch((int) $alreadyTried->id), '已经分派过的不再下单');
        $this->assertFalse($this->dispatcher()->dispatch((int) $failed->id));
        $this->assertFalse($this->dispatcher()->dispatch(0));
    }

    public function testRedispatchStalePicksUpOnlyUndispatchedOldOrders()
    {
        $stale = $this->createOrder(createdAt: date('Y-m-d H:i:s', time() - 300));
        $recent = $this->createOrder();
        $tried = $this->createOrder(attempted: true, createdAt: date('Y-m-d H:i:s', time() - 300));
        $abnormal = $this->createOrder('abnormal', createdAt: date('Y-m-d H:i:s', time() - 300));
        $this->bindQueue();

        $this->dispatcher()->redispatchStale();

        $this->assertContains((int) $stale->id, $this->pushed);
        foreach ([$recent, $tried, $abnormal] as $order) {
            $this->assertNotContains((int) $order->id, $this->pushed);
        }
    }

    public function testSyncModeRoutesInsideTheRequest()
    {
        $this->config()->set('supplier.dispatch_async', false);
        $order = $this->createOrder();
        $router = Mockery::mock(SupplierRouter::class);
        $router->shouldReceive('routeNewOrder')->once();
        $this->bind(SupplierRouter::class, $router);
        $this->bindQueue();

        $this->dispatcher()->enqueue($order, Product::find($this->productIds[0]), '13800000500');

        $this->assertSame([], $this->pushed);
        $this->assertSame(0, $this->dispatcher()->redispatchStale(), '同步模式下没有兜底要做');
    }

    public function testJobDelegatesToTheDispatcher()
    {
        $dispatcher = Mockery::mock(SupplierOrderDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->with(42)->andReturnTrue();
        $this->bind(SupplierOrderDispatcher::class, $dispatcher);

        (new PlaceSupplierOrderJob(42))->handle();
        $this->addToAssertionCount(1);
    }

    private function dispatcher(): SupplierOrderDispatcher
    {
        return make(SupplierOrderDispatcher::class);
    }

    private function config(): ConfigInterface
    {
        return ApplicationContext::getContainer()->get(ConfigInterface::class);
    }

    private function bind(string $abstract, object $instance): void
    {
        ApplicationContext::getContainer()->set($abstract, $instance);
    }

    private function bindQueue(): void
    {
        $queue = Mockery::mock(DriverInterface::class);
        $queue->shouldReceive('push')->andReturnUsing(function (PlaceSupplierOrderJob $job) {
            $this->pushed[] = $job->orderId;

            return true;
        });
        $factory = Mockery::mock(DriverFactory::class);
        $factory->shouldReceive('get')->with('default')->andReturn($queue);
        $this->bind(DriverFactory::class, $factory);
    }

    private function createOrder(string $status = 'processing', bool $attempted = false, ?string $createdAt = null): Order
    {
        if ($this->merchantIds === []) {
            $unique = uniqid('dispatcher_test_', true);
            $this->merchantIds[] = Merchant::create([
                'type' => 'company',
                'email' => $unique . '@example.com',
                'password' => 'hashed-password',
                'status' => 'active',
                'app_key' => 'app_key_' . $unique,
                'app_secret' => 'encrypted-secret-placeholder',
                'available_balance' => '100.00',
                'frozen_balance' => '0.00',
            ])->id;
            $this->productIds[] = Product::create([
                'business_line' => 'recharge',
                'name' => uniqid('dispatcher_test_product_', true),
                'operator' => 'mobile',
                'face_value' => '10.00',
                'sale_price' => '10.00',
                'rebate_amount' => '0.00',
                'status' => 'on_shelf',
            ])->id;
        }

        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $this->merchantIds[0],
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => $status,
            'sale_price' => '10.00',
            'cost_price' => '8.00',
            'frozen_amount' => '10.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);
        if ($createdAt !== null) {
            Order::where('id', $order->id)->update(['created_at' => $createdAt]);
        }
        $this->orderIds[] = $order->id;

        OrderRecharge::create([
            'order_id' => $order->id,
            'product_id' => $this->productIds[0],
            'recharge_account' => '13800000500',
            'rebate_amount' => '0.00',
        ]);
        if ($attempted) {
            OrderAttempt::create(['order_id' => $order->id, 'supplier_id' => 1, 'attempt_no' => 1, 'result' => 'processing']);
        }

        return $order->refresh();
    }
}
