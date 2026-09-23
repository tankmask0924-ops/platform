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

namespace HyperfTest\Cases\Service\Supplier;

use App\Job\RefreshSupplierBalanceJob;
use App\Model\Alert;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantBusinessSubscription;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderRecharge;
use App\Model\Product;
use App\Model\Supplier;
use App\Model\SupplierProduct;
use App\Service\Order\RechargeOrderPlacementService;
use App\Service\Supplier\SupplierBalanceService;
use App\Supplier\DriverResult;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use App\Supplier\Yunyang\YunyangDriver;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\AsyncQueue\JobInterface;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Testing\TestCase;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * App\Service\Supplier\SupplierBalanceService 余额监控（requirements.md 6.7），以及
 * 下单时供应商报告预存款不足后的告警 + 立即刷新。驱动全部 mock。
 *
 * 测试库是共享的，可能有别的启用中的供应商：驱动工厂遇到不是本测试建的供应商一律抛
 * 异常（余额服务只记日志，不改它们的余额）。
 *
 * @internal
 * @coversNothing
 */
class SupplierBalanceServiceTest extends TestCase
{
    private array $supplierIds = [];

    private array $merchantIds = [];

    private array $productIds = [];

    private array $supplierProductIds = [];

    /**
     * @var list<JobInterface>
     */
    private array $pushedJobs = [];

    protected function tearDown(): void
    {
        foreach (Order::whereIn('merchant_id', $this->merchantIds ?: [0])->pluck('id') as $orderId) {
            OrderAttempt::where('order_id', $orderId)->delete();
            OrderRecharge::where('order_id', $orderId)->delete();
            MerchantBalanceLog::where('order_id', $orderId)->delete();
            Order::destroy($orderId);
        }
        foreach ($this->supplierProductIds as $id) {
            SupplierProduct::destroy($id);
        }
        foreach ($this->productIds as $id) {
            Product::destroy($id);
        }
        foreach ($this->merchantIds as $id) {
            MerchantBusinessSubscription::where('merchant_id', $id)->delete();
            Merchant::destroy($id);
        }
        // 低于预警线 / 预存款不足现在会写 alerts（App\Service\Alert\AlertService）
        Alert::where('related_type', 'supplier')->whereIn('related_id', $this->supplierIds ?: [0])->delete();
        foreach ($this->supplierIds as $id) {
            Supplier::destroy($id);
        }
        $this->supplierIds = $this->merchantIds = $this->productIds = $this->supplierProductIds = $this->pushedJobs = [];

        parent::tearDown();
    }

    public function testRefreshStoresNormalizedBalanceAndSyncTime()
    {
        $supplier = $this->createSupplier(balance: null);
        $this->bindBalances([$supplier->id => '1234.567']);

        $this->assertTrue($this->service()->refresh($supplier));

        $supplier->refresh();
        $this->assertSame('1234.56', $supplier->balance, '多出的小数位截掉，不向上取整');
        $this->assertNotNull($supplier->balance_synced_at);
    }

    /**
     * 快递供应商（云洋）按它自己的驱动查余额，不能走卡速售的 build()（会直接抛错、永远查不到）。
     */
    public function testYunyangSupplierIsQueriedWithItsOwnDriver()
    {
        $supplier = $this->createSupplier(balance: null);
        $supplier->fill(['business_line' => 'express', 'driver' => 'yunyang'])->save();

        $driver = Mockery::mock(YunyangDriver::class);
        $driver->shouldReceive('queryBalance')->once()->andReturn('88.80');
        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldNotReceive('build');
        $factory->shouldReceive('buildYunyang')->andReturn($driver);
        $this->instance(SupplierDriverFactory::class, $factory);

        $this->assertTrue($this->service()->refresh($supplier));
        $this->assertSame('88.80', $supplier->refresh()->balance);
    }

    public function testFailedQueryKeepsPreviousBalance()
    {
        $broken = $this->createSupplier(balance: '50.00');
        $garbled = $this->createSupplier(balance: '60.00');
        $this->bindBalances([$broken->id => new RuntimeException('timeout'), $garbled->id => '1.2e3']);

        $this->assertFalse($this->service()->refresh($broken));
        $this->assertFalse($this->service()->refresh($garbled));

        $this->assertSame('50.00', $broken->refresh()->balance);
        $this->assertNull($broken->balance_synced_at);
        $this->assertSame('60.00', $garbled->refresh()->balance);
    }

    public function testBalanceBelowThresholdIsWarned()
    {
        $low = $this->createSupplier(balance: null, threshold: '100.00');
        $fine = $this->createSupplier(balance: null, threshold: '100.00');
        $this->bindBalances([$low->id => '99.99', $fine->id => '100.00']);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('supplier balance below warning threshold', Mockery::on(static fn (array $ctx) => $ctx['supplier_id'] === $low->id && $ctx['balance'] === '99.99'));
        // AlertService 也走同一个 LoggerFactory 记一条 'alert raised'，放行掉；
        // 声明顺序在具体期望之后，Mockery 才会先匹配上面那条
        $logger->shouldReceive('warning')->withAnyArgs()->zeroOrMoreTimes();
        $loggerFactory = Mockery::mock(LoggerFactory::class);
        $loggerFactory->shouldReceive('get')->andReturn($logger);
        $this->instance(LoggerFactory::class, $loggerFactory);

        $this->assertTrue($this->service()->refresh($low));
        $this->assertTrue($this->service()->refresh($fine));

        $this->assertSame('99.99', $low->refresh()->balance, '低于阈值也照常写入');
    }

