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
 * 错误码占位（跟 OpenApiSignatureMiddleware 的 40001~40007 一个风格，不是平台统一错误码）：
 *   40010 order_no / merchant_order_no 必须二选一（都没传或都传了）
 *   40404 订单不存在（或不属于当前商户，两者对外表现一致，不区分「不存在」和「不是你的」，
 *         避免向调用方泄露「这个单号存在但是别人的」这类信息）
 */
#[Controller(prefix: '/open-api')]
#[Middleware(OpenApiSignatureMiddleware::class)]
class OrderController extends AbstractOpenApiController
{
    private const CODE_INVALID_QUERY = 40010;

    private const CODE_ORDER_NOT_FOUND = 40404;

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
            return $this->fail(self::CODE_INVALID_QUERY, 'exactly one of order_no or merchant_order_no is required');
        }

        $result = $this->orderQueryService->find($merchant, $orderNo, $merchantOrderNo);
        if ($result === null) {
            return $this->fail(self::CODE_ORDER_NOT_FOUND, 'order not found');
        }

        return $this->success($result);
    }

    private function normalize(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
