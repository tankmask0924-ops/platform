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
 * 还没做（没有完整错误码体系），这里只提供成功/业务失败路径的最小封装；鉴权失败的错误响应由
 * App\Middleware\OpenApiSignatureMiddleware 自己短路返回，不经过这里。
 *
 * fail() 是本项目第一个「业务逻辑」失败（不是中间件鉴权失败）：HTTP 状态码统一保持 200，
 * 失败与否完全由响应体里的 code 是否为 0 表达（requirements.md 8.1 原文："统一返回：
 * {code, message, data}...code 非 0 表示失败"，读作应用层信封，不依赖 HTTP 状态码）。
 * 这跟 OpenApiSignatureMiddleware 用 401/403/400 表达鉴权失败是两套不同的约定——
 * 鉴权失败发生在业务逻辑之前，属于「请求本身有没有资格进来」，用 HTTP 状态码表达更符合
 * 网关/日志层面的语义；业务失败（比如订单不存在）发生在鉴权通过之后，是这次调用本身
 * 明确处理了的正常业务分支，所以延续 requirements.md 8.1 的 envelope-only 读法。
 * 错误码延用中间件里占位编号的风格（40001 等），不是平台统一错误码体系
 * （那是 docs/modules.md 第 1 节另一个独立的 ⬜ 任务）。
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

    protected function fail(int $code, string $message): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'data' => null,
        ];
    }
}
