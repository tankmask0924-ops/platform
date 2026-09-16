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

namespace App\Service\Product;

use App\Dao\MerchantLevelBusinessRateDao;
use App\Dao\ProductLevelRebateDao;
use App\Model\Product;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 话费、卡券的商户返佣计算（requirements.md 5.3），只覆盖有本地 App\Model\Product 行的
 * 两条业务线——电影票、快递用供应商返回的返佣，返佣基数和取法都不一样（供应商返佣，
 * 不是 products.rebate_amount，也没有单独设置覆盖这一层），不在这个计算器的范围内。
 *
 * 公式：商户返佣 = 返佣基数 × 等级比例，向下取整到分。
 *
 * 等级比例取法（按顺序，命中即用，不再往下找）：
 *   1. 该商品对这个等级单独设置了比例（product_level_rebates 里 (product_id, level_id) 一行）
 *   2. 该等级在该业务线的默认比例（merchant_level_business_rates 里 (level_id, business_line) 一行）
 *   3. 都没有 → 视为 0%，不返佣
 *
 * 金额计算全程用 bcmath 字符串运算，不用 float：products.rebate_amount 最多 2 位小数、
 * rebate_rate 最多 4 位小数，两者精确相乘最多 6 位小数，PHP float 乘法对这类十进制小数
 * 不保证精确（如 0.1 + 0.2 !== 0.3 的同类问题），bcmath 是任意精度十进制运算，没有这个问题。
 * "向下取整到分"通过 bcmul(..., ..., 2) 实现：bcmath 系列函数对 scale 参数是**截断**，不是
 * 四舍五入（PHP 官方文档：结果按 scale 截断小数位数），两个乘数在本场景下恒为非负数，
 * 截断等价于向下取整——例 1 的银牌档位 0.50 × 0.75 = 0.375 就是靠这个截断成 0.37，
 * 而不是四舍五入成 0.38，这正是 requirements.md 5.3 例 1 专门用来验证的那条边界。
 */
class RebateCalculator extends AbstractService
{
    private const SCALE = 2;

    #[Inject]
    protected ProductLevelRebateDao $productLevelRebateDao;

    #[Inject]
    protected MerchantLevelBusinessRateDao $merchantLevelBusinessRateDao;

    /**
     * @param null|int $merchantLevelId 商户当前等级；商户还没被分配等级时为 null
     *
     * @return string 返佣金额，2 位小数的字符串（如 '0.37'）；商户没有等级，或者
     *                该等级在商品维度和业务线维度都没有设置比例时，返回 '0.00'
     *                （用 '0.00' 而不是 null：调用方——目前是开放 API 商品列表——
     *                要把这个值直接原样放进响应体当金额字段，'0.00' 跟这条业务线
     *                其它金额字段的形状一致，不需要再判空分支）
     */
    public function calculate(Product $product, ?int $merchantLevelId): string
    {
        return $this->calculateDetailed($product, $merchantLevelId)->amount;
    }

    /**
     * 跟 `calculate()` 共用同一套 3 步比例取法（见类注释），多返回"用的哪个比例、
     * 从哪一层取的"，供 requirements.md 5.4 生成待到账返佣记录时快照
     * "返佣基数及来源、比例及来源"用（`App\Service\Order\OrderResultApplier` 是
     * 目前唯一调用方）。`calculate()` 改成这个方法的薄包装，两者共用同一份
     * precedence 逻辑（`resolveRate()`），不会出现两处实现分叉、后续改需求漏改
     * 一边的风险。
     *
     * @param null|int $merchantLevelId 商户当前等级；商户还没被分配等级时为 null
     */
    public function calculateDetailed(Product $product, ?int $merchantLevelId): RebateCalculationResult
    {
        if ($merchantLevelId === null) {
            return new RebateCalculationResult('0', null, '0.00');
        }

        $resolved = $this->resolveRate($product, $merchantLevelId);
        if ($resolved === null) {
            return new RebateCalculationResult('0', null, '0.00');
        }

        [$rate, $rateSource] = $resolved;

        return new RebateCalculationResult($rate, $rateSource, bcmul($product->rebate_amount, $rate, self::SCALE));
    }

    /**
     * @return null|array{0: string, 1: 'level'|'product_level'} [比例, 比例来源]，
     *                                                           都没命中时返回 null
     */
    private function resolveRate(Product $product, int $merchantLevelId): ?array
    {
        $override = $this->productLevelRebateDao->findForProductAndLevel($product->id, $merchantLevelId);
        if ($override !== null) {
            return [$override->rebate_rate, 'product_level'];
        }

        $levelRate = $this->merchantLevelBusinessRateDao->findForLevelAndBusinessLine(
            $merchantLevelId,
            $product->business_line
        );
        if ($levelRate !== null) {
            return [$levelRate->rebate_rate, 'level'];
        }

        return null;
    }
}
