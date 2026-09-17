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
use App\Model\Merchant;
use App\Service\Merchant\AuthService;
use App\Service\Merchant\BalanceService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 商户管理后台（web/merchant）账户接口（requirements.md 4.1、8.2）。
 *
 * 响应是普通 JSON body，不是开放 API 那套 {code,message,data} 信封——两者是不同的调用方
 * （浏览器 SPA vs 第三方服务端），前端 web/shared/src/http.ts 直接把 axios 响应体当作
 * 目标类型用，所以这里不继承 App\Controller\OpenApi\AbstractOpenApiController。
 * 鉴权失败/校验失败统一靠 HttpException 抛出真实 HTTP 状态码
 * （401/403/422，见 App\Service\Merchant\AuthService 和 App\Middleware\MerchantAuthMiddleware），
 * 前端拦截器按状态码而不是响应体字段判断成败。
 *
 * `me` 用方法级 #[Middleware(MerchantAuthMiddleware::class)] 单独挂载，register/login
 * 不挂，保持公开——类级 #[Middleware] 会连 register/login 一起拦住。
 */
#[Controller(prefix: '/merchant/auth')]
class AuthController extends AbstractController
{
    #[Inject]
    protected AuthService $authService;

    #[Inject]
    protected BalanceService $balanceService;

    #[PostMapping(path: 'register')]
    public function register(): array
    {
        $merchant = $this->authService->register((array) $this->request->getParsedBody());

        return [
            'id' => $merchant->id,
            'status' => $merchant->status,
        ];
    }

    #[PostMapping(path: 'login')]
    public function login(): array
    {
        $username = (string) $this->request->input('username', '');
        $password = (string) $this->request->input('password', '');

        return $this->authService->login($username, $password);
    }

    #[Middleware(MerchantAuthMiddleware::class)]
    #[GetMapping(path: 'me')]
    public function me(): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->request->getAttribute('merchant');

        return [
            'id' => $merchant->id,
            'type' => $merchant->type,
            'status' => $merchant->status,
            'phone' => $merchant->phone,
            'email' => $merchant->email,
            'level_id' => $merchant->level_id,
            'available_balance' => $merchant->available_balance,
            'frozen_balance' => $merchant->frozen_balance,
            // requirements.md 4.5「负余额」：非 null 表示商户当前欠款、下单已被
            // 暂停，商户后台首页据此醒目提示尽快充值；debt_warning 表示欠款已超过预警线。
            'debt_since' => $merchant->debt_since,
            'debt_warning' => $this->balanceService->isOverDebtWarningThreshold($merchant),
        ];
    }
}
