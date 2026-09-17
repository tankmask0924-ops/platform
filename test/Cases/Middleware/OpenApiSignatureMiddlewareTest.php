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

namespace HyperfTest\Cases\Middleware;

use App\Crypto\Encryptor;
use App\Dao\MerchantRateLimitDao;
use App\Middleware\OpenApiSignatureMiddleware;
use App\Model\Merchant;
use App\Model\MerchantRateLimit;
use App\Network\ClientIpResolver;
use App\Signature\MerchantRateLimiter;
use App\Signature\SignatureSigner;
use GuzzleHttp\Psr7\ServerRequest;
use Hyperf\Redis\Redis;
use Hyperf\Testing\TestCase;
use Mockery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionProperty;
use RuntimeException;

use function Hyperf\Support\make;

/**
 * @internal
 * @coversNothing
 */
class OpenApiSignatureMiddlewareTest extends TestCase
{
    private array $merchantIds = [];

    protected function tearDown(): void
    {
        foreach ($this->merchantIds as $id) {
            MerchantRateLimit::where('merchant_id', $id)->delete();
            Merchant::destroy($id);
        }
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testValidSignedRequestPassesThrough()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant('active', $secret);
        $params = $this->signedParams($merchant->app_key, $secret);

        $expectedResponse = Mockery::mock(ResponseInterface::class);
        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(function ($request) use ($merchant) {
                $attached = $request->getAttribute('merchant');

                return $attached instanceof Merchant && $attached->id === $merchant->id;
            }))
            ->andReturn($expectedResponse);

        $response = $this->middleware()->process($this->buildRequest($params), $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testMissingSignatureIsRejected()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant('active', $secret);

        $params = [
            'app_key' => $merchant->app_key,
            'timestamp' => (string) time(),
            'nonce' => uniqid('nonce_', true),
        ];
        // 故意不带 sign

        $handler = Mockery::mock(RequestHandlerInterface::class);

        $response = $this->middleware()->process($this->buildRequest($params), $handler);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(40005, $this->decodeBody($response)['code']);
    }

    public function testWrongSignatureIsRejected()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant('active', $secret);

        $params = $this->signedParams($merchant->app_key, $secret);
        $params['sign'] = 'clearly-wrong-signature';

        $handler = Mockery::mock(RequestHandlerInterface::class);

