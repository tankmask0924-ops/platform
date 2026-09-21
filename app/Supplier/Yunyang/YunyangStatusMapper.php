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

namespace App\Supplier\Yunyang;

use App\Supplier\UnifiedResult;

/**
 * 云洋快递订单状态 → 平台统一结果（yunyang.md 第 2 节的对应表）。
 *
 * 快递跟话费最大的不同：**判断成功与否的不是物流状态，而是扣费标志 `feeOver`**。
 * 话费是"充值到账 = 成功"，快递是"云洋把运费从预存款里扣掉了 = 这一单成立"，
 * 之后的签收、拒收退回都只影响完成时间和费用调整，不会把一单已经扣过费的订单
 * 变回失败。所以这里先看 `feeOver`，再看 `typeCode`。
 *
 * | 云洋 | 平台 |
 * |---|---|
 * | `feeOver=0`、`typeCode` 1/2/3/4 | 处理中（运费只是冻结，还没结算） |
 * | `feeOver=1` | 成功（结算点，按 `totalFreight` 多退少补） |
 * | `typeCode=99` 已取消且 `feeOver=0` | 明确失败（全额解冻） |
 * | `typeCode=99` 已取消但 `feeOver=1` | 结果未知，转人工（钱已经扣了又显示取消，两边说法对不上） |
 * | 其它 / 解析不出 | 结果未知 |
 *
 * `typeCode=4`（拒收退回）**不是失败**：件已经发出去了，逆向费还要补扣
 * （yunyang.md 第 2 节），完成时间按兜底天数算。把它映射成失败会让平台去退款，
 * 而实际上平台这一单是要收钱的。
 */
class YunyangStatusMapper
{
    public const TYPE_PENDING_PICKUP = 1;

    public const TYPE_IN_TRANSIT = 2;

    public const TYPE_SIGNED = 3;

    public const TYPE_REJECTED = 4;

    public const TYPE_CANCELLED = 99;

    /** 运费只是冻结，还没结算 */
    public const FEE_FROZEN = 0;

    /** 已扣费，结算点 */
    public const FEE_SETTLED = 1;

    public function map(?int $typeCode, ?int $feeOver): UnifiedResult
    {
        if ($typeCode === self::TYPE_CANCELLED) {
            return $feeOver === self::FEE_SETTLED
                // 已经扣过费又报取消：不能当失败退给商户（钱确实付出去了），也不能
                // 当成功（云洋说这单取消了），只能转人工去云洋后台核实
                ? UnifiedResult::Unknown
                : UnifiedResult::DefiniteFailure;
        }

        if ($feeOver === self::FEE_SETTLED) {
            return UnifiedResult::Success;
        }

        if ($feeOver === self::FEE_FROZEN && in_array($typeCode, [
            self::TYPE_PENDING_PICKUP,
            self::TYPE_IN_TRANSIT,
            self::TYPE_SIGNED,
            self::TYPE_REJECTED,
        ], true)) {
            return UnifiedResult::Processing;
        }

        // typeCode 或 feeOver 缺失、取值超出文档列出的范围：拿不准，一律结果未知，
        // 绝不猜明确失败（猜错会把一单已经发出去的快递退款给商户）
        return UnifiedResult::Unknown;
    }

    /**
     * 是否已经签收——订单完成时间（返佣起算点）按签收时间记，
     * 拒收退回按兜底天数，见 yunyang.md 第 2 节。
     */
    public function isSigned(?int $typeCode): bool
    {
        return $typeCode === self::TYPE_SIGNED;
    }
}
