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

namespace App\Controller\Merchant;

use App\Controller\AbstractController;
use App\Middleware\MerchantAuthMiddleware;
use App\OpenApi\ErrorCode;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 商户后台「接口文档」（requirements.md 8.2）。文档正文和签名示例在前端，
 * 这里只提供错误码表，跟开放 API 实际返回的码和文案保持同一份来源。
 */
#[Controller(prefix: '/merchant/api-docs')]
class ApiDocController extends AbstractController
{
    #[Middleware(MerchantAuthMiddleware::class)]
    #[GetMapping(path: 'error-codes')]
    public function errorCodes(): array
    {
        return ['data' => ErrorCode::catalog()];
    }
}
