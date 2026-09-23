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

namespace App\Supplier\Mango;

use App\Supplier\UnifiedResult;

/**
 * 芒果电影的错误码 / 状态映射表（mango.md 第 1 节"错误码"、第 3 节）。芒果没有给完整错误码表，
 * 能确定含义的只有下面这些；**表外的一律按结果未知**（mango.md 第 5 节待确认 #1），绝不猜明确失败
 * ——猜错了会把一张可能已经出票的电影票解冻退钱。
 *
 * 订单状态看「查询订单详情」的 `handle_step`（回调只作为触发，见 MangoDriver 类注释）：
 *
 * | handle_step | 含义 | 平台 |
 * |---|---|---|
 * | 0 | 待支付（已锁座，等确认下单） | 处理中 |
 * | 1 | 已支付 | 处理中 |
 * | 2 | 出票中 | 处理中 |
 * | 3 | 已出票 | 成功 |
 * | 4 | 已结算 | 成功 |
 * | -1 | 支付超时（锁座 10 分钟内没确认下单） | 明确失败 |
 * | -2 | 出票失败，已退款 | 明确失败 |
 * | 其它 / 缺失 | — | 结果未知 |
 *
 * 锁座下单同步返回 10040 / 10036 是"订单溢价"（价格跟芒果当前成本对不上），明确失败，
 * 提示商户 2–3 分钟后重新查场次再锁（mango.md 第 3 节）。
 */
class MangoStatusMapper
{
    public const STEP_REFUNDED = -2;

    public const STEP_PAY_TIMEOUT = -1;

    public const STEP_PENDING_PAY = 0;

    public const STEP_PAID = 1;

    public const STEP_ISSUING = 2;

    public const STEP_ISSUED = 3;

    public const STEP_SETTLED = 4;

    /** 锁座下单"订单溢价" */
    public const PRICE_CHANGED_CODES = ['10040', '10036'];

    /**
     * 订单回调的 `code`（只用于日志和排查，资金操作一律以查询结果为准）。
     * 022 客户支付成功只在 H5/小程序场景出现、026 订单结算默认关闭、029 不回调，纯 API 模式都用不到。
     */
    public const CALLBACK_CODES = [
        '000' => '出票成功',
        '056' => '出票失败退款',
        '050' => '系统错误出票失败',
        '026' => '订单结算',
        '022' => '客户支付成功',
    ];

    public function map(?int $handleStep): UnifiedResult
    {
        return match ($handleStep) {
            self::STEP_ISSUED, self::STEP_SETTLED => UnifiedResult::Success,
            self::STEP_PAY_TIMEOUT, self::STEP_REFUNDED => UnifiedResult::DefiniteFailure,
            self::STEP_PENDING_PAY, self::STEP_PAID, self::STEP_ISSUING => UnifiedResult::Processing,
            default => UnifiedResult::Unknown,
        };
    }

    public function isPriceChanged(?string $code): bool
    {
        return $code !== null && in_array($code, self::PRICE_CHANGED_CODES, true);
    }

    public function isLockExpired(?int $handleStep): bool
    {
        return $handleStep === self::STEP_PAY_TIMEOUT;
    }
}