        $response = $this->middleware()->process($this->buildRequest($params), $handler);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(40005, $this->decodeBody($response)['code']);
    }

    public function testExpiredTimestampIsRejected()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant('active', $secret);
        $params = $this->signedParams($merchant->app_key, $secret, time() - 400);

        $handler = Mockery::mock(RequestHandlerInterface::class);

        $response = $this->middleware()->process($this->buildRequest($params), $handler);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(40006, $this->decodeBody($response)['code']);
    }

    public function testReplayedNonceIsRejected()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant('active', $secret);
        $params = $this->signedParams($merchant->app_key, $secret);

        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldReceive('handle')->once()->andReturn(Mockery::mock(ResponseInterface::class));

        $middleware = $this->middleware();

        // 第一次：合法请求，正常放行并消费掉 nonce
        $middleware->process($this->buildRequest($params), $handler);

        // 第二次：同样的参数（同一个 nonce）重放，应被拒绝，且不会再调用 $handler->handle()
        $second = $middleware->process($this->buildRequest($params), $handler);

        $this->assertSame(401, $second->getStatusCode());
        $this->assertSame(40007, $this->decodeBody($second)['code']);
    }

    public function testUnknownAppKeyIsRejected()
    {
        $params = [
            'app_key' => 'unknown_app_key_' . uniqid('', true),
            'timestamp' => (string) time(),
            'nonce' => uniqid('nonce_', true),
            'sign' => 'whatever',
        ];

        $handler = Mockery::mock(RequestHandlerInterface::class);

        $response = $this->middleware()->process($this->buildRequest($params), $handler);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(40002, $this->decodeBody($response)['code']);
    }

    public function testPendingMerchantIsRejected()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant('pending', $secret);
        $params = $this->signedParams($merchant->app_key, $secret);

        $handler = Mockery::mock(RequestHandlerInterface::class);

        $response = $this->middleware()->process($this->buildRequest($params), $handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(40003, $this->decodeBody($response)['code']);
    }

    public function testDisabledMerchantIsRejected()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant('disabled', $secret);
        $params = $this->signedParams($merchant->app_key, $secret);

        $handler = Mockery::mock(RequestHandlerInterface::class);

        $response = $this->middleware()->process($this->buildRequest($params), $handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(40003, $this->decodeBody($response)['code']);
    }

    public function testWhitelistedIpPassesThrough()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant('active', $secret, ['198.51.100.1', '203.0.113.9']);
        $params = $this->signedParams($merchant->app_key, $secret);

        $expectedResponse = Mockery::mock(ResponseInterface::class);
        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldReceive('handle')->once()->andReturn($expectedResponse);

        $response = $this->middleware()->process($this->buildRequest($params, '203.0.113.9'), $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testNonWhitelistedIpIsRejectedWithoutConsumingNonce()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant('active', $secret, ['198.51.100.1']);
        $params = $this->signedParams($merchant->app_key, $secret);

        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldReceive('handle')->once()->andReturn(Mockery::mock(ResponseInterface::class));

        $middleware = $this->middleware();

        $response = $middleware->process($this->buildRequest($params, '203.0.113.9'), $handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            ['code' => 40008, 'message' => 'ip not allowed', 'data' => null],
            $this->decodeBody($response)
        );

        // 被白名单拒绝的请求不消耗 nonce：同一组参数从白名单 IP 发来仍然能通过
        $middleware->process($this->buildRequest($params, '198.51.100.1'), $handler);
    }

    public function testEmptyOrNullWhitelistAllowsAnyIp()
    {
        foreach ([[], null] as $whitelist) {
            $secret = 'plain-secret-' . uniqid('', true);
            $merchant = $this->createMerchant('active', $secret, $whitelist);
            $params = $this->signedParams($merchant->app_key, $secret);

            $expectedResponse = Mockery::mock(ResponseInterface::class);
            $handler = Mockery::mock(RequestHandlerInterface::class);
            $handler->shouldReceive('handle')->once()->andReturn($expectedResponse);

            $response = $this->middleware()->process($this->buildRequest($params, '203.0.113.9'), $handler);

            $this->assertSame($expectedResponse, $response);
        }
    }

    public function testMalformedWhitelistFailsClosed()
    {
        // 非数组（json 字符串）、以及非空但全是非法条目的数组，都按拒绝处理
        foreach (['203.0.113.9', ['not-an-ip', '203.0.113.0/24']] as $whitelist) {
            $secret = 'plain-secret-' . uniqid('', true);
            $merchant = $this->createMerchant('active', $secret, $whitelist);
            $params = $this->signedParams($merchant->app_key, $secret);

            $handler = Mockery::mock(RequestHandlerInterface::class);

            $response = $this->middleware()->process($this->buildRequest($params, '203.0.113.9'), $handler);

            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame(40008, $this->decodeBody($response)['code']);
        }
    }

    public function testForgedForwardedHeaderCannotBypassWhitelist()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant('active', $secret, ['198.51.100.1']);
        $params = $this->signedParams($merchant->app_key, $secret);

        $handler = Mockery::mock(RequestHandlerInterface::class);

        $request = $this->buildRequest($params, '203.0.113.9')
            ->withHeader('X-Forwarded-For', '198.51.100.1')
            ->withHeader('X-Real-IP', '198.51.100.1');

        $response = $this->middleware()->process($request, $handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(40008, $this->decodeBody($response)['code']);
    }

    public function testForwardedHeaderIsHonouredOnlyBehindTrustedProxy()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant('active', $secret, ['198.51.100.1']);

        $middleware = $this->middlewareTrustingProxies(['10.0.0.0/8']);

        // 可信代理追加的真实来源在白名单内 → 放行
        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldReceive('handle')->once()->andReturn(Mockery::mock(ResponseInterface::class));
        $allowed = $this->buildRequest($this->signedParams($merchant->app_key, $secret), '10.0.0.5')
            ->withHeader('X-Forwarded-For', '198.51.100.1');
        $middleware->process($allowed, $handler);

        // 客户端在最左边伪造白名单 IP，但代理追加的真实来源不在白名单 → 拒绝
        $forged = $this->buildRequest($this->signedParams($merchant->app_key, $secret), '10.0.0.5')
            ->withHeader('X-Forwarded-For', '198.51.100.1, 203.0.113.9');
        $response = $middleware->process($forged, Mockery::mock(RequestHandlerInterface::class));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(40008, $this->decodeBody($response)['code']);
    }

    public function testWhitelistIsCheckedOnlyAfterSignature()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant('active', $secret, ['198.51.100.1']);
        $params = $this->signedParams($merchant->app_key, $secret);
        $params['sign'] = 'clearly-wrong-signature';

        $response = $this->middleware()->process(
            $this->buildRequest($params, '203.0.113.9'),
            Mockery::mock(RequestHandlerInterface::class)
        );

        // 没有 AppSecret 的请求拿不到 40008，无法据此探测白名单配置
        $this->assertSame(40005, $this->decodeBody($response)['code']);
    }

    public function testRequestsOverMerchantLimitAreRejectedWith429()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant('active', $secret);
        make(MerchantRateLimitDao::class)->upsertLimit($merchant->id, 2);
        $middleware = $this->middlewareWithFixedSecond();

        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldReceive('handle')->twice()->andReturn(Mockery::mock(ResponseInterface::class));

        for ($i = 0; $i < 2; ++$i) {
            $middleware->process($this->buildRequest($this->signedParams($merchant->app_key, $secret)), $handler);
        }
        $response = $middleware->process($this->buildRequest($this->signedParams($merchant->app_key, $secret)), $handler);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(40009, $this->decodeBody($response)['code']);
    }

    public function testFailedAuthenticationDoesNotConsumeQuota()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant('active', $secret);
        make(MerchantRateLimitDao::class)->upsertLimit($merchant->id, 1);
        $middleware = $this->middlewareWithFixedSecond();

        $forged = $this->signedParams($merchant->app_key, $secret);
        $forged['sign'] = 'clearly-wrong-signature';
        $replayed = $this->signedParams($merchant->app_key, $secret);
        $replayed['timestamp'] = (string) (time() - 400);
        $replayed['sign'] = (new SignatureSigner())->sign(array_diff_key($replayed, ['sign' => true]), $secret);

        $rejectingHandler = Mockery::mock(RequestHandlerInterface::class);
        $this->assertSame(40005, $this->decodeBody($middleware->process($this->buildRequest($forged), $rejectingHandler))['code']);
        $this->assertSame(40006, $this->decodeBody($middleware->process($this->buildRequest($replayed), $rejectingHandler))['code']);

        $expectedResponse = Mockery::mock(ResponseInterface::class);
        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldReceive('handle')->once()->andReturn($expectedResponse);

        $response = $middleware->process($this->buildRequest($this->signedParams($merchant->app_key, $secret)), $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testRateLimiterFailureLetsRequestThrough()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant('active', $secret);

        $limiter = Mockery::mock(MerchantRateLimiter::class);
        $limiter->shouldReceive('attempt')->andThrow(new RuntimeException('redis down'));
        $middleware = make(OpenApiSignatureMiddleware::class);
        (new ReflectionProperty($middleware, 'rateLimiter'))->setValue($middleware, $limiter);

        $expectedResponse = Mockery::mock(ResponseInterface::class);
        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldReceive('handle')->once()->andReturn($expectedResponse);

        $response = $middleware->process($this->buildRequest($this->signedParams($merchant->app_key, $secret)), $handler);

        $this->assertSame($expectedResponse, $response);
    }

    private function middleware(): OpenApiSignatureMiddleware
    {
        return $this->getContainer()->get(OpenApiSignatureMiddleware::class);
    }

    /**
     * 容器里的中间件是单例，这里 make() 一个新实例并换掉它的 ClientIpResolver，
     * 不污染其它测试。
     */
    private function middlewareTrustingProxies(array $trustedProxies): OpenApiSignatureMiddleware
    {
        $middleware = make(OpenApiSignatureMiddleware::class);
        $resolver = new class($trustedProxies) extends ClientIpResolver {
            public function __construct(private array $proxies)
            {
            }

            public function trustedProxiesFromEnv(): array
            {
                return $this->proxies;
            }
        };
        (new ReflectionProperty($middleware, 'clientIpResolver'))->setValue($middleware, $resolver);

        return $middleware;
    }

    /**
     * 限流计数固定到同一秒，几次请求恰好跨秒时用例也不会不稳定。
     */
    private function middlewareWithFixedSecond(): OpenApiSignatureMiddleware
    {
        $limiter = new class extends MerchantRateLimiter {
            protected function currentSecond(): int
            {
                return 1_000_000;
            }
        };
        (new ReflectionProperty(MerchantRateLimiter::class, 'redis'))->setValue($limiter, make(Redis::class));

        $middleware = make(OpenApiSignatureMiddleware::class);
        (new ReflectionProperty($middleware, 'rateLimiter'))->setValue($middleware, $limiter);

        return $middleware;
    }

    private function signedParams(string $appKey, string $secret, ?int $timestamp = null): array
    {
        $params = [
            'app_key' => $appKey,
            'timestamp' => (string) ($timestamp ?? time()),
            'nonce' => uniqid('nonce_', true),
        ];
        $params['sign'] = (new SignatureSigner())->sign($params, $secret);

        return $params;
    }

    private function createMerchant(string $status, string $plainSecret, mixed $ipWhitelist = null): Merchant
    {
        $unique = uniqid('open_api_mw_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => $status,
            'app_key' => 'app_key_' . $unique,
            'app_secret' => (new Encryptor())->encrypt($plainSecret),
            'ip_whitelist' => $ipWhitelist,
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function buildRequest(array $params, string $remoteAddr = '127.0.0.1'): ServerRequest
    {
        return (new ServerRequest('POST', 'http://example.test/open/test', [], null, '1.1', ['remote_addr' => $remoteAddr]))
            ->withQueryParams($params);
    }

    private function decodeBody(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }
}
