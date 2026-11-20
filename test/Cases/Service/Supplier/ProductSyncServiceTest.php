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

use App\Exception\InvalidSupplierCallbackSignatureException;
use App\Exception\SupplierNotFoundException;
use App\Model\Supplier;
use App\Model\SupplierGoods;
use App\Model\SupplierProduct;
use App\Model\SupplierProductPriceHistory;
use App\Service\Supplier\ProductSyncService;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\SupplierDriverFactory;
use Hyperf\Testing\TestCase;
use Mockery;
use RuntimeException;

/**
 * App\Service\Supplier\ProductSyncService：applyNotification()（通知触发，验签+
 * 重新查权威值）、applyFullSyncPage()（应用一页已取回的全量同步数据），以及接入用的
 * handleNotification()（按供应商编码找驱动）和 syncSupplier()/syncAllSuppliers()
 * （每日全量校准的翻页）。
 * KasushouDriver 全部用 Mockery 双重（不发真实网络请求，跟
 * test/Cases/Supplier/Kasushou/KasushouDriverTest.php 同样的原则），这里只关心
 * Service 编排逻辑本身：验签结果如何驱动查询、映射行是否存在如何驱动 no-op、
 * 价格变化是否正确触发历史记录。
 *
 * @internal
 * @coversNothing
 */
class ProductSyncServiceTest extends TestCase
{
    private const SUPPLIER_ID = 101;

    private array $productIds = [];

    private array $supplierIds = [];

    /** 本用例在固定的 SUPPLIER_ID 下用过的编码，tearDown 只删这些供应商商品 */
    private array $codes = [];

    protected function tearDown(): void
    {
        SupplierGoods::where('supplier_id', self::SUPPLIER_ID)->whereIn('code', $this->codes)->delete();
        SupplierGoods::whereIn('supplier_id', $this->supplierIds)->delete();
        $this->codes = [];

        foreach ($this->productIds as $id) {
            SupplierProductPriceHistory::where('supplier_product_id', $id)->delete();
            SupplierProduct::destroy($id);
        }
        $this->productIds = [];

        foreach ($this->supplierIds as $id) {
            Supplier::destroy($id);
        }
        $this->supplierIds = [];

        parent::tearDown();
    }

    public function testApplyNotificationMappedProductReQueriesDetailAndAppliesWithPriceHistory()
    {
        $code = $this->uniqueCode();
        $product = $this->createProduct($code, costPrice: '10.00');

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('parseProductChangeNotification')->once()->with(['id' => $code, 'time' => '1700000000', 'sign' => 'x'])->andReturn($code);
        $driver->shouldReceive('queryProductDetail')->once()->with($code)->andReturn([
            'supplier_product_code' => $code,
            'cost_price' => '15.00',
            'status' => 'active',
            'stock' => 20,
        ]);

        $service = $this->getContainer()->get(ProductSyncService::class);
        $service->applyNotification(self::SUPPLIER_ID, $driver, ['id' => $code, 'time' => '1700000000', 'sign' => 'x']);

        $product->refresh();
        $this->assertSame('15.00', $product->cost_price);
        $this->assertSame('active', $product->status);
        $this->assertSame(20, $product->stock);
        $this->assertNotNull($product->synced_at);

        $history = SupplierProductPriceHistory::where('supplier_product_id', $product->id)->get();
        $this->assertCount(1, $history);
        $this->assertSame('10.00', $history->first()->old_price);
        $this->assertSame('15.00', $history->first()->new_price);
        $this->assertSame('sync', $history->first()->source);
    }

    public function testApplyNotificationPriceUnchangedInsertsNoHistoryRow()
    {
        $code = $this->uniqueCode();
        $product = $this->createProduct($code, costPrice: '10.00');

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('parseProductChangeNotification')->once()->andReturn($code);
        $driver->shouldReceive('queryProductDetail')->once()->with($code)->andReturn([
            'supplier_product_code' => $code,
            'cost_price' => '10.00',
            'status' => 'paused',
            'stock' => 3,
        ]);

        $service = $this->getContainer()->get(ProductSyncService::class);
        $service->applyNotification(self::SUPPLIER_ID, $driver, ['id' => $code, 'time' => '1700000000', 'sign' => 'x']);

        $product->refresh();
        $this->assertSame('10.00', $product->cost_price);
        $this->assertSame('paused', $product->status);

        $history = SupplierProductPriceHistory::where('supplier_product_id', $product->id)->get();
        $this->assertCount(0, $history);
    }

