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

use App\Dao\SystemSettingDao;
use App\Job\NotifyMerchantJob;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderRecharge;
use App\Model\Product;
use App\Model\Supplier;
use App\Model\SupplierProduct;
use App\Service\OpenApi\OrderQueryService;
use App\Service\Order\AbnormalOrderService;
use App\Service\Order\SupplierCallbackService;
use App\Service\Order\SupplierResultPollingService;
use App\Supplier\DriverResult;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Testing\TestCase;
use Mockery;

/**
 * App\Service\Order\AbnormalOrderService 异常单标记，以及标记之后回调、定时查询、
 * 开放 API 对异常单的处理。驱动全部 mock。
 *
 * @internal
 * @coversNothing
 */
class AbnormalOrderServiceTest extends TestCase
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

    public function testProcessingOrderPastAbnormalHoursIsMarkedWithoutTouchingBalanceOrNotifying()
    {
        [$merchant, $product, $supplierA] = $this->setUpSuppliers(1);
        $overdue = $this->createAcceptedOrder($merchant, $product, $supplierA, hoursAgo: 25);
        $recent = $this->createAcceptedOrder($merchant, $product, $supplierA, hoursAgo: 23);
        $finished = $this->createAcceptedOrder($merchant, $product, $supplierA, hoursAgo: 48);
        Order::where('id', $finished->id)->update(['status' => 'success']);

        $this->expectNotify(0);

        $marked = $this->service()->markOverdue();

        $this->assertContains($overdue->id, $marked);
        $this->assertNotContains($recent->id, $marked);
        $this->assertNotContains($finished->id, $marked);

        $this->assertSame('abnormal', $overdue->refresh()->status);
        $this->assertNull($overdue->finished_at);
        $this->assertSame('processing', $recent->refresh()->status);
        $this->assertSame('success', $finished->refresh()->status);

        $merchant->refresh();
        $this->assertSame('70.00', $merchant->available_balance);
        $this->assertSame('30.00', $merchant->frozen_balance, '异常单余额保持冻结');
        $this->assertSame(0, MerchantBalanceLog::where('order_id', $overdue->id)->count());

        $this->assertSame([], array_intersect([$overdue->id], $this->service()->markOverdue()), '已标记的不会重复标记');
    }

    public function testAbnormalHoursSettingIsHonoured()
    {
        $this->bindSettings(['abnormal_order_hours' => 2]);
        [$merchant, $product, $supplierA] = $this->setUpSuppliers(1);
        $order = $this->createAcceptedOrder($merchant, $product, $supplierA, hoursAgo: 3);

        $this->expectNotify(0);

        $this->assertContains($order->id, $this->service()->markOverdue());
        $this->assertSame(2, $this->service()->abnormalHours());
    }

    public function testInvalidAbnormalHoursSettingFallsBackToDefault()
    {
        $this->bindSettings(['abnormal_order_hours' => 0]);

        $this->assertSame(AbnormalOrderService::DEFAULT_HOURS, $this->service()->abnormalHours());
    }

    /**
     * 即使切换时长被配得比异常单时长还长，异常单收到明确失败也不能去下一家下单。
     */
    public function testDefiniteFailureCallbackOnAbnormalOrderIsOnlyRecorded()
    {
        $this->bindSettings(['switch_duration_minutes' => 100000]);
        [$merchant, $product, $supplierA, $supplierB] = $this->setUpSuppliers(2);
        $order = $this->createAcceptedOrder($merchant, $product, $supplierA, hoursAgo: 25);
        $this->service()->markOverdue();

        $driverB = Mockery::mock(KasushouDriver::class);
        $driverB->shouldNotReceive('placeOrder');
        $this->bindDrivers([
            $supplierA->id => $this->callbackDriver($order->order_no . '-1', UnifiedResult::DefiniteFailure),
            $supplierB->id => $driverB,
        ]);
        $this->expectNotify(0);

        $this->assertSame('ok', $this->handleCallback($supplierA));

        $order->refresh();
        $this->assertSame('abnormal', $order->status);
        $this->assertSame($supplierA->id, $order->supplier_id);
        $this->assertSame(1, OrderAttempt::where('order_id', $order->id)->count());
        $this->assertSame('failed', OrderAttempt::where('order_id', $order->id)->value('result'), '结果留给人工核实');

        $merchant->refresh();
        $this->assertSame('10.00', $merchant->frozen_balance);
    }

    public function testSuccessCallbackOnAbnormalOrderDoesNotDeduct()
    {
        [$merchant, $product, $supplierA] = $this->setUpSuppliers(1);
        $order = $this->createAcceptedOrder($merchant, $product, $supplierA, hoursAgo: 25);
        $this->service()->markOverdue();

        $this->bindDrivers([$supplierA->id => $this->callbackDriver($order->order_no . '-1', UnifiedResult::Success)]);
        $this->expectNotify(0);

        $this->handleCallback($supplierA);

        $this->assertSame('abnormal', $order->refresh()->status);
        $this->assertSame('success', OrderAttempt::where('order_id', $order->id)->value('result'));
        $this->assertSame(0, MerchantBalanceLog::where('order_id', $order->id)->where('type', 'deduct')->count());
    }

    public function testAbnormalOrderIsNoLongerPolled()
    {
        [$merchant, $product, $supplierA] = $this->setUpSuppliers(1);
        $order = $this->createAcceptedOrder($merchant, $product, $supplierA, hoursAgo: 25);
        $this->service()->markOverdue();

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldNotReceive('queryOrder');
        $this->bindDrivers([$supplierA->id => $driver]);
        $this->expectNotify(0);

        $this->getContainer()->get(SupplierResultPollingService::class)->pollDue();

        $this->assertSame('abnormal', $order->refresh()->status);
    }

    public function testMerchantStillSeesAbnormalOrderAsProcessing()
    {
        [$merchant, $product, $supplierA] = $this->setUpSuppliers(1);
        $order = $this->createAcceptedOrder($merchant, $product, $supplierA, hoursAgo: 25);
        $this->service()->markOverdue();

        $result = $this->getContainer()->get(OrderQueryService::class)->find($merchant, $order->order_no, null);

        $this->assertSame('processing', $result['status']);
        $this->assertSame('abnormal', $order->refresh()->status);
    }

    private function service(): AbnormalOrderService
    {
        return $this->getContainer()->get(AbnormalOrderService::class);
    }

    /**
     * @param array<string, mixed> $values 没列出的 key 返回调用方给的默认值
     */
    private function bindSettings(array $values): void
    {
        $dao = Mockery::mock(SystemSettingDao::class);
        $dao->shouldReceive('getValue')->andReturnUsing(static fn (string $key, mixed $default = null) => $values[$key] ?? $default);
        $this->instance(SystemSettingDao::class, $dao);
    }

    private function callbackDriver(string $externalOrderNo, UnifiedResult $result): KasushouDriver
    {
        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('parseCallback')->once()->andReturn(new DriverResult(
            result: $result,
            supplierOrderNo: 'SUP-ABN',
            rawRequest: ['external_orderno' => $externalOrderNo],
        ));

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
            $mapping = SupplierProduct::create([
                'product_id' => $product->id,
                'supplier_id' => $supplier->id,
                'supplier_product_code' => 'GOODS-' . $i,
                'cost_price' => '8.00',
                'priority' => $i,
                'status' => 'active',
            ]);
            $this->supplierProductIds[] = $mapping->id;
            $suppliers[] = $supplier;
        }

        return [$merchant, $product, ...$suppliers];
    }

    /**
     * 已受理、第一家处理中的订单，余额已冻结（商户初始 100，每笔冻结 10）。
     */
    private function createAcceptedOrder(Merchant $merchant, Product $product, Supplier $supplier, int $hoursAgo): Order
    {
        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => 'processing',
            'sale_price' => '10.00',
            'cost_price' => '8.00',
            'supplier_id' => $supplier->id,
            'frozen_amount' => '10.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);
        $this->orderIds[] = $order->id;
        Order::where('id', $order->id)->update(['created_at' => date('Y-m-d H:i:s', time() - $hoursAgo * 3600)]);

        OrderRecharge::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'recharge_account' => '13800000300',
            'rebate_amount' => '0.00',
        ]);
        OrderAttempt::create([
            'order_id' => $order->id,
            'supplier_id' => $supplier->id,
            'attempt_no' => 1,
            'result' => 'unknown',
        ]);
        OrderAttempt::where('order_id', $order->id)->update(['updated_at' => '2000-01-01 00:00:00']);

        Merchant::where('id', $merchant->id)->update([
            'available_balance' => bcsub((string) $merchant->refresh()->available_balance, '10.00', 2),
            'frozen_balance' => bcadd((string) $merchant->frozen_balance, '10.00', 2),
        ]);

        return $order->refresh();
    }

    private function createMerchant(): Merchant
    {
        $unique = uniqid('abnormal_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => 'encrypted-secret-placeholder',
            'available_balance' => '100.00',
            'frozen_balance' => '0.00',
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createProduct(): Product
    {
        $product = Product::create([
            'business_line' => 'recharge',
            'name' => uniqid('abnormal_test_product_', true),
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
        $unique = uniqid('abnormal_test_supplier_', true);

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
}
