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

use App\Exception\OpenApiException;
use App\Job\NotifyMerchantJob;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderRecharge;
use App\Model\Product;
use App\Model\Supplier;
use App\Model\SupplierProduct;
use App\OpenApi\ErrorCode;
use App\Service\Merchant\BalanceService;
use App\Service\Order\RechargeOrderPlacementService;
use App\Supplier\DriverResult;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Testing\TestCase;
use Mockery;

/**
 * App\Service\Order\RechargeOrderPlacementService 编排逻辑单测。所有供应商 HTTP
 * 调用都通过 Mockery 双重 App\Supplier\Kasushou\KasushouDriver 本身（不是再往下一层
 * 双重 GuzzleHttp\ClientInterface）——因为本类把 App\Supplier\SupplierDriverFactory
 * 整个换成 Mockery 双重（`$this->instance(SupplierDriverFactory::class, ...)`，
 * 跟 test/Cases/Job/NotifyMerchantJobTest.php 把 DriverFactory 换成 Mockery 双重
 * 是同一个容器 swap 手法），`build()` 直接返回预先配置好的驱动双重，测试要验证的是
 * "编排对不对"（选哪个供应商、什么时候停、什么时候扣款/解冻/通知），不是
 * KasushouDriver 内部怎么解析 HTTP 响应（那部分已经在
 * test/Cases/Supplier/Kasushou/KasushouDriverTest.php 覆盖过），所以直接 mock
 * 驱动的 placeOrder() 方法本身，不会发起任何真实网络请求。
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
            // 上面按 order_id 清理只覆盖 freeze/deduct/unfreeze 那几种挂了 order_id
            // 的流水；`testMerchantRecoversAfterRechargeAndCanPlaceOrderAgain()`
            // 直接调用了 BalanceService::adjust()/recharge()，留下的是
            // order_id 为 null、只挂 merchant_id 的 adjustment/recharge 流水，
            // 这里按 merchant_id 兜底清掉，跟 BalanceServiceTest::tearDown() 同一个
            // 惯例。对没有这类流水的其它测试是无害的空操作。
            MerchantBalanceLog::where('merchant_id', $id)->delete();
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

        $driverFactory = Mockery::mock(SupplierDriverFactory::class);
        $driverFactory->shouldReceive('build')
            ->with(Mockery::on(static fn (Supplier $supplier) => $supplier->id === $supplierA->id))
            ->andReturn($driverA);
        $driverFactory->shouldReceive('build')
            ->with(Mockery::on(static fn (Supplier $supplier) => $supplier->id === $supplierB->id))
            ->andReturn($driverB);
        $this->instance(SupplierDriverFactory::class, $driverFactory);

        $service = $this->getContainer()->get(RechargeOrderPlacementService::class);

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
        $this->assertSame(ErrorCode::OrderFailed->message(), $order->fail_reason);
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
        $this->assertSame(ErrorCode::InsufficientBalance->message(), $order->fail_reason);

        $this->assertSame(0, OrderAttempt::where('order_id', $order->id)->count());
        $this->assertNull(OrderRecharge::where('order_id', $order->id)->first());
        $this->assertSame(0, MerchantBalanceLog::where('order_id', $order->id)->count(), '余额不足时事务里不应该有任何流水');

        $merchant->refresh();
        $this->assertSame('5.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);
    }

    /**
     * requirements.md 4.5「负余额」：`BalanceService::isSuspended()` 为 true
     * （即 `available_balance < 0`）时，暂停该商户所有下单——一次全新的下单尝试
     * （还没有已存在的订单可以幂等重放）必须被干净、快速地拒绝，不创建任何
     * Order 行，不调用 `BalanceService::freeze()`，更不能碰到供应商驱动。用跟
     * `testInsufficientBalanceFailsBeforeAnySupplierCall()` 同样的严格性验证
     * "没有调用"：驱动 mock 设成 `shouldNotReceive('placeOrder')`，真的调用了会在
     * `Mockery::close()` 时让测试失败,不是靠事后查数据库表推断。
     *
     * 商户摆成 `available_balance` 为负、`debt_since` 同步非空的一致状态（正是
     * `BalanceService::persistBalance()` 真实写入后会留下的样子），不是只改
     * `debt_since` 而余额仍为正——拦截闸门现在直接读实时余额，不再信
     * `debt_since` 这个派生缓存本身。
     */
    public function testMerchantInDebtIsRejectedCleanlyWithoutAnySideEffects()
    {
        $merchant = $this->createMerchant('-50.00', '2026-01-01 08:00:00');
        $product = $this->createProduct('10.00');
        $supplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-1', '8.00', 1);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldNotReceive('placeOrder');

        $service = $this->makeService($driver);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();

        try {
            $service->place($merchant, $merchantOrderNo, $product->id, '13800000013', 'https://merchant.example.com/notify');
            $this->fail('expected OpenApiException for merchant currently in debt');
        } catch (OpenApiException $e) {
            $this->assertSame(ErrorCode::MerchantSuspended, $e->errorCode);
            $this->assertStringContainsString('欠款', $e->getMessage());
        }

        $this->assertNull(
            Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->first(),
            '欠款拒单不应该创建任何 Order 行'
        );
        // order_attempts.order_id 有外键指向 orders 表，上面已经证明这个商户/
        // merchant_order_no 组合没有任何 Order 行，自然不可能存在挂在它下面的
        // OrderAttempt 行——不需要再单独查一遍。
        $this->assertSame(0, MerchantBalanceLog::where('merchant_id', $merchant->id)->count(), '欠款拒单不应该有任何余额流水（freeze 从未被调用）');

        $merchant->refresh();
        $this->assertSame('-50.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);
    }

    /**
     * requirements.md 4.5「负余额」拒单逻辑必须放在幂等重放查找*之后*：一个商户
     * 在欠款之前已经成功下过的订单，即使商户现在恰好处于欠款状态，重新提交同一个
     * `merchant_order_no` 也必须原样拿回那笔旧订单的状态，不能被新加的欠款拦截
     * 挡住——这正是本任务说明里点名要验证"拦截点位置放对了"的场景。
     */
    public function testIdempotentResubmissionStillReturnsExistingOrderWhileMerchantIsCurrentlyInDebt()
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

        $first = $service->place($merchant, $merchantOrderNo, $product->id, '13800000014', 'https://merchant.example.com/notify');

        // 订单成功之后，商户"后来"陷入欠款（比如另一笔手动扣款调账）——直接改
        // 内存里这个 Merchant 对象再 save()，不经过 BalanceService（那部分穿越
        // 判断逻辑已经在 BalanceServiceTest 里覆盖过，这里只关心下单流程这边的
        // 拦截点位置对不对）。拦截闸门现在读的是实时 `available_balance`，所以
        // 这里连同 `debt_since` 一起摆成一致的"当前欠款"状态。
        $merchant->fill(['available_balance' => '-10.00', 'debt_since' => '2026-01-01 08:00:00'])->save();

        // 第二次调用如果真的触发了驱动/freeze，Mockery 的 once() 期望会在
        // tearDown() 里的 Mockery::close() 让测试失败，是比"看返回值像成功"更硬的
        // 证据；这里同时也直接断言返回值等于第一次的响应，证明真的是原样重放。
        $second = $service->place($merchant, $merchantOrderNo, $product->id, '13800000014', 'https://merchant.example.com/notify');

        $this->assertSame($first, $second, '商户当前欠款不应该影响已存在订单的幂等重放');

        $order = $this->findOrderOrFail($merchant->id, $merchantOrderNo);
        $this->assertSame('success', $order->status);
    }

    /**
     * requirements.md 4.5「充值补足到 ≥ 0 后自动恢复」端到端验证：不是分别验证
     * "扣成负数后拒单"和"充值后恢复"两个独立场景凑巧都通过，而是同一个商户在
     * 同一个测试里真的依次经历 `BalanceService::adjust()` 扣成负数 -> 下单被拒
     * -> `BalanceService::recharge()` 补回非负 -> 下单成功这四步连续发生，证明
     * `persistBalance()` 的 debt_since 穿越判断和 `isSuspended()` 的实时余额判断
     * 真的接上了，不需要任何单独的"恢复"动作/开关/管理后台按钮。
     *
     * 全程只用一个 `$service`/一个驱动 mock：驱动 mock 只设一次 `shouldReceive
     * ('placeOrder')->once()`（对应第二次、恢复后的下单尝试），第一次欠款期间的
     * `place()` 调用如果真的碰到了驱动，会走成功分支返回而不是抛异常，
     * `$this->fail(...)` 会先于 Mockery 计数暴露这个错误；两道证据叠加，比单独
     * 任何一道都更硬。
     */
    public function testMerchantRecoversAfterRechargeAndCanPlaceOrderAgain()
    {
        $merchant = $this->createMerchant('50.00');
        $product = $this->createProduct('10.00');
        $supplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-1', '8.00', 1);

        /** @var BalanceService $balanceService */
        $balanceService = $this->getContainer()->get(BalanceService::class);

        // 制造欠款：财务手动扣款调账（4.5 列举的三个会让余额变负的场景之一），
        // 扣完之后可用余额从 50.00 变成 -20.00，跨越 0 这条线。
        $balanceService->adjust($merchant->id, '-70.00', '测试：制造欠款', null);
        $merchant->refresh();
        $this->assertSame('-20.00', $merchant->available_balance);
        $this->assertNotNull($merchant->debt_since, 'adjust() 扣成负数之后 debt_since 应该被设置');
        $this->assertTrue($balanceService->isSuspended($merchant));

        $driver = Mockery::mock(KasushouDriver::class);

        $service = $this->makeService($driver);

        $rejectedMerchantOrderNo = $this->uniqueMerchantOrderNo();
        try {
            $service->place($merchant, $rejectedMerchantOrderNo, $product->id, '13800000015', 'https://merchant.example.com/notify');
            $this->fail('expected OpenApiException while merchant is currently in debt');
        } catch (OpenApiException $e) {
            $this->assertSame(ErrorCode::MerchantSuspended, $e->errorCode);
            $this->assertStringContainsString('欠款', $e->getMessage());
        }

        $this->assertNull(
            Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $rejectedMerchantOrderNo)->first(),
            '欠款期间的下单尝试不应该创建任何 Order 行'
        );
        $this->assertSame(
            0,
            MerchantBalanceLog::where('merchant_id', $merchant->id)->where('type', 'freeze')->count(),
            '欠款期间的下单尝试不应该调用 freeze()'
        );

        // 充值补足到 ≥ 0——4.5「自动恢复」，不需要任何单独的恢复动作。
        $balanceService->recharge($merchant->id, '30.00', '测试：充值补足欠款');
        $merchant->refresh();
        $this->assertSame('10.00', $merchant->available_balance);
        $this->assertNull($merchant->debt_since, 'recharge() 补足到 ≥ 0 之后 debt_since 应该被清空');
        $this->assertFalse($balanceService->isSuspended($merchant));

        $driver->shouldReceive('placeOrder')->once()->andReturn(new DriverResult(
            result: UnifiedResult::Success,
            supplierOrderNo: 'SUP-RECOVERY',
            actualCost: '8.00',
        ));
        $this->expectNotify(1);

        $successMerchantOrderNo = $this->uniqueMerchantOrderNo();
        $service->place($merchant, $successMerchantOrderNo, $product->id, '13800000015', 'https://merchant.example.com/notify');

        $order = $this->findOrderOrFail($merchant->id, $successMerchantOrderNo);
        $this->assertSame('success', $order->status, '充值补足欠款之后应该能重新成功下单');
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

    /**
     * requirements.md 6.5：没有可用供应商时下单接口直接返回失败，不建单、不冻结余额。
     */
    public function testNoEligibleSupplierProductsIsRejectedWithoutFreezing()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00');
        // 故意不建任何 supplier_products 映射行。

        $this->assertRejectedAsProductUnavailable($merchant, $product, '13800000006');
    }

    /**
     * 覆盖 requirements.md 6.5 的筛选条件：映射行非 active、库存为 0、供应商停用、
     * 供应商余额低于成本价，四种都被跳过，不触发任何驱动调用，等效于"没有可用供应商"。
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

        $poorSupplier = $this->createSupplier();
        $poorSupplier->fill(['balance' => '7.99'])->save();
        $this->createSupplierProduct($product->id, $poorSupplier->id, 'GOODS-POOR', '8.00', 4);

        $driverFactory = Mockery::mock(SupplierDriverFactory::class);
        $driverFactory->shouldNotReceive('build');
        $this->instance(SupplierDriverFactory::class, $driverFactory);

        $this->assertRejectedAsProductUnavailable($merchant, $product, '13800000009');
    }

    public function testSupplierWithUnknownBalanceIsStillRouted()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00');
        $supplier = $this->createSupplier();
        $this->assertNull($supplier->balance);
        $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-1', '8.00', 1);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('placeOrder')->once()->andReturn(new DriverResult(result: UnifiedResult::Processing));

        $service = $this->makeService($driver);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();
        $service->place($merchant, $merchantOrderNo, $product->id, '13800000013', 'https://merchant.example.com/notify');

        $this->assertSame('processing', $this->findOrderOrFail($merchant->id, $merchantOrderNo)->status);
    }

    public function testNonRechargeProductThrowsOpenApiExceptionWithoutSideEffects()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00', 'card');

        $service = $this->getContainer()->get(RechargeOrderPlacementService::class);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();

        try {
            $service->place($merchant, $merchantOrderNo, $product->id, '13800000010', 'https://merchant.example.com/notify');
            $this->fail('expected OpenApiException for non-recharge product');
        } catch (OpenApiException $e) {
            $this->assertSame(ErrorCode::ProductBusinessLineMismatch, $e->errorCode);
        }

        $this->assertNull(Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->first());

        $merchant->refresh();
        $this->assertSame('100.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);
    }

    public function testOffShelfProductThrowsOpenApiExceptionWithoutSideEffects()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00', 'recharge', 'off_shelf');

        $service = $this->getContainer()->get(RechargeOrderPlacementService::class);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();

        try {
            $service->place($merchant, $merchantOrderNo, $product->id, '13800000011', 'https://merchant.example.com/notify');
            $this->fail('expected OpenApiException for off-shelf product');
        } catch (OpenApiException $e) {
            $this->assertSame(ErrorCode::ProductNotOnShelf, $e->errorCode);
        }

        $this->assertNull(Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->first());
    }

    public function testUnknownProductIdThrowsProductNotFound()
    {
        $merchant = $this->createMerchant('100.00');

        $service = $this->getContainer()->get(RechargeOrderPlacementService::class);

        try {
            $service->place($merchant, $this->uniqueMerchantOrderNo(), 999999999, '13800000012', 'https://merchant.example.com/notify');
            $this->fail('expected OpenApiException for unknown product');
        } catch (OpenApiException $e) {
            $this->assertSame(ErrorCode::ProductNotFound, $e->errorCode);
        }
    }

    private function assertRejectedAsProductUnavailable(Merchant $merchant, Product $product, string $rechargeAccount): void
    {
        $service = $this->getContainer()->get(RechargeOrderPlacementService::class);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();

        try {
            $service->place($merchant, $merchantOrderNo, $product->id, $rechargeAccount, 'https://merchant.example.com/notify');
            $this->fail('expected OpenApiException when no supplier is available');
        } catch (OpenApiException $e) {
            $this->assertSame(ErrorCode::ProductUnavailable, $e->errorCode);
        }

        $this->assertNull(Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->first());

        $merchant->refresh();
        $this->assertSame('100.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);
    }

    private function makeService(KasushouDriver $driver): RechargeOrderPlacementService
    {
        $driverFactory = Mockery::mock(SupplierDriverFactory::class);
        $driverFactory->shouldReceive('build')->andReturn($driver);
        $this->instance(SupplierDriverFactory::class, $driverFactory);

        return $this->getContainer()->get(RechargeOrderPlacementService::class);
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

    private function createMerchant(string $availableBalance, ?string $debtSince = null): Merchant
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
            'debt_since' => $debtSince,
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