    public function testApplyNotificationInvalidSignatureDoesNotQueryDetail()
    {
        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('parseProductChangeNotification')->once()->andReturn(null);
        $driver->shouldNotReceive('queryProductDetail');

        $service = $this->getContainer()->get(ProductSyncService::class);

        // 不应该抛异常，也不应该有任何查询/落库副作用。
        $service->applyNotification(self::SUPPLIER_ID, $driver, ['id' => 'x', 'time' => '1700000000', 'sign' => 'bad']);
        $this->assertTrue(true);
    }

    public function testApplyNotificationUnmappedProductStillQueriesDetailAndFillsGoodsCatalog()
    {
        $code = $this->uniqueCode();

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('parseProductChangeNotification')->once()->andReturn($code);
        // 没有映射也要查详情：供应商商品库收录供应商的全部商品
        $driver->shouldReceive('queryProductDetail')->once()->with($code)->andReturn([
            'supplier_product_code' => $code,
            'cost_price' => '48.00',
            'status' => 'active',
            'stock' => null,
            'name' => '爱奇艺月卡',
            'face_value' => '50.00',
        ]);

        $service = $this->getContainer()->get(ProductSyncService::class);
        $service->applyNotification(self::SUPPLIER_ID, $driver, ['id' => $code, 'time' => '1700000000', 'sign' => 'x']);

        $goods = SupplierGoods::where('supplier_id', self::SUPPLIER_ID)->where('code', $code)->first();
        $this->assertNotNull($goods);
        $this->assertSame('爱奇艺月卡', $goods->name);
        $this->assertSame('50.00', $goods->face_value);
        $this->assertSame('48.00', $goods->cost_price);
        $this->assertSame('active', $goods->status);
        $this->assertNotNull($goods->synced_at);
    }

    public function testApplyNotificationUpdatesEveryMappingOfTheSameSupplierGoods()
    {
        $code = $this->uniqueCode();
        $a = $this->createProduct($code, costPrice: '10.00');
        $b = $this->createProduct($code, costPrice: '10.00');

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('parseProductChangeNotification')->once()->andReturn($code);
        $driver->shouldReceive('queryProductDetail')->once()->andReturn([
            'supplier_product_code' => $code, 'cost_price' => '10.50', 'status' => 'active', 'stock' => null,
        ]);

        $this->service()->applyNotification(self::SUPPLIER_ID, $driver, ['id' => $code, 'time' => '1700000000', 'sign' => 'x']);

        $this->assertSame('10.50', $a->refresh()->cost_price);
        $this->assertSame('10.50', $b->refresh()->cost_price);
    }

    public function testApplyFullSyncPageAppliesMappedEntriesWithPriceHistoryOnlyWhenChanged()
    {
        $codeChanged = $this->uniqueCode();
        $codeUnchanged = $this->uniqueCode();
        $productChanged = $this->createProduct($codeChanged, costPrice: '10.00');
        $productUnchanged = $this->createProduct($codeUnchanged, costPrice: '20.00');

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldNotReceive('queryProductDetail');

        $service = $this->getContainer()->get(ProductSyncService::class);
        $service->applyFullSyncPage(self::SUPPLIER_ID, [
            ['supplier_product_code' => $codeChanged, 'cost_price' => '11.00', 'status' => 'active', 'stock' => 30],
            ['supplier_product_code' => $codeUnchanged, 'cost_price' => '20.00', 'status' => 'active', 'stock' => 40],
        ]);

        $productChanged->refresh();
        $productUnchanged->refresh();
        $this->assertSame('11.00', $productChanged->cost_price);
        $this->assertSame('20.00', $productUnchanged->cost_price);

        $this->assertCount(1, SupplierProductPriceHistory::where('supplier_product_id', $productChanged->id)->get());
        $this->assertCount(0, SupplierProductPriceHistory::where('supplier_product_id', $productUnchanged->id)->get());
    }

