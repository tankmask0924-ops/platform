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

namespace HyperfTest\Cases\Notify;

use App\Notify\CallbackUrlGuard;
use Hyperf\Testing\TestCase;

/**
 * 纯单测，不碰数据库/网络。只覆盖「host 本身就是字面量 IP」这一类场景——这是
 * 完全确定性的，也是 SSRF 里最重要的一类（DNS rebinding 之外，攻击者可以直接
 * 把回调地址填成 http://169.254.169.254/ 之类）。
 *
 * 「域名解析到内网 IP」这条分支（CallbackUrlGuard::resolveHost()）没有对着真实
 * 域名做单测：这个沙箱环境不保证有可用的外网 DNS，硬要测会变成一个又慢又不
 * 确定（今天能解析、明天域名过期就不能）的测试。这个限制和采用的兜底行为
 * （解析不出任何 IP 时按拒绝处理）写在 CallbackUrlGuard 类注释里。
 *
 * @internal
 * @coversNothing
 */
class CallbackUrlGuardTest extends TestCase
{
    public function testRejectsNonHttpScheme()
    {
        $guard = new CallbackUrlGuard();

        $this->assertFalse($guard->isAllowed('ftp://1.2.3.4/callback'));
        $this->assertFalse($guard->isAllowed('file:///etc/passwd'));
        $this->assertFalse($guard->isAllowed('gopher://1.2.3.4/callback'));
    }

    public function testRejectsMalformedUrl()
    {
        $guard = new CallbackUrlGuard();

        $this->assertFalse($guard->isAllowed('not-a-url'));
        $this->assertFalse($guard->isAllowed(''));
    }

    public function testAcceptsOrdinaryPublicHttpAndHttpsIp()
    {
        $guard = new CallbackUrlGuard();

        // 用字面量公网 IP 代替域名，避开 DNS 解析（见类注释的测试限制）。
        $this->assertTrue($guard->isAllowed('http://8.8.8.8/callback'));
        $this->assertTrue($guard->isAllowed('https://1.1.1.1:8443/callback?x=1'));
    }

    public function testRejectsCloudMetadataEndpoint()
    {
        $guard = new CallbackUrlGuard();

        // 经典 SSRF 目标：云厂商元数据地址，落在 169.254.0.0/16 里。
        $this->assertFalse($guard->isAllowed('http://169.254.169.254/latest/meta-data'));
        $this->assertFalse($guard->isAllowed('http://169.254.169.254/'));
    }

    /**
     * @dataProvider blockedIpv4Provider
     */
    public function testRejectsBlockedIpv4Literal(string $ip)
    {
        $guard = new CallbackUrlGuard();

        $this->assertFalse($guard->isAllowed('http://' . $ip . '/callback'));
    }

    public static function blockedIpv4Provider(): array
    {
        return [
            '10.0.0.0/8' => ['10.1.2.3'],
            '172.16.0.0/12 low' => ['172.16.0.1'],
            '172.16.0.0/12 high' => ['172.31.255.254'],
            '192.168.0.0/16' => ['192.168.1.1'],
            '127.0.0.0/8 loopback' => ['127.0.0.1'],
            '169.254.0.0/16 link-local' => ['169.254.1.1'],
            '169.254.169.254 metadata' => ['169.254.169.254'],
            '0.0.0.0/8' => ['0.0.0.0'],
        ];
    }

    /**
     * @dataProvider blockedIpv6Provider
     */
    public function testRejectsBlockedIpv6Literal(string $url)
    {
        $guard = new CallbackUrlGuard();

        $this->assertFalse($guard->isAllowed($url));
    }

    public static function blockedIpv6Provider(): array
    {
        return [
            '::1 loopback' => ['http://[::1]/callback'],
            'fc00::/7 ULA' => ['http://[fc00::1]/callback'],
            'fd00::/8 ULA (within fc00::/7)' => ['http://[fdff::1]/callback'],
            'fe80::/10 link-local' => ['http://[fe80::1]/callback'],
        ];
    }

    public function testAcceptsOrdinaryPublicIpv6Literal()
    {
        $guard = new CallbackUrlGuard();

        // 2001:4860:4860::8888 是 Google 的公网 IPv6 DNS 地址，不落在任何禁止网段。
        $this->assertTrue($guard->isAllowed('http://[2001:4860:4860::8888]/callback'));
    }

    public function testRejectsMissingHost()
    {
        $guard = new CallbackUrlGuard();

        $this->assertFalse($guard->isAllowed('http:///callback'));
    }
}
