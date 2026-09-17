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

namespace HyperfTest\Cases\OpenApi;

use App\Crypto\Encryptor;
use App\Model\Merchant;
use App\Signature\SignatureSigner;
use HyperfTest\HttpTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * 开放 API IP 白名单的端到端测试（requirements.md 8.1），走真实路由 + #[Middleware] 中间件栈，
 * 以 GET /open-api/balance 为代表（所有开放 API Controller 挂的是同一个
 * OpenApiSignatureMiddleware）。Hyperf\Testing\Client 固定把 remote_addr 设成 127.0.0.1，
 * 需要模拟其它来源 IP 时先 initRequest() 再覆盖 server params，最后 sendRequest()。
 * 细粒度的判定规则见 test/Cases/Network 和 test/Cases/Middleware/OpenApiSignatureMiddlewareTest.php。
 *
 * @internal
 * @coversNothing
 */
class IpWhitelistTest extends HttpTestCase
{
    private array $merchantIds = [];

    protected function tearDown(): void
    {
        foreach ($this->merchantIds as $id) {
            Merchant::destroy($id);
        }
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testRequestFromWhitelistedIpIsServed()
    {
        [$merchant, $secret] = $this->createMerchant(['127.0.0.1']);

        $response = $this->balanceRequest($merchant, $secret, '127.0.0.1');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->decode($response)['code']);
    }

    public function testRequestFromOtherIpIsRejected()
    {
        [$merchant, $secret] = $this->createMerchant(['127.0.0.1']);

        $response = $this->balanceRequest($merchant, $secret, '203.0.113.9');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            ['code' => 40008, 'message' => '来源 IP 不在白名单内', 'data' => null],
            $this->decode($response)
        );
    }

    public function testEmptyWhitelistDoesNotRestrict()
    {
        [$merchant, $secret] = $this->createMerchant([]);

        $response = $this->balanceRequest($merchant, $secret, '203.0.113.9');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->decode($response)['code']);
    }

    public function testMalformedWhitelistIsRejected()
    {
        [$merchant, $secret] = $this->createMerchant(['bogus']);

        $response = $this->balanceRequest($merchant, $secret, '127.0.0.1');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(40008, $this->decode($response)['code']);
    }

    public function testForgedForwardedForHeaderIsIgnored()
    {
        [$merchant, $secret] = $this->createMerchant(['127.0.0.1']);

        $response = $this->balanceRequest($merchant, $secret, '203.0.113.9', [
            'X-Forwarded-For' => '127.0.0.1',
            'X-Real-IP' => '127.0.0.1',
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(40008, $this->decode($response)['code']);
    }

    private function balanceRequest(Merchant $merchant, string $secret, string $remoteAddr, array $headers = []): ResponseInterface
    {
        $request = $this->client->initRequest('GET', '/open-api/balance', [
            'query' => $this->signedParams($merchant->app_key, $secret),
            'headers' => $headers,
        ]);
        $request = $request->withServerParams(['remote_addr' => $remoteAddr] + $request->getServerParams());

        return $this->client->sendRequest($request);
    }

    private function signedParams(string $appKey, string $secret): array
    {
        $params = [
            'app_key' => $appKey,
            'timestamp' => (string) time(),
            'nonce' => uniqid('nonce_', true),
        ];
        $params['sign'] = (new SignatureSigner())->sign($params, $secret);

        return $params;
    }

    /**
     * @return array{0: Merchant, 1: string}
     */
    private function createMerchant(?array $ipWhitelist): array
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $unique = uniqid('ip_whitelist_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => (new Encryptor())->encrypt($secret),
            'ip_whitelist' => $ipWhitelist,
        ]);

        $this->merchantIds[] = $merchant->id;

        return [$merchant, $secret];
    }

    private function decode(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }
}
