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
use App\Service\Order\RechargeOrderPlacementService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 话费下单（requirements.md 8.1「下单」话费一侧，卡券参数不同、单独设计，不在这次
 * 任务范围）。中间件挂载方式沿用 App\Controller\OpenApi\BalanceController 定下的
 * 模式（#[Middleware] 类级注解，原因见该类注释）。
 *
 * 本 Controller 只做输入的「有没有传、形状对不对」这类浅层校验，业务规则校验
 * （商品是否存在/是否话费/是否上架、余额够不够、供应商路由）全部在
 * App\Service\Order\RechargeOrderPlacementService 里，跟这个代码库「Controller
 * 不写业务逻辑」的既有分层约定一致。
 *
 * 错误码占位（跟 OpenApiSignatureMiddleware 的 40001~40007、OrderController 的
 * 40010/40404、ProductController 的 40011 一个风格，不是平台统一错误码）：
 *   40020 必填参数缺失或形状不对（merchant_order_no/product_id/recharge_account/callback_url）
 *
 * 商品不存在/不是话费业务线/未上架，由 Service 直接抛 Hyperf\HttpMessage\Exception\
 * HttpException（跟 App\Service\Merchant\BalanceService、App\Service\Admin\
 * ProductMappingAdminService 等既有 Service 同样的约定），不经过这里的 fail()
 * envelope——这类"调用方传参不合法"场景在这个代码库里一贯用 HTTP 状态码表达，
 * 不强行套进 {code,message,data} 信封。
 */
#[Controller(prefix: '/open-api')]
#[Middleware(OpenApiSignatureMiddleware::class)]
class RechargeOrderController extends AbstractOpenApiController
{
    private const CODE_INVALID_PARAMS = 40020;

    #[Inject]
    protected RechargeOrderPlacementService $placementService;

    #[PostMapping(path: 'orders/recharge')]
    public function create(): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->request->getAttribute('merchant');

        $merchantOrderNo = $this->normalizeString($this->request->input('merchant_order_no'));
        $productId = $this->request->input('product_id');
        $rechargeAccount = $this->normalizeString($this->request->input('recharge_account'));
        $callbackUrl = $this->normalizeString($this->request->input('callback_url'));

        if ($merchantOrderNo === null
            || $rechargeAccount === null
            || $callbackUrl === null
            || ! $this->isPositiveIntLike($productId)
            || filter_var($callbackUrl, FILTER_VALIDATE_URL) === false
        ) {
            return $this->fail(
                self::CODE_INVALID_PARAMS,
                'merchant_order_no/product_id/recharge_account/callback_url is missing or malformed'
            );
        }

        $result = $this->placementService->place(
            $merchant,
            $merchantOrderNo,
            (int) $productId,
            $rechargeAccount,
            $callbackUrl
        );

        return $this->success($result);
    }

    private function normalizeString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isPositiveIntLike(mixed $value): bool
    {
        return is_numeric($value) && (int) $value > 0 && (string) (int) $value === (string) $value;
    }
}
