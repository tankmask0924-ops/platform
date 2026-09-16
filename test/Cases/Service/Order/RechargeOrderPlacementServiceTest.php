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
use App\Service\Order\RechargeOrderPlacementService;
use App\Supplier\DriverResult;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\UnifiedResult;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Testing\TestCase;
use Mockery;

/**
 * App\Service\Order\RechargeOrderPlacementService 编排逻辑单测。所有供应商 HTTP
 * 调用都通过 Mockery 双重 App\Supplier\Kasushou\KasushouDriver 本身（不是再往下一层
 * 双重 GuzzleHttp\ClientInterface）——因为本类通过 setDriverFactoryForTesting()
 * 整个替换掉 buildDriver() 的产出，测试要验证的是"编排对不对"（选哪个供应商、
 * 什么时候停、什么时候扣款/解冻/通知），不是 KasushouDriver 内部怎么解析 HTTP
 * 响应（那部分已经在 test/Cases/Supplier/Kasushou/KasushouDriverTest.php 覆盖过），
 * 所以直接 mock 驱动的 placeOrder() 方法本身，不会发起任何真实网络请求。
 *
 * 异步通知走 App\Service\MerchantNotifyService -> Hyperf\AsyncQueue\Driver\DriverFactory
 * ->push(new NotifyMerchantJob(...))，用跟 test/Cases/Job/NotifyMerchantJobTest.php
 * 一样的手法把 DriverFactory 换成 Mockery 双重，断言 push() 被调用/不被调用，
 * 不真的进队列、也不真的发起商户回调 HTTP 请求。
 *
 * @internal
 * @coversNothing
 */
