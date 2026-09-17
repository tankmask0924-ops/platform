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

namespace App\Network;

/**
 * 开放 API IP 白名单判定（requirements.md 8.1：「商户配置了白名单时，非白名单 IP 拒绝」）。
 *
 * 数据来源 `merchants.ip_whitelist`（json 列，Merchant 模型 cast 成 array），由商户后台
 * App\Service\Merchant\DevSettingsService::updateIpWhitelist() 写入，格式是 IP 字符串组成的
 * JSON 数组（例如 `["1.2.3.4", "2001:db8::1"]`），写入时已经用 FILTER_VALIDATE_IP 校验过。
 *
 * 需求文档没写清楚、由本类决定的行为：
 * - null / 空数组 → 视为「未配置」，放行（与需求原文「配置了白名单时」及 DevSettingsService
 *   类注释的约定一致）。
 * - 只做精确 IP 匹配，**不支持 CIDR**：写入端只接受单个 IP，这里也不认网段，
 *   `10.0.0.0/8` 这样的条目按非法条目处理。IPv6 按规范化后的二进制比较，
 *   IPv4-mapped IPv6 与对应的 IPv4 视为同一地址。
 * - 数据格式异常一律失败即拒绝（fail closed）：
 *   - 字段不是数组（比如手工改库写成了字符串/数字/对象外的标量）→ 拒绝；
 *   - 数组里的非字符串 / 非法 IP 条目忽略，其余合法条目照常匹配；非空但没有任何合法条目
 *     → 没有 IP 能匹配上，等于全部拒绝（商户明确配置过白名单，不能因为数据坏了退化成不限制）。
 * - 客户端 IP 无法确定（null）且配置了白名单 → 拒绝。
 */
final class IpWhitelist
{
    public static function isConfigured(mixed $whitelist): bool
    {
        return ! ($whitelist === null || $whitelist === []);
    }

    public static function allows(mixed $whitelist, ?string $clientIp): bool
    {
        if (! self::isConfigured($whitelist)) {
            return true;
        }

        if (! is_array($whitelist) || $clientIp === null) {
            return false;
        }

        foreach ($whitelist as $entry) {
            if (is_string($entry) && IpAddress::equals($clientIp, $entry)) {
                return true;
            }
        }

        return false;
    }
}
