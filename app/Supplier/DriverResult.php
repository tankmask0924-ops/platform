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

namespace App\Supplier;

/**
 * requirements.md 6.2 定义的驱动统一结果值对象：`UnifiedResult` 加上供应商订单号、
 * 失败原因、实际成本、退款金额、供应商返佣（电影票、快递才有，卡速售恒为 null）、
 * 原始请求、原始响应。
 *
 * 金额字段（actualCost/refundAmount/supplierRebate）一律用字符串，不用 float——
 * 跟 database-design.md 里金额字段用 DECIMAL 存储、Model 层不做浮点运算是同一个原则。
 *
 * 【超出 6.2 字面字段列表的必要扩展，写在这里做说明】
 * 6.2 的统一结果字段列表里没有卡密，但卡速售订单详情接口（kasushou.md "卡密"一节）
 * 对卡密类商品会明文返回 card_no/card_password，平台要把这些数据存进
 * order_recharges.card_no/card_pwd（加密后），驱动必须有地方把它们带出来，
 * 不可能凭空产生。所以这里加一个可选的 cardList 字段承载卡密列表，
 * 是本次实现在 6.2 字面要求之外做的必要补充，不是文档遗漏了要求去发明字段。
 * 其他不返回卡密的能力（查询余额等）此字段恒为 null。
 *
 * `supplierBalanceInsufficient`：明确失败的原因是平台在该供应商的预存款不足（卡速售
 * 状态 -1）。requirements.md 6.7 要求这种情况告警财务并立即刷新该供应商余额，路由层
 * 靠这个标志识别，不去解析 failReason 文本。
 */
final class DriverResult
{
    /**
     * @param null|array<int, array{card_no?: string, card_password?: string}> $cardList 见类注释——6.2 字面字段列表之外的必要扩展
     * @param array<string, mixed> $rawRequest
     * @param array<string, mixed> $rawResponse
     */
    public function __construct(
        public readonly UnifiedResult $result,
        public readonly ?string $supplierOrderNo = null,
        public readonly ?string $failReason = null,
        public readonly ?string $actualCost = null,
        public readonly ?string $refundAmount = null,
        public readonly ?string $supplierRebate = null,
        public readonly array $rawRequest = [],
        public readonly array $rawResponse = [],
        public readonly ?array $cardList = null,
        public readonly bool $supplierBalanceInsufficient = false,
    ) {
    }
}
