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

namespace App\Supplier\Kasushou;

use App\Supplier\UnifiedResult;

/**
 * kasushou.md 第 2 节"订单状态映射"表的代码化实现，一行对应一个分支，方便逐行对照
 * 文档审计（requirements.md 6.2 点名这张表是资金安全最敏感的部分，"改动走代码评审
 * ...归类错误会直接造成重复充值或资金损失"，所以这里刻意不做"聪明"的合并写法）。
 *
 * | 卡速售状态 | 含义         | 统一结果   | 说明                                   |
 * |-----------|--------------|-----------|----------------------------------------|
 * | 1         | 等待处理      | 处理中     | —                                       |
 * | 2         | 正在处理      | 处理中     | —                                       |
 * | 3         | 交易成功      | 成功       | 卡密商品还需拿到 card_list，没拿到继续查询（处理中） |
 * | 4         | 取消交易      | 明确失败   | 要求 has_back_money 等于 total_price     |
 * | 5         | 已退款        | 明确失败   | 同上                                     |
 * | 4 或 5     | 部分退款      | 结果未知   | has_back_money 小于 total_price，转人工   |
 * | -1        | 未支付（预存款不足） | 明确失败 | 换下一家 + 告警财务，见下方 -1 分支注释    |
 * | 其它未列出的状态码 | —      | 结果未知   | 拿不准一律 Unknown，不猜 |
 */
class KasushouStatusMapper
{
    /**
     * 未支付：平台在该站点的预存款不足。
     */
    public const STATUS_UNPAID = -1;

    /**
     * @param null|array<int, mixed> $cardList 订单详情返回的卡密列表，非卡密类商品/未拿到时传 null
     * @param bool $isCardProduct 这笔订单对应的供应商商品是否卡密类商品（商品映射不在本次范围内，
     *                            由调用方根据 supplier_products 配置传入；驱动本身不知道商品类型）
     * @param null|string $hasBackMoney 状态 4/5 时的退款金额（字符串，金额不用 float）
     * @param null|string $totalPrice 状态 4/5 时的订单总金额（字符串，金额不用 float）
     */
    public function map(
        int $status,
        ?array $cardList,
        bool $isCardProduct,
        ?string $hasBackMoney,
        ?string $totalPrice
    ): UnifiedResult {
        if ($status === 1 || $status === 2) {
            return UnifiedResult::Processing;
        }

        if ($status === 3) {
            return $this->mapSuccess($cardList, $isCardProduct);
        }

        if ($status === 4 || $status === 5) {
            return $this->mapCancelledOrRefunded($hasBackMoney, $totalPrice);
        }

        if ($status === self::STATUS_UNPAID) {
            // 平台在卡速售该站点的预存款不足，订单未受理：明确失败 + 换下一家。
            // 告警财务、立即刷新余额由路由层按 DriverResult::$supplierBalanceInsufficient
            // 处理（SupplierRouter）；计入熔断统计等熔断（6.6，二期）落地时再接。
            return UnifiedResult::DefiniteFailure;
        }

        // 映射表里没有的状态码：拿不准，一律按结果未知处理，绝不猜成明确失败
        // （requirements.md 6.2："遇到映射表里没有的新错误码，按结果未知处理并告警"）。
        return UnifiedResult::Unknown;
    }

    /**
     * 状态 3（交易成功）："卡密商品还需拿到 card_list，没拿到继续查询"——这里的判断
     * 依据只能是调用方传入的 isCardProduct（商品是不是卡密类型不在驱动职责范围内，
     * 见类注释），驱动自己不猜。非卡密商品状态 3 直接算成功；卡密商品必须
     * card_list 非空才算成功，否则仍按处理中处理，等下一次查询。
     *
     * @param null|array<int, mixed> $cardList
     */
    private function mapSuccess(?array $cardList, bool $isCardProduct): UnifiedResult
    {
        if ($isCardProduct && ($cardList === null || $cardList === [])) {
            return UnifiedResult::Processing;
        }

        return UnifiedResult::Success;
    }

    /**
     * 状态 4/5：比较退款金额 has_back_money 和订单金额 total_price。
     * 金额是字符串，一律用 bccomp 比较，不转 float。
     */
    private function mapCancelledOrRefunded(?string $hasBackMoney, ?string $totalPrice): UnifiedResult
    {
        if ($hasBackMoney === null || $totalPrice === null) {
            // 缺字段没法判断是全额还是部分退款，拿不准，按结果未知处理
            return UnifiedResult::Unknown;
        }

        return bccomp($hasBackMoney, $totalPrice, 2) === 0
            ? UnifiedResult::DefiniteFailure
            : UnifiedResult::Unknown;
    }
}
