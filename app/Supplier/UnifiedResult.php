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
 * requirements.md 6.2 定义的"统一结果"，每个供应商驱动的下单/查询订单/解析回调都必须
 * 收敛成这 4 种之一，业务代码只认这 4 种，不关心具体供应商的错误码。
 *
 * 最关键的原则（6.2 原文）：拿不准一律归为 Unknown，绝不能猜成 DefiniteFailure——
 * 错把"未知"当"失败"会触发换供应商，可能导致重复充值、平台多付成本；错把"失败"
 * 当"未知"只是订单慢一点，转人工处理即可。所以两者的犯错代价不对称，任何驱动代码
 * 拿不准该选哪个时，一律选 Unknown。
 */
enum UnifiedResult
{
    /**
     * 成功：供应商明确表示已到账/已发卡/已出票/已扣费。
     */
    case Success;

    /**
     * 明确失败：供应商明确表示订单未受理或已失败、不会扣费。
     */
    case DefiniteFailure;

    /**
     * 处理中：供应商已受理，结果未出，等回调或定时查询。
     */
    case Processing;

    /**
     * 结果未知：其余所有情况（网络超时、HTTP 5xx、响应无法解析、映射表里没有的
     * 错误码、说明含糊的错误码等）。保持处理中语义、定时查询，超过异常单时长转人工。
     */
    case Unknown;
}
