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

use App\Network\ClientIpResolver;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ClientIpResolverTest extends TestCase
{
    public function testRemoteAddrIsUsedWhenNoProxyIsTrusted()
    {
        $request = $this->request('203.0.113.9', '1.2.3.4');

        $this->assertSame('203.0.113.9', (new ClientIpResolver())->resolve($request, []));
    }

    public function testForgedForwardedHeadersAreIgnoredFromUntrustedPeer()
    {
        $request = $this->request('203.0.113.9', '1.2.3.4')->withHeader('X-Real-IP', '1.2.3.4');

        // 对端不是可信代理：哪怕配置了其它可信代理，也不读转发头
        $this->assertSame('203.0.113.9', (new ClientIpResolver())->resolve($request, ['10.0.0.0/8']));
    }

    public function testRightmostUntrustedHopIsUsedBehindTrustedProxy()
    {
        // 客户端自己塞了伪造的 1.2.3.4，真实来源 198.51.100.7 由可信代理追加在右边
        $request = $this->request('10.0.0.2', '1.2.3.4, 198.51.100.7, 10.0.0.1');

        $this->assertSame('198.51.100.7', (new ClientIpResolver())->resolve($request, ['10.0.0.0/8']));
    }

    public function testMultipleForwardedForHeaderLinesAreJoined()
    {
        $request = $this->request('10.0.0.2')
            ->withHeader('X-Forwarded-For', ['1.2.3.4', '198.51.100.7']);

        $this->assertSame('198.51.100.7', (new ClientIpResolver())->resolve($request, ['10.0.0.2']));
    }

    public function testTrustedProxyWithoutForwardedHeaderFallsBackToRemoteAddr()
    {
        $request = $this->request('10.0.0.2');

        $this->assertSame('10.0.0.2', (new ClientIpResolver())->resolve($request, ['10.0.0.0/8']));
    }

    public function testAllHopsTrustedReturnsLeftmost()
    {
        $request = $this->request('10.0.0.3', '10.0.0.1, 10.0.0.2');

        $this->assertSame('10.0.0.1', (new ClientIpResolver())->resolve($request, ['10.0.0.0/8']));
    }

    public function testMalformedHopBehindTrustedProxyIsUnresolvable()
    {
        $request = $this->request('10.0.0.2', '1.2.3.4, not-an-ip');

        $this->assertNull((new ClientIpResolver())->resolve($request, ['10.0.0.0/8']));
    }

    public function testMissingOrInvalidRemoteAddrIsUnresolvable()
    {
        $this->assertNull((new ClientIpResolver())->resolve(new ServerRequest('GET', '/'), []));
        $this->assertNull((new ClientIpResolver())->resolve($this->request('garbage'), []));
    }

    public function testIpv4MappedRemoteAddrIsNormalized()
    {
        $this->assertSame('1.2.3.4', (new ClientIpResolver())->resolve($this->request('::ffff:1.2.3.4'), []));
    }

    private function request(string $remoteAddr, ?string $forwardedFor = null): ServerRequest
    {
        $request = new ServerRequest('GET', '/', [], null, '1.1', ['remote_addr' => $remoteAddr]);

        return $forwardedFor === null ? $request : $request->withHeader('X-Forwarded-For', $forwardedFor);
    }
}
