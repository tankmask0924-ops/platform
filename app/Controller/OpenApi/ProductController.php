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
use App\Service\OpenApi\ProductListService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 话费商品列表（requirements.md 8.1 / docs/modules.md 第 6 节）。中间件挂载方式沿用
 * App\Controller\OpenApi\BalanceController 定下的模式（#[Middleware] 类级注解，不能用
 * #[Controller(options: ['middleware' => [...]])]，原因见该类注释）。
 *
 * 只支持 business_line=recharge：卡券商品列表是 docs/modules.md 第 6 节里单独一行
 * "卡券商品列表（二期）"，本任务范围是一期话费，传别的取值（包括 card）一律用
 * fail() 返回明确的业务错误，不当成 recharge 处理、也不静默返回空列表——避免调用方
 * 传错参数时以为"这条业务线现在没有商品"。
 *
 * 商户已开通业务线的校验（requirements.md 8.1"商户已开通的商品"）**没有实现**：
 * 这个开通/审核机制目前完全没有 Model/Dao/后台，细节见
 * App\Service\OpenApi\ProductListService 类注释。这里只依赖
 * App\Middleware\OpenApiSignatureMiddleware 已经保证的"商户存在且 status = active"。
 */
#[Controller(prefix: '/open-api')]
#[Middleware(OpenApiSignatureMiddleware::class)]
class ProductController extends AbstractOpenApiController
{
    private const CODE_UNSUPPORTED_BUSINESS_LINE = 40011;

    private const SUPPORTED_BUSINESS_LINE = 'recharge';

    #[Inject]
    protected ProductListService $productListService;

    #[GetMapping(path: 'products')]
    public function index(): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->request->getAttribute('merchant');

        $businessLine = $this->request->input('business_line');
        if ($businessLine !== self::SUPPORTED_BUSINESS_LINE) {
            return $this->fail(
                self::CODE_UNSUPPORTED_BUSINESS_LINE,
                'business_line must be recharge (card is not supported yet)'
            );
        }

        return $this->success($this->productListService->list($merchant, $businessLine));
    }
}
