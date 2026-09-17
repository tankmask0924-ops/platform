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

namespace App\Exception\Handler;

use App\Exception\OpenApiException;
use App\OpenApi\ApiResponse;
use App\OpenApi\ErrorCode;
use Hyperf\Context\Context;
use Hyperf\ExceptionHandler\Annotation\ExceptionHandler as ExceptionHandlerAnnotation;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Logger\LoggerFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * 开放 API（`/open-api/*`）的统一异常出口（requirements.md 8.1「统一返回」）：
 * 这个前缀下抛出的任何异常都转成 {code, message, data} 信封，不再落到框架的
 * HttpExceptionHandler（纯文本）或 AppExceptionHandler（500 纯文本）。
 *
 * - OpenApiException：按它携带的错误码返回；
 * - HttpException：路由不存在 / 方法不对映射成 49001 / 49002，其它状态码统一 49003，
 *   不回显异常文案（可能是框架或内部代码写的，不保证是给商户看的平台文案）；
 * - 其它异常：记 error 日志，返回 50000，不回显任何内部信息。
 *
 * 优先级高于 config/autoload/exceptions.php 里的处理器（那些默认优先级 0），
 * 不是开放 API 的请求直接放给后面的处理器。
 */
#[ExceptionHandlerAnnotation(server: 'http', priority: 100)]
class OpenApiExceptionHandler extends ExceptionHandler
{
    private const PATH_PREFIX = '/open-api';

    public function __construct(protected LoggerFactory $loggerFactory)
    {
    }

    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        if ($throwable instanceof OpenApiException) {
            return ApiResponse::error($throwable->errorCode, $throwable->getMessage());
        }

        if ($throwable instanceof HttpException) {
            return ApiResponse::error(match ($throwable->getStatusCode()) {
                404 => ErrorCode::RouteNotFound,
                405 => ErrorCode::MethodNotAllowed,
                default => ErrorCode::BadRequest,
            });
        }

        $this->loggerFactory->get('open_api')->error('unhandled open api exception', [
            'exception' => $throwable::class,
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile() . ':' . $throwable->getLine(),
            'trace' => $throwable->getTraceAsString(),
        ]);

        return ApiResponse::error(ErrorCode::InternalError);
    }

    public function isValid(Throwable $throwable): bool
    {
        $request = Context::get(ServerRequestInterface::class);
        if (! $request instanceof ServerRequestInterface) {
            return false;
        }

        $path = $request->getUri()->getPath();

        return $path === self::PATH_PREFIX || str_starts_with($path, self::PATH_PREFIX . '/');
    }
}
