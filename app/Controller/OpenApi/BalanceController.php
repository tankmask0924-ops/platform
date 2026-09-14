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
use App\Service\OpenApi\BalanceService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 查询余额（requirements.md 8.1 / docs/modules.md 第 6 节）。
 *
 * 中间件用 #[Middleware] 类级注解挂载，不能用
 * #[Controller(options: ['middleware' => [...]])]：Hyperf\HttpServer\Router\DispatcherFactory::
 * handleController() 里 $options['middleware'] 最终会被 $methodMiddlewares（只来自
 * #[Middleware]/#[Middlewares] 注解）整个覆盖掉，Controller 注解里塞的 options.middleware
 * 静默失效，路由能建立但中间件根本不会跑（用真实 HTTP 派发测过，症状是控制器直接拿到
 * 空 Merchant，而不是报路由未找到）。
 */
#[Controller(prefix: '/open-api')]
#[Middleware(OpenApiSignatureMiddleware::class)]
class BalanceController extends AbstractOpenApiController
{
    #[Inject]
    protected BalanceService $balanceService;

    #[GetMapping(path: 'balance')]
    public function show(): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->request->getAttribute('merchant');

        return $this->success($this->balanceService->getBalance($merchant));
    }
}
