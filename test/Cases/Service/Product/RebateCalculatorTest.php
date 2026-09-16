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

namespace HyperfTest\Cases\Service\Product;

use App\Model\MerchantLevelBusinessRate;
use App\Model\Product;
use App\Model\ProductLevelRebate;
use App\Service\Product\RebateCalculator;
use Hyperf\Testing\TestCase;

/**
 * requirements.md 5.3 的返佣公式和例 1（话费，售价 99.20、返佣金额 0.50）作为精确测试向量：
 * 普通 60% → 0.30，银牌 75% → 0.375 向下取整成 0.37（不是四舍五入的 0.38，这条专门验证
 * 截断而不是四舍五入），金牌等级比例 100% 但商品对金牌单独设了 90% 覆盖 → 0.45。
 *
 * @internal
 * @coversNothing
 */
class RebateCalculatorTest extends TestCase
{
    private array $productIds = [];

    private array $productLevelRebateIds = [];

    private array $merchantLevelBusinessRateIds = [];

    protected function tearDown(): void
    {
        foreach ($this->productLevelRebateIds as $id) {
            ProductLevelRebate::destroy($id);
        }
        $this->productLevelRebateIds = [];

        foreach ($this->merchantLevelBusinessRateIds as $id) {
            MerchantLevelBusinessRate::destroy($id);
        }
        $this->merchantLevelBusinessRateIds = [];

        foreach ($this->productIds as $id) {
            Product::destroy($id);
        }
        $this->productIds = [];

        parent::tearDown();
    }

    public function testSpecExample1OrdinaryLevelUsesLevelBusinessRate()
    {
        [$product, $ordinaryLevelId] = $this->buildSpecExampleFixture();

        $this->assertSame('0.30', $this->calculator()->calculate($product, $ordinaryLevelId));
    }

    /**
     * 银牌档位：0.50 × 0.75 = 0.375，requirements.md 5.3 例 1 明确写了要向下取整到 0.37，
     * 而不是四舍五入到 0.38——这条断言就是专门用来抓「用了 round() 而不是向下取整」这种
     * 错误实现的。
     */
    public function testSpecExample1SilverLevelFloorsInsteadOfRounding()
    {
        [$product, , $silverLevelId] = $this->buildSpecExampleFixture();

        $this->assertSame('0.37', $this->calculator()->calculate($product, $silverLevelId));
    }

    public function testSpecExample1GoldLevelPrefersProductOverrideOverHigherLevelRate()
    {
        [$product, , , $goldLevelId] = $this->buildSpecExampleFixture();

        // 金牌的业务线比例是 100%，但商品对金牌单独设置了 90% 覆盖，取值应该是 0.50 × 0.90 = 0.45，
        // 不是 0.50 × 1.00 = 0.50。
        $this->assertSame('0.45', $this->calculator()->calculate($product, $goldLevelId));
    }

    public function testNoMerchantLevelReturnsZero()
    {
        $product = $this->createProduct('0.50');

        $this->assertSame('0.00', $this->calculator()->calculate($product, null));
    }

    public function testLevelWithNeitherOverrideNorBusinessRateReturnsZero()
    {
        $product = $this->createProduct('0.50');
        $levelWithNoRateAtAll = random_int(500000, 599999);

        $this->assertSame('0.00', $this->calculator()->calculate($product, $levelWithNoRateAtAll));
    }

    /**
     * 覆盖比例比等级默认比例更低时，precedence 仍然优先用覆盖值，证明「覆盖优先」不是
     * 只在覆盖值更高时才生效（不是取 max，是无条件优先商品维度的设置）。
     */
    public function testProductOverrideWinsEvenWhenLowerThanLevelBusinessRate()
    {
        $product = $this->createProduct('1.00');
        $levelId = random_int(600000, 699999);

        $this->createLevelBusinessRate($levelId, '0.8000');
        $this->createProductOverride($product->id, $levelId, '0.5000');

        $this->assertSame('0.50', $this->calculator()->calculate($product, $levelId));
    }

