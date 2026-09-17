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

namespace App\Exception;

use App\OpenApi\ErrorCode;
use RuntimeException;

/**
 * 开放 API 链路上（Controller、下单 Service）需要带平台错误码中断请求时抛出，
 * 由 App\Exception\Handler\OpenApiExceptionHandler 转成 {code, message, data} 信封。
 * `$message` 只在需要比错误码默认文案更具体、且仍然是可以给商户看的平台文案时才传。
 */
class OpenApiException extends RuntimeException
{
    public function __construct(public readonly ErrorCode $errorCode, ?string $message = null)
    {
        parent::__construct($message ?? $errorCode->message(), $errorCode->value);
    }
}