    public function testApplyFullSyncPageUpsertsGoodsCatalogKeepingKnownNameWhenMissing()
    {
        $code = $this->uniqueCode();

        $service = $this->getContainer()->get(ProductSyncService::class);
        $service->applyFullSyncPage(self::SUPPLIER_ID, [
            ['supplier_product_code' => $code, 'cost_price' => '1.00', 'status' => 'active', 'stock' => 1, 'name' => '移动 100 元快充', 'face_value' => '100.00'],
        ]);
        // 第二次没带名称、面值：保留上次的，价格状态库存照常更新
        $service->applyFullSyncPage(self::SUPPLIER_ID, [
            ['supplier_product_code' => $code, 'cost_price' => '1.20', 'status' => 'paused', 'stock' => 0, 'name' => null, 'face_value' => null],
        ]);

        $rows = SupplierGoods::where('supplier_id', self::SUPPLIER_ID)->where('code', $code)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('移动 100 元快充', $rows[0]->name);
        $this->assertSame('100.00', $rows[0]->face_value);
        $this->assertSame('1.20', $rows[0]->cost_price);
        $this->assertSame('paused', $rows[0]->status);
        $this->assertSame(0, $rows[0]->stock);
    }

    public function testHandleNotificationResolvesSupplierByCode()
    {
        $supplier = $this->createSupplier();
        $code = $this->uniqueCode();
        $product = $this->createProduct($code, costPrice: '10.00', supplierId: $supplier->id);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('parseProductChangeNotification')->once()->andReturn($code);
        $driver->shouldReceive('queryProductDetail')->once()->andReturn([
            'supplier_product_code' => $code, 'cost_price' => '9.50', 'status' => 'paused', 'stock' => 0,
        ]);
        $this->bindDrivers([$supplier->id => $driver]);

        $this->service()->handleNotification($supplier->code, ['id' => $code, 'time' => '1700000000', 'sign' => 'x']);

        $product->refresh();
        $this->assertSame('9.50', $product->cost_price);
        $this->assertSame('paused', $product->status);
        $this->assertSame(0, $product->stock);
    }

    public function testHandleNotificationRejectsUnknownSupplierAndBadSignature()
    {
        $supplier = $this->createSupplier();
        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('parseProductChangeNotification')->once()->andReturnNull();
        $driver->shouldNotReceive('queryProductDetail');
        $this->bindDrivers([$supplier->id => $driver]);

        try {
            $this->service()->handleNotification('no-such-supplier-' . uniqid(), []);
            $this->fail('expected SupplierNotFoundException');
        } catch (SupplierNotFoundException) {
        }

        $this->expectException(InvalidSupplierCallbackSignatureException::class);
        $this->service()->handleNotification($supplier->code, ['id' => 'x', 'time' => '1', 'sign' => 'bad']);
    }

    public function testSyncSupplierWalksPagesUntilShortPage()
    {
        $supplier = $this->createSupplier();
        $first = $this->createProduct($this->uniqueCode(), costPrice: '10.00', supplierId: $supplier->id);
        $last = $this->createProduct($this->uniqueCode(), costPrice: '10.00', supplierId: $supplier->id);

        $page1 = $this->page(ProductSyncService::FULL_SYNC_PAGE_SIZE, $first->supplier_product_code, '11.00');
        $page2 = [$this->entry($last->supplier_product_code, '12.00')];

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('syncAllProducts')->once()->with(1, ProductSyncService::FULL_SYNC_PAGE_SIZE)->andReturn($page1);
        $driver->shouldReceive('syncAllProducts')->once()->with(2, ProductSyncService::FULL_SYNC_PAGE_SIZE)->andReturn($page2);
        $this->bindDrivers([$supplier->id => $driver]);

        $this->assertSame(ProductSyncService::FULL_SYNC_PAGE_SIZE + 1, $this->service()->syncSupplier($supplier));

        $this->assertSame('11.00', $first->refresh()->cost_price);
        $this->assertSame('12.00', $last->refresh()->cost_price);
        $this->assertNotNull($last->synced_at);
    }

