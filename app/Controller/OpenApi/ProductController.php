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
use App\OpenApi\ErrorCode;
use App\Service\OpenApi\ProductListService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 话费、卡券商品列表（requirements.md 8.1「话费、卡券 | 商品列表」/ docs/modules.md
 * 第 6 节）。中间件挂载方式沿用 App\Controller\OpenApi\BalanceController 定下的模式
 * （#[Middleware] 类级注解，不能用 #[Controller(options: ['middleware' => [...]])]，
 * 原因见该类注释）。
 *
 * 两条业务线共用这一个接口（requirements.md 8.1 的接口表里"商品列表"本来就是
 * 「话费、卡券」一行，不是两行），按 `business_line` 区分；电影票、快递没有本地商品库
 * （成本和返佣每次从供应商实时取，requirements.md 6.1），永远不会进这个接口。
 * 取值不在白名单里时用 fail() 返回明确的业务错误，不静默返回空列表——避免调用方传错
 * 参数时以为"这条业务线现在没有商品"。
 *
 * 商户没开通该业务线（requirements.md 4.2）时返回 42007，见 App\Service\OpenApi\ProductListService。
 */
#[Controller(prefix: '/open-api')]
#[Middleware(OpenApiSignatureMiddleware::class)]
class ProductController extends AbstractOpenApiController
{
    /**
     * 有本地商品库的两条业务线，跟商户后台「商品价格」的
     * App\Service\Merchant\ProductPriceService::CATALOG_BUSINESS_LINES 是同一组。
     */
    private const SUPPORTED_BUSINESS_LINES = ['recharge', 'card'];

    #[Inject]
    protected ProductListService $productListService;

    #[GetMapping(path: 'products')]
    public function index(): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->request->getAttribute('merchant');

        $businessLine = $this->request->input('business_line');
        if (! is_string($businessLine) || ! in_array($businessLine, self::SUPPORTED_BUSINESS_LINES, true)) {
            return $this->fail(
                ErrorCode::UnsupportedBusinessLine,
                'business_line 目前只支持 ' . implode('/', self::SUPPORTED_BUSINESS_LINES)
            );
        }

        return $this->success($this->productListService->list($merchant, $businessLine));
    }
}
