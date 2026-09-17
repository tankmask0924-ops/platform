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

use Psr\Http\Message\ServerRequestInterface;

use function Hyperf\Support\env;

/**
 * 解析开放 API 请求的真实客户端 IP（给 IP 白名单校验用）。
 *
 * 安全默认：**不信任任何转发头**。项目目前没有任何反向代理/负载均衡的约定或配置，
 * 所以直接取 TCP 连接的对端地址（Swoole 的 server param `remote_addr`），客户端随便
 * 伪造的 `X-Forwarded-For` / `X-Real-IP` 一律忽略，避免被伪造头绕过白名单。
 *
 * 部署在反向代理后面时，通过环境变量 `OPEN_API_TRUSTED_PROXIES` 显式声明可信代理
 * （逗号分隔，支持单个 IP 或 CIDR，例如 `10.0.0.0/8,172.16.0.0/12`）。只有当
 * `remote_addr` 本身落在可信代理里时，才会读 `X-Forwarded-For`：从右往左跳过可信代理，
 * 取第一个不是可信代理的地址作为客户端 IP（最右边的条目是离我们最近的代理追加的，
 * 左边的条目客户端可以任意伪造，所以不能直接取最左边）。
 * - 遇到格式不合法的条目 → 返回 null（无法确定来源，调用方按拒绝处理）
 * - 整条链都是可信代理 → 取最左边那个
 * - 没有 X-Forwarded-For 头 → 退回 remote_addr
 * 只认 `X-Forwarded-For`，不认 `X-Real-IP` / `Forwarded`，避免多个头之间互相矛盾。
 */
class ClientIpResolver
{
    public const TRUSTED_PROXIES_ENV = 'OPEN_API_TRUSTED_PROXIES';

    /**
     * @param null|list<string> $trustedProxies 为 null 时从环境变量读取（测试可直接传入）
     */
    public function resolve(ServerRequestInterface $request, ?array $trustedProxies = null): ?string
    {
        $trustedProxies ??= $this->trustedProxiesFromEnv();

        $serverParams = $request->getServerParams();
        $remoteAddr = $serverParams['remote_addr'] ?? $serverParams['REMOTE_ADDR'] ?? null;
        if (! is_string($remoteAddr)) {
            return null;
        }
        $remoteAddr = IpAddress::normalize($remoteAddr);
        if ($remoteAddr === null) {
            return null;
        }

        if (! $this->isTrusted($remoteAddr, $trustedProxies)) {
            return $remoteAddr;
        }

        $hops = $this->splitList(implode(',', $request->getHeader('X-Forwarded-For')));
        if ($hops === []) {
            return $remoteAddr;
        }

        $candidate = $remoteAddr;
        foreach (array_reverse($hops) as $hop) {
            $hop = IpAddress::normalize($hop);
            if ($hop === null) {
                return null;
            }
            $candidate = $hop;
            if (! $this->isTrusted($hop, $trustedProxies)) {
                return $hop;
            }
        }

        return $candidate;
    }

    /**
     * @return list<string>
     */
    public function trustedProxiesFromEnv(): array
    {
        $raw = env(self::TRUSTED_PROXIES_ENV);

        return is_string($raw) ? $this->splitList($raw) : [];
    }

    /**
     * @return list<string>
     */
    private function splitList(string $raw): array
    {
        $items = array_map('trim', explode(',', $raw));

        return array_values(array_filter($items, static fn (string $item) => $item !== ''));
    }

    /**
     * @param list<string> $trustedProxies
     */
    private function isTrusted(string $ip, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $range) {
            if (IpAddress::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }
}
