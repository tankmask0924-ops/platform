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

use App\Model\SupplierProduct;
use App\Model\SupplierProductPriceHistory;
use App\Service\Supplier\ProductSyncService;
use App\Supplier\Kasushou\KasushouDriver;
use Hyperf\Testing\TestCase;
use Mockery;

/**
 * App\Service\Supplier\ProductSyncService 的两个方法：applyNotification()（通知
 * 触发，验签+重新查权威值）和 applyFullSyncPage()（应用一页已取回的全量同步数据）。
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

    protected function tearDown(): void
    {
        foreach ($this->productIds as $id) {
            SupplierProductPriceHistory::where('supplier_product_id', $id)->delete();
            SupplierProduct::destroy($id);
        }
        $this->productIds = [];

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

    public function testApplyNotificationUnmappedProductCodeIsNoOp()
    {
        $code = $this->uniqueCode();

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('parseProductChangeNotification')->once()->andReturn($code);
        // 没有配置映射行，不应该再去查供应商详情。
        $driver->shouldNotReceive('queryProductDetail');

        $service = $this->getContainer()->get(ProductSyncService::class);

        $service->applyNotification(self::SUPPLIER_ID, $driver, ['id' => $code, 'time' => '1700000000', 'sign' => 'x']);
        $this->assertTrue(true);
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

    public function testApplyFullSyncPageUnmappedEntryIsNoOpAndDoesNotThrow()
    {
        $driver = Mockery::mock(KasushouDriver::class);

        $service = $this->getContainer()->get(ProductSyncService::class);
        $service->applyFullSyncPage(self::SUPPLIER_ID, [
            ['supplier_product_code' => 'does-not-exist-' . uniqid('', true), 'cost_price' => '1.00', 'status' => 'active', 'stock' => 1],
        ]);

        $this->assertTrue(true);
    }

    private function uniqueCode(): string
    {
        return 'GOODS-' . uniqid('', true);
    }

    private function createProduct(string $code, string $costPrice): SupplierProduct
    {
        $product = SupplierProduct::create([
            'product_id' => random_int(100000, 999999),
            'supplier_id' => self::SUPPLIER_ID,
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
