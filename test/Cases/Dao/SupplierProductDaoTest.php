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

namespace HyperfTest\Cases\Dao;

use App\Dao\SupplierProductDao;
use App\Model\SupplierProduct;
use App\Model\SupplierProductPriceHistory;
use Hyperf\Testing\TestCase;

/**
 * 直接单测 App\Dao\SupplierProductDao::applySync() 的"改价必留痕"行为，跟
 * App\Service\Supplier\ProductSyncService 的 Service 层测试互为belt-and-suspenders——
 * 这里不经过 Service，直接验证 Dao 这一个方法本身的事务/落库正确性。
 *
 * @internal
 * @coversNothing
 */
class SupplierProductDaoTest extends TestCase
{
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

    public function testFindBySupplierAndCodeFindsExistingMapping()
    {
        $dao = $this->getContainer()->get(SupplierProductDao::class);
        $code = $this->uniqueCode();
        $product = $this->createProduct(supplierId: 101, code: $code);

        $found = $dao->findBySupplierAndCode(101, $code);

        $this->assertNotNull($found);
        $this->assertSame($product->id, $found->id);
    }

    public function testFindBySupplierAndCodeReturnsNullWhenSupplierIdDoesNotMatch()
    {
        $dao = $this->getContainer()->get(SupplierProductDao::class);
        $code = $this->uniqueCode();
        $this->createProduct(supplierId: 101, code: $code);

        // 同样的 code，但 supplier_id 对不上——不是唯一键，必须两个条件都匹配。
        $this->assertNull($dao->findBySupplierAndCode(202, $code));
    }

    public function testFindBySupplierAndCodeReturnsNullForUnknownCode()
    {
        $dao = $this->getContainer()->get(SupplierProductDao::class);

        $this->assertNull($dao->findBySupplierAndCode(101, 'does-not-exist-' . uniqid('', true)));
    }

    public function testApplySyncInsertsPriceHistoryWhenPriceChanges()
    {
        $dao = $this->getContainer()->get(SupplierProductDao::class);
        $product = $this->createProduct(supplierId: 101, code: $this->uniqueCode(), costPrice: '10.00');

        $updated = $dao->applySync($product, '12.50', 'active', 50);

        $this->assertSame('12.50', $updated->cost_price);
        $this->assertSame('active', $updated->status);
        $this->assertSame(50, $updated->stock);
        $this->assertNotNull($updated->synced_at);

        $history = SupplierProductPriceHistory::where('supplier_product_id', $product->id)->get();
        $this->assertCount(1, $history);
        $this->assertSame('10.00', $history->first()->old_price);
        $this->assertSame('12.50', $history->first()->new_price);
        $this->assertSame('sync', $history->first()->source);
    }

    public function testApplySyncDoesNotInsertPriceHistoryWhenPriceUnchanged()
    {
        $dao = $this->getContainer()->get(SupplierProductDao::class);
        $product = $this->createProduct(supplierId: 101, code: $this->uniqueCode(), costPrice: '10.00');

        $updated = $dao->applySync($product, '10.00', 'paused', 5);

        $this->assertSame('10.00', $updated->cost_price);
        $this->assertSame('paused', $updated->status);
        $this->assertSame(5, $updated->stock);

        $history = SupplierProductPriceHistory::where('supplier_product_id', $product->id)->get();
        $this->assertCount(0, $history);
    }

    public function testApplySyncAllowsNullStockForUnlimited()
    {
        $dao = $this->getContainer()->get(SupplierProductDao::class);
        $product = $this->createProduct(supplierId: 101, code: $this->uniqueCode(), costPrice: '10.00', stock: 5);

        $updated = $dao->applySync($product, '10.00', 'active', null);

        $this->assertNull($updated->stock);
    }

    public function testListForProductOrdersByPriority()
    {
        $dao = $this->getContainer()->get(SupplierProductDao::class);
        $productId = random_int(100000, 999999);

        $low = $this->createProduct(supplierId: 301, code: $this->uniqueCode(), productId: $productId, priority: 5);
        $high = $this->createProduct(supplierId: 302, code: $this->uniqueCode(), productId: $productId, priority: 1);
        $mid = $this->createProduct(supplierId: 303, code: $this->uniqueCode(), productId: $productId, priority: 3);

        $ordered = $dao->listForProduct($productId);

        $this->assertSame([$high->id, $mid->id, $low->id], $ordered->pluck('id')->all());
    }

    public function testUpdateCostPriceManuallyInsertsPriceHistoryWithManualSource()
    {
        $dao = $this->getContainer()->get(SupplierProductDao::class);
        $product = $this->createProduct(supplierId: 101, code: $this->uniqueCode(), costPrice: '10.00');

        $updated = $dao->updateCostPriceManually($product, '15.00');

        $this->assertSame('15.00', $updated->cost_price);

        $history = SupplierProductPriceHistory::where('supplier_product_id', $product->id)->get();
        $this->assertCount(1, $history);
        $this->assertSame('10.00', $history->first()->old_price);
        $this->assertSame('15.00', $history->first()->new_price);
        $this->assertSame('manual', $history->first()->source);
    }

    public function testUpdateCostPriceManuallyDoesNotInsertPriceHistoryWhenPriceUnchanged()
    {
        $dao = $this->getContainer()->get(SupplierProductDao::class);
        $product = $this->createProduct(supplierId: 101, code: $this->uniqueCode(), costPrice: '10.00');

        $updated = $dao->updateCostPriceManually($product, '10.00');

        $this->assertSame('10.00', $updated->cost_price);

        $history = SupplierProductPriceHistory::where('supplier_product_id', $product->id)->get();
        $this->assertCount(0, $history);
    }

    public function testUpdateCostPriceManuallyDoesNotTouchStatusOrStock()
    {
        $dao = $this->getContainer()->get(SupplierProductDao::class);
        $product = $this->createProduct(supplierId: 101, code: $this->uniqueCode(), costPrice: '10.00', stock: 7);
        $product->fill(['status' => 'paused'])->save();

        $updated = $dao->updateCostPriceManually($product, '12.00');

        $this->assertSame('paused', $updated->status);
        $this->assertSame(7, $updated->stock);
    }

    private function uniqueCode(): string
    {
        return 'GOODS-' . uniqid('', true);
    }

    private function createProduct(
        int $supplierId,
        string $code,
        string $costPrice = '10.00',
        ?int $stock = 100,
        ?int $productId = null,
        int $priority = 1
    ): SupplierProduct {
        $product = SupplierProduct::create([
            'product_id' => $productId ?? random_int(100000, 999999),
            'supplier_id' => $supplierId,
            'supplier_product_code' => $code,
            'cost_price' => $costPrice,
            'priority' => $priority,
            'status' => 'active',
            'stock' => $stock,
        ]);

        $this->productIds[] = $product->id;

        return $product;
    }
}
