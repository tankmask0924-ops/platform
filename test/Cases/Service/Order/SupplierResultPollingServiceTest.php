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

use App\Job\NotifyMerchantJob;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderRecharge;
use App\Model\Product;
use App\Model\Supplier;
use App\Model\SupplierProduct;
use App\Service\Order\OrderResultApplier;
use App\Service\Order\SupplierResultPollingService;
use App\Supplier\DriverResult;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Testing\TestCase;
use Mockery;
use RuntimeException;

/**
 * App\Service\Order\SupplierResultPollingService：处理中/结果未知订单的定时查询。
 * 驱动全部 mock，不发真实网络请求。
 *
 * 测试库是共享的，可能有别的处理中订单：本类建的尝试把 updated_at 设到很早，保证排在
 * 批次最前面；驱动工厂遇到不是本测试建的供应商一律抛异常（轮询只记日志，不改订单）。
 *
 * @internal
 * @coversNothing
 */
class SupplierResultPollingServiceTest extends TestCase
{
    private const LONG_AGO = '2000-01-01 00:00:00';

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

    public function testUnknownAttemptIsQueriedAndSuccessCompletesOrder()
    {
        [$merchant, $product, $supplierA] = $this->setUpSuppliers(1);
        $order = $this->createAcceptedOrder($merchant, $product, $supplierA, 'unknown');

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('queryOrder')
            ->once()
            ->with($order->order_no . '-1', false)
            ->andReturn(new DriverResult(result: UnifiedResult::Success, supplierOrderNo: 'SUP-Q-1', actualCost: '7.90'));
        $this->bindDrivers([$supplierA->id => $driver]);
        $this->expectNotify(1);

        $this->poll();

        $order->refresh();
        $this->assertSame('success', $order->status);
        $this->assertSame('SUP-Q-1', $order->supplier_order_no);
        $this->assertSame('7.90', $order->cost_price);
        $this->assertSame('10.00', $order->deducted_amount);
        $this->assertSame('success', $this->attempt($order, 1)->result);

        $merchant->refresh();
        $this->assertSame('90.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);
    }

    public function testRecentlyUpdatedAttemptIsNotQueriedYet()
    {
        [$merchant, $product, $supplierA] = $this->setUpSuppliers(1);
        $order = $this->createAcceptedOrder($merchant, $product, $supplierA, 'processing');
        OrderAttempt::where('order_id', $order->id)->update(['updated_at' => date('Y-m-d H:i:s')]);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldNotReceive('queryOrder');
        $this->bindDrivers([$supplierA->id => $driver]);
        $this->expectNotify(0);

        $this->poll();

        $this->assertSame('processing', $order->refresh()->status);
    }

    public function testStillProcessingKeepsOrderAndWaitsForNextInterval()
    {
        [$merchant, $product, $supplierA] = $this->setUpSuppliers(1);
        $order = $this->createAcceptedOrder($merchant, $product, $supplierA, 'unknown');

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('queryOrder')->once()->andReturn(new DriverResult(result: UnifiedResult::Processing, supplierOrderNo: 'SUP-Q-2'));
        $this->bindDrivers([$supplierA->id => $driver]);
        $this->expectNotify(0);

        $this->poll();
        // 第二轮：刚查过，不到间隔，不会再查（queryOrder 只允许一次）
        $this->poll();

        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame('SUP-Q-2', $order->supplier_order_no);

        $attempt = $this->attempt($order, 1);
        $this->assertSame('processing', $attempt->result);
        $this->assertNotSame(self::LONG_AGO, $attempt->updated_at->toDateTimeString());

        $merchant->refresh();
        $this->assertSame('10.00', $merchant->frozen_balance);
    }

    public function testConfirmedNotFoundSwitchesToNextSupplier()
    {
        [$merchant, $product, $supplierA, $supplierB] = $this->setUpSuppliers(2);
        $order = $this->createAcceptedOrder($merchant, $product, $supplierA, 'unknown');

        $driverA = Mockery::mock(KasushouDriver::class);
        $driverA->shouldReceive('queryOrder')->once()->andReturn(new DriverResult(
            result: UnifiedResult::DefiniteFailure,
            failReason: 'kasushou: order not found for external_orderno=' . $order->order_no . '-1',
        ));
        $driverB = Mockery::mock(KasushouDriver::class);
        $driverB->shouldReceive('placeOrder')->once()->andReturn(new DriverResult(result: UnifiedResult::Processing));
        $this->bindDrivers([$supplierA->id => $driverA, $supplierB->id => $driverB]);
        $this->expectNotify(0);

        $this->poll();

        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($supplierB->id, $order->supplier_id);
        $this->assertSame('failed', $this->attempt($order, 1)->result);
        $this->assertSame('processing', $this->attempt($order, 2)->result);
    }

    public function testOnlyLatestAttemptOfProcessingOrdersIsQueried()
    {
        [$merchant, $product, $supplierA, $supplierB] = $this->setUpSuppliers(2);

        // 旧尝试停在 unknown，但已经切到了第二家
        $switched = $this->createAcceptedOrder($merchant, $product, $supplierA, 'unknown');
        OrderAttempt::create(['order_id' => $switched->id, 'supplier_id' => $supplierB->id, 'attempt_no' => 2, 'result' => 'success']);
        OrderAttempt::where('order_id', $switched->id)->update(['updated_at' => self::LONG_AGO]);

        // 订单已经是终态
        $finished = $this->createAcceptedOrder($merchant, $product, $supplierA, 'unknown');
        Order::where('id', $finished->id)->update(['status' => 'success']);

        $driverA = Mockery::mock(KasushouDriver::class);
        $driverA->shouldNotReceive('queryOrder');
        $driverB = Mockery::mock(KasushouDriver::class);
        $driverB->shouldNotReceive('queryOrder');
        $this->bindDrivers([$supplierA->id => $driverA, $supplierB->id => $driverB]);
        $this->expectNotify(0);

        $this->poll();

        $this->assertSame('processing', $switched->refresh()->status);
    }

    public function testDriverErrorIsLoggedAndRetriedLater()
    {
        [$merchant, $product, $supplierA] = $this->setUpSuppliers(1);
        $order = $this->createAcceptedOrder($merchant, $product, $supplierA, 'unknown');

        $this->bindDrivers([]);
        $this->expectNotify(0);

        $this->poll();

        $order->refresh();
        $this->assertSame('processing', $order->status);

        $attempt = $this->attempt($order, 1);
        $this->assertSame('unknown', $attempt->result);
        $this->assertNotSame(self::LONG_AGO, $attempt->updated_at->toDateTimeString(), '失败也要刷新，避免每轮都卡在同一批');
    }

    /**
     * 回调已经把订单落成终态，另一路（定时查询）手上还是旧的 processing 模型：
     * 不能再扣款、不能再通知商户。
     */
    public function testTerminalResultIsAppliedOnlyOnce()
    {
        [$merchant, $product, $supplierA] = $this->setUpSuppliers(1);
        $order = $this->createAcceptedOrder($merchant, $product, $supplierA, 'unknown');
        $stale = Order::find($order->id);

        $this->expectNotify(1);
        $applier = $this->getContainer()->get(OrderResultApplier::class);
        $success = new DriverResult(result: UnifiedResult::Success, supplierOrderNo: 'SUP-ONCE');

        $applier->apply($order, $success, $supplierA->id);
        $applier->apply($stale, $success, $supplierA->id);
        $applier->apply($stale, new DriverResult(result: UnifiedResult::DefiniteFailure), $supplierA->id);

        $order->refresh();
        $this->assertSame('success', $order->status);
        $this->assertSame(1, MerchantBalanceLog::where('order_id', $order->id)->where('type', 'deduct')->count());
        $this->assertSame(0, MerchantBalanceLog::where('order_id', $order->id)->where('type', 'unfreeze')->count());
    }

    private function poll(): void
    {
        $this->getContainer()->get(SupplierResultPollingService::class)->pollDue();
    }

    private function attempt(Order $order, int $attemptNo): OrderAttempt
    {
        return OrderAttempt::where('order_id', $order->id)->where('attempt_no', $attemptNo)->firstOrFail();
    }

    /**
     * @return list<mixed> [merchant, product, supplier1, supplier2, ...]
     */
    private function setUpSuppliers(int $count): array
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct();

        $suppliers = [];
        for ($i = 1; $i <= $count; ++$i) {
            $supplier = $this->createSupplier();
            $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-' . $i, '8.00', $i);
            $suppliers[] = $supplier;
        }

        return [$merchant, $product, ...$suppliers];
    }

