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
 * IP 地址的规范化与比较（纯函数，无状态）。
 *
 * 比较一律先转成 inet_pton() 的二进制形式，这样 IPv6 的不同写法（`2001:db8::1` 与
 * `2001:0db8:0:0:0:0:0:1`）会被视为同一个地址；IPv4-mapped IPv6（`::ffff:1.2.3.4`，
 * 双栈监听时 remote_addr 可能是这种形式）会被还原成 4 字节的 IPv4，跟白名单里写的
 * `1.2.3.4` 能对上。
 */
final class IpAddress
{
    private const IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /**
     * 合法 IP 返回规范化后的二进制（4 或 16 字节），否则返回 null。
     */
    public static function toBinary(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $binary = inet_pton($ip);
        if ($binary === false) {
            return null;
        }

        if (strlen($binary) === 16 && str_starts_with($binary, self::IPV4_MAPPED_PREFIX)) {
            return substr($binary, 12);
        }

        return $binary;
    }

    /**
     * 合法 IP 返回规范化后的文本形式，否则返回 null。
     */
    public static function normalize(string $ip): ?string
    {
        $binary = self::toBinary($ip);

        return $binary === null ? null : (string) inet_ntop($binary);
    }

    public static function equals(string $a, string $b): bool
    {
        $binaryA = self::toBinary($a);

        return $binaryA !== null && $binaryA === self::toBinary($b);
    }

    /**
     * $range 可以是单个 IP，也可以是 CIDR（`10.0.0.0/8`、`fd00::/8`）。
     * $range 格式不合法时返回 false（永不匹配），不抛异常。
     */
    public static function inRange(string $ip, string $range): bool
    {
        $range = trim($range);
        if (! str_contains($range, '/')) {
            return self::equals($ip, $range);
        }

        [$network, $prefix] = explode('/', $range, 2);
        if (! ctype_digit($prefix)) {
            return false;
        }

        $ipBinary = self::toBinary($ip);
        $networkBinary = self::toBinary($network);
        if ($ipBinary === null || $networkBinary === null || strlen($ipBinary) !== strlen($networkBinary)) {
            return false;
        }

        $prefixLength = (int) $prefix;
        if ($prefixLength > strlen($networkBinary) * 8) {
            return false;
        }

        $fullBytes = intdiv($prefixLength, 8);
        if (substr($ipBinary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $prefixLength % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
    }
}
