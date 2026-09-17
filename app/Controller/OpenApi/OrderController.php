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
use App\Service\OpenApi\OrderQueryService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 订单查询（requirements.md 8.1 / docs/modules.md 第 6 节）：按平台订单号 order_no
 * 或商户订单号 merchant_order_no 二选一查询。中间件挂载方式沿用
 * App\Controller\OpenApi\BalanceController 定下的模式（#[Middleware] 类级注解，
 * 不能用 #[Controller(options: ['middleware' => [...]])]，原因见该类注释）。
 *
 * order_no / merchant_order_no 都没传或都传了返回 ErrorCode::InvalidParams；
 * 订单不存在或不属于当前商户都返回 ErrorCode::OrderNotFound，对外不区分这两种情况，
 * 避免向调用方泄露「这个单号存在但是别人的」这类信息。
 */
#[Controller(prefix: '/open-api')]
#[Middleware(OpenApiSignatureMiddleware::class)]
class OrderController extends AbstractOpenApiController
{
    #[Inject]
    protected OrderQueryService $orderQueryService;

    #[GetMapping(path: 'order')]
    public function show(): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->request->getAttribute('merchant');

        $orderNo = $this->normalize($this->request->input('order_no'));
        $merchantOrderNo = $this->normalize($this->request->input('merchant_order_no'));

        if (($orderNo === null) === ($merchantOrderNo === null)) {
            return $this->fail(ErrorCode::InvalidParams, 'order_no 和 merchant_order_no 必须且只能传一个');
        }

        $result = $this->orderQueryService->find($merchant, $orderNo, $merchantOrderNo);
        if ($result === null) {
            return $this->fail(ErrorCode::OrderNotFound);
        }

        return $this->success($result);
    }

    private function normalize(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
