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

namespace App\OpenApi;

use Hyperf\HttpMessage\Base\Response;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;

/**
 * 开放 API 统一返回信封 {code, message, data}（requirements.md 8.1）。
 * Controller 正常返回走 App\Controller\OpenApi\AbstractOpenApiController 的数组形式，
 * 中间件和异常处理器这类需要直接构造 PSR 响应的地方用这里。
 */
final class ApiResponse
{
    public const SUCCESS_CODE = 0;

    public const SUCCESS_MESSAGE = 'ok';

    /**
     * @return array{code: int, message: string, data: mixed}
     */
    public static function successBody(mixed $data = null): array
    {
        return ['code' => self::SUCCESS_CODE, 'message' => self::SUCCESS_MESSAGE, 'data' => $data];
    }

    /**
     * @return array{code: int, message: string, data: null}
     */
    public static function errorBody(ErrorCode $code, ?string $message = null): array
    {
        return ['code' => $code->value, 'message' => $message ?? $code->message(), 'data' => null];
    }

    public static function error(ErrorCode $code, ?string $message = null): ResponseInterface
    {
        $body = (string) json_encode(self::errorBody($code, $message), JSON_UNESCAPED_UNICODE);

        return (new Response())
            ->withStatus($code->httpStatus())
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new SwooleStream($body));
    }
}
