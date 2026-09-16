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

/**
 * `RebateCalculator::calculateDetailed()` 的返回值：不只是最终金额，还带上"用的哪个
 * 比例、这个比例是从哪一层取的"——requirements.md 5.4 生成待到账返佣记录时要求
 * 原样记下"返佣基数及来源、比例及来源"，只有最终金额不够用，`RebateCalculator::
 * calculate()`（现有唯一调用方 `App\Service\OpenApi\ProductListService` 只关心
 * 金额）不需要、也不应该被迫改造成返回这个更复杂的形状，所以单独加一个方法、
 * 单独一个只读结果对象，`calculate()` 保持原样不变。
 */
final class RebateCalculationResult
{
    /**
     * @param string $rate 实际使用的返佣比例（4 位小数字符串，如 '0.7500'）；
     *                     没有命中任何比例（等级为 null，或商品维度、业务线维度都
     *                     没设置）时固定是 '0'，此时 `$rateSource` 为 `null`，
     *                     `$amount` 固定是 '0.00'
     * @param null|string $rateSource 这个比例是从哪一层取的：`'product_level'`
     *                                （商品对该等级单独设置的覆盖）或 `'level'`
     *                                （该等级在该业务线的默认比例）；没有命中任何
     *                                比例时为 `null`
     * @param string $amount 最终返佣金额，2 位小数字符串，向下取整到分
     */
    public function __construct(
        public readonly string $rate,
        public readonly ?string $rateSource,
        public readonly string $amount,
    ) {
    }
}
