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

/**
 * 开放 API Controller 的公共基类。requirements.md 8.1 要求所有开放 API 响应都是
 * {code, message, data} 形状，但项目级的"统一返回格式与错误码"（docs/modules.md 第 1 节）
 * 还没做（没有完整错误码体系），这里只提供成功路径的最小封装；鉴权失败的错误响应由
 * App\Middleware\OpenApiSignatureMiddleware 自己短路返回，不经过这里。
 */
abstract class AbstractOpenApiController extends AbstractController
{
    protected function success(mixed $data = null): array
    {
        return [
            'code' => 0,
            'message' => 'ok',
            'data' => $data,
        ];
    }
}
