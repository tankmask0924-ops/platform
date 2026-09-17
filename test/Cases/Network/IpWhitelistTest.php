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

namespace HyperfTest\Cases\Network;

use App\Network\IpAddress;
use App\Network\IpWhitelist;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class IpWhitelistTest extends TestCase
{
    public function testNullOrEmptyWhitelistAllowsEverything()
    {
        $this->assertTrue(IpWhitelist::allows(null, '8.8.8.8'));
        $this->assertTrue(IpWhitelist::allows([], '8.8.8.8'));
        $this->assertTrue(IpWhitelist::allows([], null));
        $this->assertFalse(IpWhitelist::isConfigured(null));
        $this->assertFalse(IpWhitelist::isConfigured([]));
    }

    public function testListedIpIsAllowedAndOthersAreRejected()
    {
        $whitelist = ['1.2.3.4', '2001:db8::1'];

        $this->assertTrue(IpWhitelist::allows($whitelist, '1.2.3.4'));
        $this->assertTrue(IpWhitelist::allows($whitelist, '2001:0db8:0:0:0:0:0:1'));
        $this->assertFalse(IpWhitelist::allows($whitelist, '1.2.3.5'));
        $this->assertFalse(IpWhitelist::allows($whitelist, '2001:db8::2'));
    }

    public function testIpv4MappedIpv6MatchesPlainIpv4()
    {
        $this->assertTrue(IpWhitelist::allows(['1.2.3.4'], '::ffff:1.2.3.4'));
        $this->assertTrue(IpWhitelist::allows(['::ffff:1.2.3.4'], '1.2.3.4'));
    }

    public function testCidrEntriesAreNotSupported()
    {
        $this->assertFalse(IpWhitelist::allows(['10.0.0.0/8'], '10.1.2.3'));
    }

    public function testUnresolvableClientIpIsRejectedWhenConfigured()
    {
        $this->assertFalse(IpWhitelist::allows(['1.2.3.4'], null));
    }

    public function testMalformedWhitelistFailsClosed()
    {
        // 字段不是数组
        $this->assertFalse(IpWhitelist::allows('1.2.3.4', '1.2.3.4'));
        $this->assertFalse(IpWhitelist::allows(123, '1.2.3.4'));
        // 非空但没有任何合法条目
        $this->assertFalse(IpWhitelist::allows(['not-an-ip', 42, null, ['1.2.3.4']], '1.2.3.4'));
        $this->assertFalse(IpWhitelist::allows([''], '1.2.3.4'));
        // 非法条目忽略，合法条目照常生效
        $this->assertTrue(IpWhitelist::allows(['garbage', 7, ' 1.2.3.4 '], '1.2.3.4'));
    }

    public function testIpAddressInRange()
    {
        $this->assertTrue(IpAddress::inRange('10.1.2.3', '10.0.0.0/8'));
        $this->assertTrue(IpAddress::inRange('172.16.5.4', '172.16.0.0/12'));
        $this->assertFalse(IpAddress::inRange('172.32.0.1', '172.16.0.0/12'));
        $this->assertTrue(IpAddress::inRange('192.168.1.7', '192.168.1.7/32'));
        $this->assertTrue(IpAddress::inRange('8.8.8.8', '0.0.0.0/0'));
        $this->assertTrue(IpAddress::inRange('fd00::1', 'fd00::/8'));
        $this->assertFalse(IpAddress::inRange('fe00::1', 'fd00::/8'));
        $this->assertTrue(IpAddress::inRange('127.0.0.1', '127.0.0.1'));
        // 族不同、前缀越界、格式不合法 → 不匹配
        $this->assertFalse(IpAddress::inRange('10.0.0.1', 'fd00::/8'));
        $this->assertFalse(IpAddress::inRange('10.0.0.1', '10.0.0.0/33'));
        $this->assertFalse(IpAddress::inRange('10.0.0.1', '10.0.0.0/abc'));
        $this->assertFalse(IpAddress::inRange('10.0.0.1', 'bogus/8'));
        $this->assertFalse(IpAddress::inRange('bogus', '10.0.0.0/8'));
    }
}