class RechargeOrderPlacementServiceTest extends TestCase
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

    public function testHappyPathSingleSupplierSuccessDeductsBalanceAndPushesNotify()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00');
        $supplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-1', '8.00', 1);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('placeOrder')->once()->andReturn(new DriverResult(
            result: UnifiedResult::Success,
            supplierOrderNo: 'SUP-ORDER-1',
            actualCost: '8.00',
        ));

        $this->expectNotify(1);

        $service = $this->makeService($driver);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();
        $result = $service->place($merchant, $merchantOrderNo, $product->id, '13800000000', 'https://merchant.example.com/notify');

        $order = $this->findOrderOrFail($merchant->id, $merchantOrderNo);

        $this->assertSame('success', $result['status']);
        $this->assertSame('success', $order->status);
        $this->assertSame('8.00', $order->cost_price);
        $this->assertSame($supplier->id, $order->supplier_id);
        $this->assertSame('SUP-ORDER-1', $order->supplier_order_no);
        $this->assertSame('10.00', $order->deducted_amount);
        $this->assertNotNull($order->completed_at);

        $merchant->refresh();
        $this->assertSame('90.00', $merchant->available_balance, '冻结后扣款：可用余额只应该被扣一次');
        $this->assertSame('0.00', $merchant->frozen_balance);

        $recharge = OrderRecharge::where('order_id', $order->id)->first();
        $this->assertNotNull($recharge);
        $this->assertSame('13800000000', $recharge->recharge_account);
        $this->assertSame($product->id, $recharge->product_id);

        $attempts = OrderAttempt::where('order_id', $order->id)->get();
        $this->assertCount(1, $attempts);
        $this->assertSame('success', $attempts->first()->result);
        $this->assertSame($supplier->id, $attempts->first()->supplier_id);
        $this->assertSame(1, $attempts->first()->attempt_no);
    }

    public function testFailoverToSecondSupplierAfterDefiniteFailure()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00');
        $supplierA = $this->createSupplier();
        $supplierB = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplierA->id, 'GOODS-A', '8.00', 1);
        $this->createSupplierProduct($product->id, $supplierB->id, 'GOODS-B', '8.50', 2);

        $driverA = Mockery::mock(KasushouDriver::class);
        $driverA->shouldReceive('placeOrder')->once()->andReturn(new DriverResult(
            result: UnifiedResult::DefiniteFailure,
            failReason: 'kasushou: order status 4',
        ));

        $driverB = Mockery::mock(KasushouDriver::class);
        $driverB->shouldReceive('placeOrder')->once()->andReturn(new DriverResult(
            result: UnifiedResult::Success,
            supplierOrderNo: 'SUP-B-1',
            actualCost: '8.50',
        ));

        $this->expectNotify(1);

        $service = $this->getContainer()->get(RechargeOrderPlacementService::class);
        $service->setDriverFactoryForTesting(static fn (Supplier $supplier) => $supplier->id === $supplierA->id ? $driverA : $driverB);

        $merchantOrderNo = $this->uniqueMerchantOrderNo();
        $service->place($merchant, $merchantOrderNo, $product->id, '13800000001', 'https://merchant.example.com/notify');

        $order = $this->findOrderOrFail($merchant->id, $merchantOrderNo);

        $this->assertSame('success', $order->status);
        $this->assertSame($supplierB->id, $order->supplier_id);
        $this->assertSame('8.50', $order->cost_price);

        $attempts = OrderAttempt::where('order_id', $order->id)->orderBy('attempt_no')->get();
        $this->assertCount(2, $attempts);
        $this->assertSame('failed', $attempts[0]->result);
        $this->assertSame($supplierA->id, $attempts[0]->supplier_id);
        $this->assertSame(1, $attempts[0]->attempt_no);
        $this->assertSame('success', $attempts[1]->result);
        $this->assertSame($supplierB->id, $attempts[1]->supplier_id);
        $this->assertSame(2, $attempts[1]->attempt_no);

        $merchant->refresh();
        $this->assertSame('90.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);

        // 余额只应该被 freeze+deduct 各处理一次，不能因为失败切换而被多算。
        $this->assertSame(1, MerchantBalanceLog::where('order_id', $order->id)->where('type', 'freeze')->count());
        $this->assertSame(1, MerchantBalanceLog::where('order_id', $order->id)->where('type', 'deduct')->count());
    }

    public function testAllSuppliersDefiniteFailureUnfreezesAndMarksFailed()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00');
        $supplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-1', '8.00', 1);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('placeOrder')->once()->andReturn(new DriverResult(
            result: UnifiedResult::DefiniteFailure,
            failReason: 'kasushou: order status 4',
        ));

        $this->expectNotify(1);

        $service = $this->makeService($driver);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();
        $service->place($merchant, $merchantOrderNo, $product->id, '13800000002', 'https://merchant.example.com/notify');

        $order = $this->findOrderOrFail($merchant->id, $merchantOrderNo);

        $this->assertSame('failed', $order->status);
        $this->assertSame('kasushou: order status 4', $order->fail_reason);
        $this->assertSame('8.00', $order->cost_price);
        $this->assertNotNull($order->finished_at);

        $merchant->refresh();
        $this->assertSame('100.00', $merchant->available_balance, '解冻后可用余额要回到冻结前');
        $this->assertSame('0.00', $merchant->frozen_balance);
    }

    public function testProcessingResultKeepsOrderProcessingBalanceFrozenAndDoesNotNotify()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00');
        $supplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-1', '8.00', 1);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('placeOrder')->once()->andReturn(new DriverResult(
            result: UnifiedResult::Processing,
            supplierOrderNo: 'SUP-1',
        ));

        // 关键断言：处理中不是终态，NotifyMerchantJob 不应该被推送。
        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->shouldNotReceive('get');
        $this->instance(DriverFactory::class, $driverFactory);

        $service = $this->makeService($driver);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();
        $service->place($merchant, $merchantOrderNo, $product->id, '13800000003', 'https://merchant.example.com/notify');

        $order = $this->findOrderOrFail($merchant->id, $merchantOrderNo);

        $this->assertSame('processing', $order->status);
        $this->assertSame($supplier->id, $order->supplier_id);
        $this->assertSame('SUP-1', $order->supplier_order_no);
        $this->assertNull($order->completed_at);
        $this->assertNull($order->finished_at);

        $merchant->refresh();
        $this->assertSame('90.00', $merchant->available_balance);
        $this->assertSame('10.00', $merchant->frozen_balance, '处理中不是终态，冻结余额必须原样保留');
    }

    public function testInsufficientBalanceFailsBeforeAnySupplierCall()
    {
        $merchant = $this->createMerchant('5.00');
        $product = $this->createProduct('10.00');
        $supplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-1', '8.00', 1);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldNotReceive('placeOrder');

        $this->expectNotify(1);

        $service = $this->makeService($driver);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();
        $service->place($merchant, $merchantOrderNo, $product->id, '13800000004', 'https://merchant.example.com/notify');

        $order = $this->findOrderOrFail($merchant->id, $merchantOrderNo);

        $this->assertSame('failed', $order->status);
        $this->assertSame('0.00', $order->frozen_amount, '冻结失败时没有真的冻结任何钱');
        $this->assertStringContainsString('余额不足', (string) $order->fail_reason);

        $this->assertSame(0, OrderAttempt::where('order_id', $order->id)->count());
        $this->assertNull(OrderRecharge::where('order_id', $order->id)->first());
        $this->assertSame(0, MerchantBalanceLog::where('order_id', $order->id)->count(), '余额不足时事务里不应该有任何流水');

        $merchant->refresh();
        $this->assertSame('5.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);
    }

    /**
     * 全篇最重要的一条：同一个 merchant_order_no 提交两次，第二次绝不能再调用一次
     * freeze()/驱动——不是靠"响应看起来正常"去推断，而是靠 Mockery 的 once() 期望
     * （驱动 mock 和 push() mock 都设成 once()，第二次调用如果真的发生，
     * tearDown() 里的 Mockery::close() 会让测试失败）加上"只有一条 freeze 流水"
     * 的数据库断言两道防线共同证明。
     */
    public function testIdempotentResubmissionDoesNotFreezeOrCallDriverTwice()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00');
        $supplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-1', '8.00', 1);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('placeOrder')->once()->andReturn(new DriverResult(
            result: UnifiedResult::Success,
            supplierOrderNo: 'SUP-1',
            actualCost: '8.00',
        ));

        $this->expectNotify(1);

        $service = $this->makeService($driver);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();

        $first = $service->place($merchant, $merchantOrderNo, $product->id, '13800000005', 'https://merchant.example.com/notify');
        $second = $service->place($merchant, $merchantOrderNo, $product->id, '13800000005', 'https://merchant.example.com/notify');

        $this->assertSame($first, $second, '重复提交必须原样返回同一笔订单的状态');

        $orders = Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->get();
        $this->assertCount(1, $orders, '不能为同一个 merchant_order_no 建出第二条 Order');
        $this->orderIds[] = $orders->first()->id;

        $this->assertSame(
            1,
            MerchantBalanceLog::where('order_id', $orders->first()->id)->where('type', 'freeze')->count(),
            'freeze 流水必须只有一条，证明 freeze() 真的只被调用了一次'
        );

        $merchant->refresh();
        $this->assertSame('90.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);
    }

    public function testNoEligibleSupplierProductsResultsInCleanFailedOrder()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00');
        // 故意不建任何 supplier_products 映射行。

        $this->expectNotify(1);

        $service = $this->getContainer()->get(RechargeOrderPlacementService::class);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();
        $service->place($merchant, $merchantOrderNo, $product->id, '13800000006', 'https://merchant.example.com/notify');

        $order = $this->findOrderOrFail($merchant->id, $merchantOrderNo);

        $this->assertSame('failed', $order->status);
        $this->assertSame('无可用供应商', $order->fail_reason);
        $this->assertSame('0.00', $order->cost_price);

        $merchant->refresh();
        $this->assertSame('100.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);
    }

    /**
     * 覆盖 requirements.md 6.3/6.5 的路由资格过滤三条件：映射行 status != active、
     * stock = 0、供应商本身 status != active，三种都应该被跳过，不应该触发任何驱动
     * 调用，最终等效于"没有可用供应商"。
     */
    public function testIneligibleSupplierProductsAreSkippedEntirely()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00');

        $pausedSupplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $pausedSupplier->id, 'GOODS-PAUSED', '8.00', 1, 'paused');

        $outOfStockSupplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $outOfStockSupplier->id, 'GOODS-OOS', '8.00', 2, 'active', 0);

        $disabledSupplier = $this->createSupplier('disabled');
        $this->createSupplierProduct($product->id, $disabledSupplier->id, 'GOODS-DISABLED', '8.00', 3);

        $this->expectNotify(1);

        $service = $this->getContainer()->get(RechargeOrderPlacementService::class);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();
        $service->place($merchant, $merchantOrderNo, $product->id, '13800000009', 'https://merchant.example.com/notify');

        $order = $this->findOrderOrFail($merchant->id, $merchantOrderNo);

        $this->assertSame('failed', $order->status);
        $this->assertSame('无可用供应商', $order->fail_reason);
        $this->assertSame(0, OrderAttempt::where('order_id', $order->id)->count());
    }

    public function testNonRechargeProductThrowsHttpExceptionWithoutSideEffects()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00', 'card');

        $service = $this->getContainer()->get(RechargeOrderPlacementService::class);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();

        try {
            $service->place($merchant, $merchantOrderNo, $product->id, '13800000010', 'https://merchant.example.com/notify');
            $this->fail('expected HttpException for non-recharge product');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertNull(Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->first());

        $merchant->refresh();
        $this->assertSame('100.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);
    }

    public function testOffShelfProductThrowsHttpExceptionWithoutSideEffects()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00', 'recharge', 'off_shelf');

        $service = $this->getContainer()->get(RechargeOrderPlacementService::class);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();

        try {
            $service->place($merchant, $merchantOrderNo, $product->id, '13800000011', 'https://merchant.example.com/notify');
            $this->fail('expected HttpException for off-shelf product');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertNull(Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->first());
    }

    public function testUnknownProductIdThrowsNotFoundHttpException()
    {
        $merchant = $this->createMerchant('100.00');

        $service = $this->getContainer()->get(RechargeOrderPlacementService::class);

        try {
            $service->place($merchant, $this->uniqueMerchantOrderNo(), 999999999, '13800000012', 'https://merchant.example.com/notify');
            $this->fail('expected HttpException for unknown product');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    private function makeService(KasushouDriver $driver): RechargeOrderPlacementService
    {
        $service = $this->getContainer()->get(RechargeOrderPlacementService::class);
        $service->setDriverFactoryForTesting(static fn () => $driver);

        return $service;
    }

    /**
     * 断言这次下单最终会（且只会）推一次 NotifyMerchantJob——跟
     * test/Cases/Job/NotifyMerchantJobTest.php 同样的容器 swap 手法，
     * DriverFactory 换成 Mockery 双重，不真的进 Redis 队列。
     */
    private function expectNotify(int $times): void
    {
        $asyncDriver = Mockery::mock(DriverInterface::class);
        $asyncDriver->shouldReceive('push')->times($times)->with(Mockery::type(NotifyMerchantJob::class))->andReturnTrue();

        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->shouldReceive('get')->times($times)->with('default')->andReturn($asyncDriver);
        $this->instance(DriverFactory::class, $driverFactory);
    }

    private function findOrderOrFail(int $merchantId, string $merchantOrderNo): Order
    {
        $order = Order::where('merchant_id', $merchantId)->where('merchant_order_no', $merchantOrderNo)->first();
        $this->assertNotNull($order);
        $this->orderIds[] = $order->id;

        return $order;
    }

    private function uniqueMerchantOrderNo(): string
    {
        return 'MO-' . uniqid('', true);
    }

    private function createMerchant(string $availableBalance): Merchant
    {
        $unique = uniqid('recharge_order_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => 'encrypted-secret-placeholder',
            'available_balance' => $availableBalance,
            'frozen_balance' => '0.00',
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createProduct(string $salePrice, string $businessLine = 'recharge', string $status = 'on_shelf'): Product
    {
        $unique = uniqid('recharge_order_test_product_', true);

        $product = Product::create([
            'business_line' => $businessLine,
            'name' => $unique,
            'operator' => 'mobile',
            'charge_speed' => 'instant',
            'face_value' => $salePrice,
            'sale_price' => $salePrice,
            'rebate_amount' => '0.00',
            'status' => $status,
        ]);

        $this->productIds[] = $product->id;

        return $product;
    }

    private function createSupplier(string $status = 'active'): Supplier
    {
        $unique = uniqid('recharge_order_test_supplier_', true);
        // suppliers.code 是 varchar(32)，uniqid(..., true) 加前缀会超长，code 单独
        // 用一个短的、基于 md5 截断的值，保证在长度限制内仍然唯一。
        $code = substr(md5($unique), 0, 24);

        $supplier = Supplier::create([
            'name' => $unique,
            'code' => $code,
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => 'unused-in-test-driver-factory-is-overridden',
            'status' => $status,
        ]);

        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }

    private function createSupplierProduct(
        int $productId,
        int $supplierId,
        string $code,
        string $costPrice,
        int $priority,
        string $status = 'active',
        ?int $stock = null
    ): SupplierProduct {
        $mapping = SupplierProduct::create([
            'product_id' => $productId,
            'supplier_id' => $supplierId,
            'supplier_product_code' => $code,
            'cost_price' => $costPrice,
            'priority' => $priority,
            'status' => $status,
            'stock' => $stock,
        ]);

        $this->supplierProductIds[] = $mapping->id;

        return $mapping;
    }
}