    /**
     * @param array<int, KasushouDriver> $driversBySupplierId
     */
    private function bindDrivers(array $driversBySupplierId): void
    {
        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('build')->andReturnUsing(static function (Supplier $s) use ($driversBySupplierId) {
            return $driversBySupplierId[$s->id] ?? throw new RuntimeException('not a supplier of this test');
        });
        $this->instance(SupplierDriverFactory::class, $factory);
    }

    private function expectNotify(int $times): void
    {
        $asyncDriver = Mockery::mock(DriverInterface::class);
        $asyncDriver->shouldReceive('push')->times($times)->with(Mockery::type(NotifyMerchantJob::class))->andReturnTrue();

        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->shouldReceive('get')->times($times)->with('default')->andReturn($asyncDriver);
        $this->instance(DriverFactory::class, $driverFactory);
    }

    private function createAcceptedOrder(Merchant $merchant, Product $product, Supplier $supplier, string $attemptResult): Order
    {
        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => 'processing',
            'sale_price' => $product->sale_price,
            'cost_price' => '8.00',
            'supplier_id' => $supplier->id,
            'frozen_amount' => $product->sale_price,
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);
        $this->orderIds[] = $order->id;

        OrderRecharge::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'recharge_account' => '13800000200',
            'rebate_amount' => '0.00',
        ]);
        OrderAttempt::create([
            'order_id' => $order->id,
            'supplier_id' => $supplier->id,
            'attempt_no' => 1,
            'result' => $attemptResult,
        ]);
        OrderAttempt::where('order_id', $order->id)->update(['updated_at' => self::LONG_AGO]);

        return $order;
    }

    private function createMerchant(): Merchant
    {
        $unique = uniqid('polling_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => 'encrypted-secret-placeholder',
            'available_balance' => '90.00',
            'frozen_balance' => '10.00',
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createProduct(): Product
    {
        $product = Product::create([
            'business_line' => 'recharge',
            'name' => uniqid('polling_test_product_', true),
            'operator' => 'mobile',
            'charge_speed' => 'instant',
            'face_value' => '10.00',
            'sale_price' => '10.00',
            'rebate_amount' => '0.00',
            'status' => 'on_shelf',
        ]);

        $this->productIds[] = $product->id;

        return $product;
    }

    private function createSupplier(): Supplier
    {
        $unique = uniqid('polling_test_supplier_', true);

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
