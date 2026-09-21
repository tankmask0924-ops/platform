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

use App\Middleware\OpenApiSignatureMiddleware;
use App\Model\Merchant;
use App\Service\OpenApi\ExpressQuoteService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 开放 API 快递接口（requirements.md 7.2、8.1，docs/modules.md 第 6 节）。
 * 中间件挂载方式沿用 App\Controller\OpenApi\BalanceController 定下的模式。
 *
 * 查价用 POST 而不是 GET：入参是寄收件地址、重量、体积十来个字段，而地址属于个人信息，
 * 不该出现在 URL 和访问日志里。它本身是只读的，不产生订单、不冻结任何金额。
 * 参数是扁平的标量字段（`sender_province` 这种），因为开放 API 的签名只能拼标量，
 * 理由见 ExpressQuoteService::validate()。
 *
 * 口径和取舍见 App\Service\OpenApi\ExpressQuoteService 类注释。
 */
#[Controller(prefix: '/open-api')]
#[Middleware(OpenApiSignatureMiddleware::class)]
class ExpressController extends AbstractOpenApiController
{
    #[Inject]
    protected ExpressQuoteService $expressQuoteService;

    #[PostMapping(path: 'express/quote')]
    public function quote(): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->request->getAttribute('merchant');

        return $this->success($this->expressQuoteService->quote($merchant, $this->request->all()));
    }
}
