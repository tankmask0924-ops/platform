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

use App\Dao\OrderAttemptDao;
use App\Job\NotifyMerchantJob;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderRecharge;
use App\Model\Product;
use App\Model\Supplier;
use App\Model\SupplierCircuitBreaker;
use App\Model\SupplierProduct;
use App\OpenApi\ErrorCode;
use App\Service\Order\SupplierCallbackService;
use App\Service\Order\SupplierRouter;
use App\Supplier\DriverResult;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Testing\TestCase;
use Mockery;

/**
 * requirements.md 6.5 受理后异步回调失败的切换：通过 SupplierCallbackService::handle()
 * 进入 App\Service\Order\SupplierRouter。驱动全部 mock（回调解析 + 下一家下单），
 * 不发真实网络请求。同步下单的路由在 RechargeOrderPlacementServiceTest /
 * CardOrderPlacementServiceTest 里覆盖。
 *
 * 场景都是：订单已经受理，第一家（attempt 1）处于 processing，余额已冻结。
 *
 * @internal
 * @coversNothing
 */
class SupplierRouterTest extends TestCase
{
    private array $merchantIds = [];

    private array $productIds = [];

    private array $supplierIds = [];

    private array $supplierProductIds = [];

    private array $orderIds = [];

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $id) {
            OrderAttempt::where('order_id', $id)->delete();
            OrderRecharge::where('order_id', $id)->delete();
            MerchantBalanceLog::where('order_id', $id)->delete();
            Order::destroy($id);
        }
        $this->orderIds = [];

        foreach ($this->supplierProductIds as $id) {
            SupplierProduct::destroy($id);
        }
        $this->supplierProductIds = [];

        foreach ($this->supplierIds as $id) {
            SupplierCircuitBreaker::where('supplier_id', $id)->delete();
            Supplier::destroy($id);
        }
        $this->supplierIds = [];

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

    public function testAsyncDefiniteFailureSwitchesToNextSupplierWithSameOrderNo()
    {
        [$merchant, $product, $supplierA, $supplierB] = $this->setUpTwoSuppliers();
        $order = $this->createAcceptedOrder($merchant, $product, $supplierA, '8.00');

        $driverA = $this->callbackDriver($order->order_no . '-1', new DriverResult(
            result: UnifiedResult::DefiniteFailure,
            failReason: 'kasushou: order status 4',
        ));

        $driverB = Mockery::mock(KasushouDriver::class);
        $driverB->shouldReceive('placeOrder')
            ->once()
            ->withArgs(static fn (string $externalOrderNo, string $goodsId, string $safePrice, string $notifyUrl, array $attach, int $quantity, bool $isCardProduct) => $externalOrderNo === $order->order_no . '-2'
                && $goodsId === 'GOODS-B'
                && str_ends_with($notifyUrl, '/notify/' . $supplierB->code . '/' . $supplierB->notify_token)
                && $attach === ['recharge_account' => '13800000100']
                && $isCardProduct === false)
            ->andReturn(new DriverResult(result: UnifiedResult::Processing, supplierOrderNo: 'SUP-B-1'));

        $this->bindDrivers([$supplierA->id => $driverA, $supplierB->id => $driverB]);
        $this->expectNotify(0);

        $reply = $this->handleCallback($supplierA);

        $this->assertSame('ok', $reply);

        $order->refresh();
        $this->assertSame('processing', $order->status, '换了供应商，订单对商户仍在处理中');
        $this->assertSame($supplierB->id, $order->supplier_id);
        $this->assertSame('8.50', $order->cost_price);

        $attempts = OrderAttempt::where('order_id', $order->id)->orderBy('attempt_no')->get();
        $this->assertCount(2, $attempts);
        $this->assertSame('failed', $attempts[0]->result);
        $this->assertSame('kasushou: order status 4', $attempts[0]->fail_reason);
        $this->assertSame($supplierB->id, $attempts[1]->supplier_id);
        $this->assertSame('processing', $attempts[1]->result);

        $merchant->refresh();
        $this->assertSame('90.00', $merchant->available_balance);
        $this->assertSame('10.00', $merchant->frozen_balance, '切换期间余额保持冻结');
    }

    public function testAsyncDefiniteFailureAfterSwitchWindowFailsOrderWithoutSwitching()
    {
        [$merchant, $product, $supplierA, $supplierB] = $this->setUpTwoSuppliers();
        $order = $this->createAcceptedOrder($merchant, $product, $supplierA, '8.00');
        // 默认切换时长 30 分钟
        Order::where('id', $order->id)->update(['created_at' => date('Y-m-d H:i:s', time() - 31 * 60)]);

        $driverA = $this->callbackDriver($order->order_no . '-1', new DriverResult(result: UnifiedResult::DefiniteFailure));
        $driverB = Mockery::mock(KasushouDriver::class);
        $driverB->shouldNotReceive('placeOrder');

        $this->bindDrivers([$supplierA->id => $driverA, $supplierB->id => $driverB]);
        $this->expectNotify(1);

        $this->handleCallback($supplierA);

        $order->refresh();
        $this->assertSame('failed', $order->status);
        $this->assertSame(ErrorCode::OrderFailed->message(), $order->fail_reason);
        $this->assertSame(1, OrderAttempt::where('order_id', $order->id)->count());

        $merchant->refresh();
        $this->assertSame('100.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);
    }

    public function testAsyncDefiniteFailureOnLastSupplierFailsOrder()
    {
        [$merchant, $product, $supplierA, $supplierB] = $this->setUpTwoSuppliers();
        $order = $this->createAcceptedOrder($merchant, $product, $supplierA, '8.00');
        // A 已经同步明确失败过，B 受理后异步失败
        OrderAttempt::where('order_id', $order->id)->update(['result' => 'failed']);
        OrderAttempt::create(['order_id' => $order->id, 'supplier_id' => $supplierB->id, 'attempt_no' => 2, 'result' => 'processing']);
        $order->fill(['supplier_id' => $supplierB->id])->save();

        $driverB = $this->callbackDriver($order->order_no . '-2', new DriverResult(result: UnifiedResult::DefiniteFailure));
        $driverB->shouldNotReceive('placeOrder');
        $driverA = Mockery::mock(KasushouDriver::class);
        $driverA->shouldNotReceive('placeOrder');

        $this->bindDrivers([$supplierA->id => $driverA, $supplierB->id => $driverB]);
        $this->expectNotify(1);

        $this->handleCallback($supplierB);

        $order->refresh();
        $this->assertSame('failed', $order->status);
        $this->assertSame(2, OrderAttempt::where('order_id', $order->id)->count(), '每家最多尝试一次');

        $merchant->refresh();
        $this->assertSame('100.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);
    }

    public function testStaleCallbackForEarlierAttemptIsIgnored()
    {
        [$merchant, $product, $supplierA, $supplierB] = $this->setUpTwoSuppliers();
        $order = $this->createAcceptedOrder($merchant, $product, $supplierA, '8.00');
        OrderAttempt::where('order_id', $order->id)->update(['result' => 'failed']);
        OrderAttempt::create(['order_id' => $order->id, 'supplier_id' => $supplierB->id, 'attempt_no' => 2, 'result' => 'processing']);
        $order->fill(['supplier_id' => $supplierB->id])->save();

        // 供应商 A 按间隔重推同一个失败回调
        $driverA = $this->callbackDriver($order->order_no . '-1', new DriverResult(result: UnifiedResult::DefiniteFailure));
        $driverB = Mockery::mock(KasushouDriver::class);
        $driverB->shouldNotReceive('placeOrder');

        $this->bindDrivers([$supplierA->id => $driverA, $supplierB->id => $driverB]);
        $this->expectNotify(0);

        $this->assertSame('ok', $this->handleCallback($supplierA));

        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($supplierB->id, $order->supplier_id);
        $this->assertSame('processing', OrderAttempt::where('order_id', $order->id)->where('attempt_no', 2)->value('result'));

        $merchant->refresh();
        $this->assertSame('10.00', $merchant->frozen_balance);
    }

    public function testAttemptNoCanOnlyBeClaimedOnce()
    {
        [$merchant, $product, $supplierA, $supplierB] = $this->setUpTwoSuppliers();
        $order = $this->createAcceptedOrder($merchant, $product, $supplierA, '8.00');

        $dao = $this->getContainer()->get(OrderAttemptDao::class);

        $this->assertNotNull($dao->claim($order->id, $supplierB->id, 2));
        $this->assertNull($dao->claim($order->id, $supplierB->id, 2), '并发切换时只有一个请求能占到下一个序号');
    }

    /**
     * requirements.md 6.6：熔断中的供应商不再分配新订单。这里验证的是路由筛选那一层
     * （`eligibleCandidates()`），判定逻辑本身在 CircuitBreakerServiceTest 里。
     */
    public function testPausedSupplierIsSkippedByRouting()
    {
        [, $product, $supplierA, $supplierB] = $this->setUpTwoSuppliers();
        $router = $this->getContainer()->get(SupplierRouter::class);

        $this->assertSame(
            [$supplierA->id, $supplierB->id],
            array_map(static fn (array $c) => $c[1]->id, $router->eligibleCandidates($product)),
        );

        SupplierCircuitBreaker::create([
            'supplier_id' => $supplierA->id,
            'product_id' => SupplierCircuitBreaker::PRODUCT_ID_ALL,
            'status' => SupplierCircuitBreaker::STATUS_PAUSED,
            'paused_until' => date('Y-m-d H:i:s', time() + 300),
            'triggered_reason' => '测试熔断',
        ]);

        $this->assertSame(
            [$supplierB->id],
            array_map(static fn (array $c) => $c[1]->id, $router->eligibleCandidates($product)),
            '熔断中的 A 被跳过，仍然能路由到 B',
        );
        $this->assertTrue($router->hasEligibleSupplier($product));
    }

    /**
     * 所有供应商都熔断时，下单前预检就该判定"商品暂不可售"，不建单不冻结。
     */
    public function testProductBecomesUnavailableWhenEverySupplierIsPaused()
    {
        [, $product, $supplierA, $supplierB] = $this->setUpTwoSuppliers();
        $router = $this->getContainer()->get(SupplierRouter::class);

        foreach ([$supplierA, $supplierB] as $supplier) {
            SupplierCircuitBreaker::create([
                'supplier_id' => $supplier->id,
                'product_id' => SupplierCircuitBreaker::PRODUCT_ID_ALL,
                'status' => SupplierCircuitBreaker::STATUS_PAUSED,
                'paused_until' => date('Y-m-d H:i:s', time() + 300),
                'triggered_reason' => '测试熔断',
            ]);
        }

        $this->assertFalse($router->hasEligibleSupplier($product));
    }

    /**
     * 到期的熔断行不再拦路由，不用等定时任务把它写回 normal。
     */
    public function testExpiredPauseNoLongerBlocksRouting()
    {
        [, $product, $supplierA] = $this->setUpTwoSuppliers();
        SupplierCircuitBreaker::create([
            'supplier_id' => $supplierA->id,
            'product_id' => SupplierCircuitBreaker::PRODUCT_ID_ALL,
            // 库里还标着 paused，但截止时间已经过了
            'status' => SupplierCircuitBreaker::STATUS_PAUSED,
            'paused_until' => date('Y-m-d H:i:s', time() - 1),
            'triggered_reason' => '已过期',
        ]);

        $candidates = $this->getContainer()->get(SupplierRouter::class)->eligibleCandidates($product);

        $this->assertContains($supplierA->id, array_map(static fn (array $c) => $c[1]->id, $candidates));
    }

    /**
     * @return array{0: Merchant, 1: Product, 2: Supplier, 3: Supplier}
     */
    private function setUpTwoSuppliers(): array
    {
        $merchant = $this->createMerchant('90.00', '10.00');
        $product = $this->createProduct('10.00');
        $supplierA = $this->createSupplier();
        $supplierB = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplierA->id, 'GOODS-A', '8.00', 1);
        $this->createSupplierProduct($product->id, $supplierB->id, 'GOODS-B', '8.50', 2);

        return [$merchant, $product, $supplierA, $supplierB];
    }

    private function callbackDriver(string $externalOrderNo, DriverResult $result): KasushouDriver|Mockery\MockInterface
    {
        $result = new DriverResult(
            result: $result->result,
            supplierOrderNo: $result->supplierOrderNo,
            failReason: $result->failReason,
            rawRequest: ['external_orderno' => $externalOrderNo],
        );

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('parseCallback')->once()->andReturn($result);

        return $driver;
    }

    /**
     * @param array<int, KasushouDriver> $driversBySupplierId
     */
    private function bindDrivers(array $driversBySupplierId): void
    {
        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('build')->andReturnUsing(static fn (Supplier $s) => $driversBySupplierId[$s->id]);
        $this->instance(SupplierDriverFactory::class, $factory);
    }

    private function handleCallback(Supplier $supplier): string
    {
        return $this->getContainer()->get(SupplierCallbackService::class)
            ->handle($supplier->code, ['external_orderno' => 'unused-driver-is-mocked'], []);
    }

    private function expectNotify(int $times): void
    {
        $asyncDriver = Mockery::mock(DriverInterface::class);
        $asyncDriver->shouldReceive('push')->times($times)->with(Mockery::type(NotifyMerchantJob::class))->andReturnTrue();

        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->shouldReceive('get')->times($times)->with('default')->andReturn($asyncDriver);
        $this->instance(DriverFactory::class, $driverFactory);
    }

    private function createAcceptedOrder(Merchant $merchant, Product $product, Supplier $supplier, string $costPrice): Order
    {
        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => 'processing',
            'sale_price' => $product->sale_price,
            'cost_price' => $costPrice,
            'supplier_id' => $supplier->id,
            'frozen_amount' => $product->sale_price,
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);
        $this->orderIds[] = $order->id;

        OrderRecharge::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'recharge_account' => '13800000100',
            'rebate_amount' => '0.00',
        ]);
        OrderAttempt::create([
            'order_id' => $order->id,
            'supplier_id' => $supplier->id,
            'attempt_no' => 1,
            'result' => 'processing',
        ]);

        return $order;
    }

    private function createMerchant(string $availableBalance, string $frozenBalance): Merchant
    {
        $unique = uniqid('supplier_router_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => 'encrypted-secret-placeholder',
            'available_balance' => $availableBalance,
            'frozen_balance' => $frozenBalance,
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createProduct(string $salePrice): Product
    {
        $product = Product::create([
            'business_line' => 'recharge',
            'name' => uniqid('supplier_router_test_product_', true),
            'operator' => 'mobile',
            'charge_speed' => 'instant',
            'face_value' => $salePrice,
            'sale_price' => $salePrice,
            'rebate_amount' => '0.00',
            'status' => 'on_shelf',
        ]);

        $this->productIds[] = $product->id;

        return $product;
    }

    private function createSupplier(): Supplier
    {
        $unique = uniqid('supplier_router_test_supplier_', true);

        $supplier = Supplier::create([
            'name' => $unique,
            'code' => substr(md5($unique), 0, 24),
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => 'unused-in-test-driver-factory-is-overridden',
            'status' => 'active',
        ]);

        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }

    private function createSupplierProduct(int $productId, int $supplierId, string $code, string $costPrice, int $priority): void
    {
        $mapping = SupplierProduct::create([
            'product_id' => $productId,
            'supplier_id' => $supplierId,
            'supplier_product_code' => $code,
            'cost_price' => $costPrice,
            'priority' => $priority,
            'status' => 'active',
        ]);

        $this->supplierProductIds[] = $mapping->id;
    }
}