    /**
     * `calculateDetailed()`（requirements.md 5.4 生成待到账返佣记录用，需要连比例
     * 及来源一起快照）复用同一个 spec 例 1 fixture：普通/银牌走等级默认比例
     * （来源 'level'），金牌命中商品单独覆盖（来源 'product_level'）。金额断言
     * 跟 `calculate()` 完全一致，证明重构没有改变既有行为。
     */
    public function testCalculateDetailedOrdinaryAndSilverLevelsUseLevelSource()
    {
        [$product, $ordinaryLevelId, $silverLevelId] = $this->buildSpecExampleFixture();

        $ordinary = $this->calculator()->calculateDetailed($product, $ordinaryLevelId);
        $this->assertSame('0.6000', $ordinary->rate);
        $this->assertSame('level', $ordinary->rateSource);
        $this->assertSame('0.30', $ordinary->amount);

        $silver = $this->calculator()->calculateDetailed($product, $silverLevelId);
        $this->assertSame('0.7500', $silver->rate);
        $this->assertSame('level', $silver->rateSource);
        $this->assertSame('0.37', $silver->amount);
    }

    public function testCalculateDetailedGoldLevelUsesProductLevelSource()
    {
        [$product, , , $goldLevelId] = $this->buildSpecExampleFixture();

        $gold = $this->calculator()->calculateDetailed($product, $goldLevelId);
        $this->assertSame('0.9000', $gold->rate);
        $this->assertSame('product_level', $gold->rateSource);
        $this->assertSame('0.45', $gold->amount);
    }

    /**
     * 没有等级、或等级在商品维度和业务线维度都没设置比例时，`calculateDetailed()`
     * 的 `rateSource` 必须是 `null`（不是空字符串或某个占位值），`amount` 固定
     * `'0.00'`——`App\Service\Order\OrderResultApplier` 拿这个结果去判断"要不要
     * 生成返佣记录"，靠的就是 `amount` 是否 > 0，`rateSource` 为 null 是"这份
     * 结果不对应任何真实比例"的显式信号。
     */
    public function testCalculateDetailedZeroRateCaseHasNullSourceAndZeroAmount()
    {
        $product = $this->createProduct('0.50');

        $noLevel = $this->calculator()->calculateDetailed($product, null);
        $this->assertSame('0.00', $noLevel->amount);
        $this->assertNull($noLevel->rateSource);

        $levelWithNoRateAtAll = random_int(500000, 599999);
        $noRate = $this->calculator()->calculateDetailed($product, $levelWithNoRateAtAll);
        $this->assertSame('0.00', $noRate->amount);
        $this->assertNull($noRate->rateSource);
    }

    /**
     * @return array{0: Product, 1: int, 2: int, 3: int} [商品, 普通等级id, 银牌等级id, 金牌等级id]
     */
    private function buildSpecExampleFixture(): array
    {
        $product = $this->createProduct('0.50');

        $ordinaryLevelId = random_int(700000, 799999);
        $silverLevelId = random_int(800000, 899999);
        $goldLevelId = random_int(900000, 999999);

        $this->createLevelBusinessRate($ordinaryLevelId, '0.6000');
        $this->createLevelBusinessRate($silverLevelId, '0.7500');
        $this->createLevelBusinessRate($goldLevelId, '1.0000');
        $this->createProductOverride($product->id, $goldLevelId, '0.9000');

        return [$product, $ordinaryLevelId, $silverLevelId, $goldLevelId];
    }

    private function createProduct(string $rebateAmount): Product
    {
        $product = Product::create([
            'business_line' => 'recharge',
            'name' => '移动 100 元快充',
            'operator' => 'mobile',
            'face_value' => '100.00',
            'sale_price' => '99.20',
            'rebate_amount' => $rebateAmount,
            'status' => 'on_shelf',
        ]);

        $this->productIds[] = $product->id;

        return $product;
    }

    private function createLevelBusinessRate(int $levelId, string $rate): MerchantLevelBusinessRate
    {
        $row = MerchantLevelBusinessRate::create([
            'level_id' => $levelId,
            'business_line' => 'recharge',
            'rebate_rate' => $rate,
        ]);

        $this->merchantLevelBusinessRateIds[] = $row->id;

        return $row;
    }

    private function createProductOverride(int $productId, int $levelId, string $rate): ProductLevelRebate
    {
        $row = ProductLevelRebate::create([
            'product_id' => $productId,
            'level_id' => $levelId,
            'rebate_rate' => $rate,
        ]);

        $this->productLevelRebateIds[] = $row->id;

        return $row;
    }

    private function calculator(): RebateCalculator
    {
        return $this->getContainer()->get(RebateCalculator::class);
    }
}
