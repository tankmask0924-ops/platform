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
use App\Middleware\OpenApiSignatureMiddleware;
use App\Model\Merchant;
use App\Signature\SignatureSigner;
use GuzzleHttp\Psr7\ServerRequest;
use Hyperf\Testing\TestCase;
use Mockery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

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

    private function middleware(): OpenApiSignatureMiddleware
    {
        return $this->getContainer()->get(OpenApiSignatureMiddleware::class);
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

    private function createMerchant(string $status, string $plainSecret): Merchant
    {
        $unique = uniqid('open_api_mw_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => $status,
            'app_key' => 'app_key_' . $unique,
            'app_secret' => (new Encryptor())->encrypt($plainSecret),
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function buildRequest(array $params): ServerRequest
    {
        return (new ServerRequest('POST', 'http://example.test/open/test'))->withQueryParams($params);
    }

    private function decodeBody(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }
}