    public function testRefreshAllOnlyQueriesActiveSuppliers()
    {
        $active = $this->createSupplier(balance: null);
        $disabled = $this->createSupplier(balance: '5.00', status: 'disabled');
        $this->bindBalances([$active->id => '88.00', $disabled->id => '999.00']);

        $this->service()->refreshAll();

        $this->assertSame('88.00', $active->refresh()->balance);
        $this->assertSame('5.00', $disabled->refresh()->balance);
    }

    public function testReportInsufficientQueuesRefreshJobThatUpdatesBalance()
    {
        $supplier = $this->createSupplier(balance: '500.00');
        $this->bindBalances([$supplier->id => '0.50']);
        $this->captureQueue();

        $this->service()->reportInsufficient($supplier->id, 'test');

        $this->assertCount(1, $this->pushedJobs);
        $job = $this->pushedJobs[0];
        $this->assertInstanceOf(RefreshSupplierBalanceJob::class, $job);
        $this->assertSame($supplier->id, $job->supplierId);

        $job->handle();

        $this->assertSame('0.50', $supplier->refresh()->balance);
    }

    /**
     * 下单时第一家报预存款不足：换下一家，同时排队刷新第一家的余额；刷新后
     * 第一家的余额低于成本价，下一笔订单不会再分给它。
     */
    public function testInsufficientBalanceDuringPlacementTriggersRefreshAndStopsRoutingToSupplier()
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct();
        $poor = $this->createSupplier(balance: '500.00');
        $rich = $this->createSupplier(balance: null);
        $this->createMapping($product, $poor, 1);
        $this->createMapping($product, $rich, 2);

        $poorDriver = Mockery::mock(KasushouDriver::class);
        $poorDriver->shouldReceive('placeOrder')->once()->andReturn(new DriverResult(
            result: UnifiedResult::DefiniteFailure,
            failReason: 'kasushou: order status -1',
            supplierBalanceInsufficient: true,
        ));
        $poorDriver->shouldReceive('queryBalance')->once()->andReturn('3.00');
        $richDriver = Mockery::mock(KasushouDriver::class);
        $richDriver->shouldReceive('placeOrder')->twice()->andReturn(new DriverResult(result: UnifiedResult::Processing));
        $this->bindDrivers([$poor->id => $poorDriver, $rich->id => $richDriver]);
        $this->captureQueue();

        $service = $this->getContainer()->get(RechargeOrderPlacementService::class);
        $first = $service->place($merchant, 'MO-' . uniqid('', true), $product->id, '13800000400', 'https://merchant.example.com/notify');
        $this->assertSame('processing', $first['status']);

        $refreshJobs = array_values(array_filter($this->pushedJobs, static fn ($job) => $job instanceof RefreshSupplierBalanceJob));
        $this->assertCount(1, $refreshJobs);
        $this->assertSame($poor->id, $refreshJobs[0]->supplierId);

        $refreshJobs[0]->handle();
        $this->assertSame('3.00', $poor->refresh()->balance);

        // 第二笔直接走第二家（poorDriver 的 placeOrder 只允许一次）
        $second = $service->place($merchant, 'MO-' . uniqid('', true), $product->id, '13800000401', 'https://merchant.example.com/notify');
        $this->assertSame('processing', $second['status']);
    }

    private function service(): SupplierBalanceService
    {
        return $this->getContainer()->get(SupplierBalanceService::class);
    }

    /**
     * @param array<int, RuntimeException|string> $balances 按供应商 id 给查询结果或要抛的异常
     */
    private function bindBalances(array $balances): void
    {
        $drivers = [];
        foreach ($balances as $supplierId => $balance) {
            $driver = Mockery::mock(KasushouDriver::class);
            $expectation = $driver->shouldReceive('queryBalance');
            $balance instanceof RuntimeException ? $expectation->andThrow($balance) : $expectation->andReturn($balance);
            $drivers[$supplierId] = $driver;
        }
        $this->bindDrivers($drivers);
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

    private function captureQueue(): void
    {
        $queue = Mockery::mock(DriverInterface::class);
        $queue->shouldReceive('push')->andReturnUsing(function (JobInterface $job) {
            $this->pushedJobs[] = $job;
            return true;
        });
        $factory = Mockery::mock(DriverFactory::class);
        $factory->shouldReceive('get')->with('default')->andReturn($queue);
        $this->instance(DriverFactory::class, $factory);
    }

    private function createSupplier(?string $balance, ?string $threshold = null, string $status = 'active'): Supplier
    {
        $unique = uniqid('balance_test_supplier_', true);

        $supplier = Supplier::create([
            'name' => $unique,
            'code' => substr(md5($unique), 0, 24),
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => 'unused-in-test-driver-factory-is-overridden',
            'status' => $status,
            'balance' => $balance,
            'balance_warning_threshold' => $threshold,
        ]);

        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }

    private function createMerchant(): Merchant
    {
        $unique = uniqid('balance_test_', true);

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
        // 下单和商品查询要求已开通业务线（requirements.md 4.2）
        foreach (['recharge', 'card'] as $line) {
            MerchantBusinessSubscription::create(['merchant_id' => $merchant->id, 'business_line' => $line, 'status' => 'approved', 'applied_at' => date('Y-m-d H:i:s')]);
        }

        return $merchant;
    }

    private function createProduct(): Product
    {
        $product = Product::create([
            'business_line' => 'recharge',
            'name' => uniqid('balance_test_product_', true),
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

    private function createMapping(Product $product, Supplier $supplier, int $priority): void
    {
        $mapping = SupplierProduct::create([
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_product_code' => 'GOODS-' . $priority,
            'cost_price' => '8.00',
            'priority' => $priority,
            'status' => 'active',
        ]);

        $this->supplierProductIds[] = $mapping->id;
    }
}