    public function testSyncSupplierStopsWhenApiIgnoresPageNumber()
    {
        $supplier = $this->createSupplier();
        $samePage = $this->page(ProductSyncService::FULL_SYNC_PAGE_SIZE, 'GOODS-X', '1.00');

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('syncAllProducts')->twice()->andReturn($samePage);
        $this->bindDrivers([$supplier->id => $driver]);

        $this->assertSame(ProductSyncService::FULL_SYNC_PAGE_SIZE, $this->service()->syncSupplier($supplier));
    }

    public function testSyncSupplierStopsOnEmptyPage()
    {
        $supplier = $this->createSupplier();

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('syncAllProducts')->once()->andReturn([]);
        $this->bindDrivers([$supplier->id => $driver]);

        $this->assertSame(0, $this->service()->syncSupplier($supplier));
    }

    public function testSyncAllSuppliersContinuesPastFailingSupplier()
    {
        $broken = $this->createSupplier();
        $healthy = $this->createSupplier();
        $disabled = $this->createSupplier('disabled');
        $product = $this->createProduct($this->uniqueCode(), costPrice: '10.00', supplierId: $healthy->id);

        $brokenDriver = Mockery::mock(KasushouDriver::class);
        $brokenDriver->shouldReceive('syncAllProducts')->andThrow(new RuntimeException('http 500'));
        $healthyDriver = Mockery::mock(KasushouDriver::class);
        $healthyDriver->shouldReceive('syncAllProducts')->once()->andReturn([$this->entry($product->supplier_product_code, '13.00')]);
        $disabledDriver = Mockery::mock(KasushouDriver::class);
        $disabledDriver->shouldNotReceive('syncAllProducts');
        $this->bindDrivers([$broken->id => $brokenDriver, $healthy->id => $healthyDriver, $disabled->id => $disabledDriver]);

        $this->service()->syncAllSuppliers();

        $this->assertSame('13.00', $product->refresh()->cost_price);
    }

    private function service(): ProductSyncService
    {
        return $this->getContainer()->get(ProductSyncService::class);
    }

    /**
     * 测试库是共享的，不是本测试建的供应商一律让驱动构造失败（只记日志）。
     *
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

    /**
     * 一整页：第一条是给定的商品，其余用不存在映射的编码填满。
     *
     * @return list<array{supplier_product_code: string, cost_price: string, status: string, stock: null|int}>
     */
    private function page(int $size, string $firstCode, string $firstCost): array
    {
        $entries = [$this->entry($firstCode, $firstCost)];
        for ($i = 1; $i < $size; ++$i) {
            $entries[] = $this->entry('UNMAPPED-' . $i, '1.00');
        }

        return $entries;
    }

    /**
     * @return array{supplier_product_code: string, cost_price: string, status: string, stock: null|int}
     */
    private function entry(string $code, string $cost): array
    {
        return ['supplier_product_code' => $code, 'cost_price' => $cost, 'status' => 'active', 'stock' => null];
    }

    private function createSupplier(string $status = 'active'): Supplier
    {
        $unique = uniqid('product_sync_test_supplier_', true);

        $supplier = Supplier::create([
            'name' => $unique,
            'code' => substr(md5($unique), 0, 24),
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => 'unused-in-test-driver-factory-is-overridden',
            'status' => $status,
        ]);

        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }

    private function uniqueCode(): string
    {
        return $this->codes[] = 'GOODS-' . uniqid('', true);
    }

    private function createProduct(string $code, string $costPrice, int $supplierId = self::SUPPLIER_ID): SupplierProduct
    {
        $product = SupplierProduct::create([
            'product_id' => random_int(100000, 999999),
            'supplier_id' => $supplierId,
            'supplier_product_code' => $code,
            'cost_price' => $costPrice,
            'priority' => 1,
            'status' => 'active',
            'stock' => 100,
        ]);

        $this->productIds[] = $product->id;

        return $product;
    }
}
