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

namespace App\Notify;

/**
 * SSRF 防护（requirements.md 7.6）：商户每笔下单时自己传入回调地址，平台完全不
 * 信任这个字符串——校验必须在发起任何出站 HTTP 请求之前完成。
 *
 * 校验规则：
 *   1. scheme 必须是 http 或 https；
 *   2. 如果 host 本身就是字面量 IP（包括 [::1] 这种带方括号的 IPv6 写法），
 *      直接按 IP 判断是否落在内网/环回/链路本地/保留网段；
 *   3. 如果 host 是域名，解析出所有 A/AAAA 记录，只要有一个解析结果落在禁止网段
 *      就拒绝——只查字符串形式的域名本身完全挡不住「域名解析到内网 IP」（DNS
 *      rebinding，或者攻击者自己控制的域名）这种绕过方式。
 *
 * 禁止网段（IPv4）：10.0.0.0/8、172.16.0.0/12、192.168.0.0/16、127.0.0.0/8、
 * 169.254.0.0/16（含云厂商元数据地址 169.254.169.254，经典 SSRF 目标）、0.0.0.0/8；
 * （IPv6）：::1/128、fc00::/7（ULA）、fe80::/10（link-local）。
 *
 * 测试限制：本仓库的单测沙箱不保证有可用的外网 DNS（也不应该依赖真实网络让测试
 * 变慢/变得不确定），所以 test/Cases/Notify/CallbackUrlGuardTest.php 主要覆盖
 * 「host 本身就是字面量 IP」的场景（包括 169.254.169.254），这部分是完全确定性的，
 * 也是最重要的一类攻击面；域名解析那条分支的行为写在 resolveHost() 的注释里，
 * 没有对着真实域名做网络单测。resolveHost() 解析失败（比如域名压根解析不出
 * 任何 IP，或者沙箱没有网络）时按「拒绝」处理（fail closed）——一个无法验证是否
 * 安全的域名，不应该被放行去发起出站请求。
 */
class CallbackUrlGuard
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * @var string[] CIDR 格式，IPv4/IPv6 混在一起，ipInRange() 会按地址族自动跳过不匹配的
     */
    private const BLOCKED_RANGES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '0.0.0.0/8',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    public function isAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), self::ALLOWED_SCHEMES, true)) {
            return false;
        }

        $host = $this->stripIpv6Brackets($parts['host']);

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! $this->isBlockedIp($host);
        }

        $resolvedIps = $this->resolveHost($host);
        if ($resolvedIps === []) {
            // 解析不出任何 IP：可能域名压根不存在，也可能是沙箱/网络问题——两种
            // 情况都无法确认「安全」，一律拒绝，见类注释。
            return false;
        }

        foreach ($resolvedIps as $ip) {
            if ($this->isBlockedIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 把域名解析成 IPv4/IPv6 地址列表。真实环境下有网络时会走这里；单测环境
     * 不对着真实域名断言这个方法的结果（见类注释的"测试限制"）。
     *
     * @return string[]
     */
    protected function resolveHost(string $host): array
    {
        $ips = [];

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $ipv4 = @gethostbynamel($host);
            if (is_array($ipv4)) {
                $ips = array_merge($ips, $ipv4);
            }
        }

        return array_values(array_unique($ips));
    }

    private function stripIpv6Brackets(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    private function isBlockedIp(string $ip): bool
    {
        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function ipInRange(string $ip, string $cidr): bool
    {
        [$subnet, $maskBits] = explode('/', $cidr);
        $maskBits = (int) $maskBits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            // 地址族不一致（比如 IPv4 地址去比 IPv6 网段），肯定不在这个网段里
            return false;
        }

        $fullBytes = intdiv($maskBits, 8);
        $remainingBits = $maskBits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainingBits)) & 0xFF);

        return (substr($ipBin, $fullBytes, 1) & $mask) === (substr($subnetBin, $fullBytes, 1) & $mask);
    }
}
