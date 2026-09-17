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

namespace App\Controller\OpenApi;

use App\Controller\AbstractController;
use App\OpenApi\ApiResponse;
use App\OpenApi\ErrorCode;

/**
 * 开放 API Controller 的公共基类，返回 requirements.md 8.1 的 {code, message, data} 信封。
 * 错误码统一定义在 App\OpenApi\ErrorCode；鉴权失败由 App\Middleware\OpenApiSignatureMiddleware
 * 直接返回，Service 层的业务失败抛 App\Exception\OpenApiException，由
 * App\Exception\Handler\OpenApiExceptionHandler 转成信封，都不经过这里。
 */
abstract class AbstractOpenApiController extends AbstractController
{
    protected function success(mixed $data = null): array
    {
        return ApiResponse::successBody($data);
    }

    /**
     * 参数/业务类失败，HTTP 200 + 信封里的错误码（见 App\OpenApi\ErrorCode 的分段说明）。
     * `$message` 只在需要比错误码默认文案更具体时才传，必须是可以给商户看的文案。
     */
    protected function fail(ErrorCode $code, ?string $message = null): array
    {
        return ApiResponse::errorBody($code, $message);
    }
}
