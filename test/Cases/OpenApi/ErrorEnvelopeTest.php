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

use App\Exception\Handler\OpenApiExceptionHandler;
use App\Exception\OpenApiException;
use App\OpenApi\ErrorCode;
use GuzzleHttp\Psr7\ServerRequest;
use Hyperf\Context\Context;
use Hyperf\HttpMessage\Base\Response;
use HyperfTest\HttpTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

use function Hyperf\Support\make;

/**
 * App\Exception\Handler\OpenApiExceptionHandler：`/open-api` 下任何异常都转成
 * {code, message, data} 信封。路由类错误走真实派发，内部异常直接调用处理器
 * （没有现成的开放 API 路由能稳定抛出任意异常）。
 *
 * @internal
 * @coversNothing
 */
class ErrorEnvelopeTest extends HttpTestCase
{
    protected function tearDown(): void
    {
        Context::destroy(ServerRequestInterface::class);

        parent::tearDown();
    }

    public function testUnknownOpenApiRouteReturnsEnvelope()
    {
        $response = $this->client->request('GET', '/open-api/no-such-endpoint');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            ['code' => 49001, 'message' => '接口不存在', 'data' => null],
            json_decode((string) $response->getBody(), true)
        );
    }

    public function testWrongMethodReturnsEnvelope()
    {
        $response = $this->client->request('POST', '/open-api/balance');

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame(49002, json_decode((string) $response->getBody(), true)['code']);
    }

    public function testUnexpectedExceptionReturns500EnvelopeWithoutLeakingDetails()
    {
        $handler = $this->handlerForPath('/open-api/orders/recharge');

        $response = $handler->handle(new RuntimeException('SQLSTATE[HY000] secret-host:3306'), new Response());

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        $this->assertSame(['code' => 50000, 'message' => '系统繁忙，请稍后再试', 'data' => null], json_decode($body, true));
        $this->assertStringNotContainsString('secret-host', $body);
    }

    public function testOpenApiExceptionKeepsItsCodeAndMessage()
    {
        $handler = $this->handlerForPath('/open-api/orders/card');

        $response = $handler->handle(
            new OpenApiException(ErrorCode::ProductBusinessLineMismatch, '商品不是卡券业务线'),
            new Response()
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['code' => 42003, 'message' => '商品不是卡券业务线', 'data' => null],
            $this->decode($response)
        );
    }

    public function testOnlyOpenApiPathsAreHandled()
    {
        $exception = new RuntimeException('boom');

        $this->assertTrue($this->handlerForPath('/open-api')->isValid($exception));
        $this->assertTrue($this->handlerForPath('/open-api/balance')->isValid($exception));
        $this->assertFalse($this->handlerForPath('/open-apix/balance')->isValid($exception));
        $this->assertFalse($this->handlerForPath('/admin/merchants')->isValid($exception));
        $this->assertFalse($this->handlerForPath('/merchant/open-api')->isValid($exception));
    }

    private function handlerForPath(string $path): OpenApiExceptionHandler
    {
        Context::set(ServerRequestInterface::class, new ServerRequest('GET', 'http://example.test' . $path));

        return make(OpenApiExceptionHandler::class);
    }

    private function decode(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }
}
