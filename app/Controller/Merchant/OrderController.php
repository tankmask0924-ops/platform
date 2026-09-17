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
use App\Service\Merchant\OrderService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 商户管理后台「订单管理」（requirements.md 8.2），逻辑见 App\Service\Merchant\OrderService。
 * 商户身份只从 `$request->getAttribute('merchant')` 取（同 BalanceLogController），
 * 订单用平台订单号定位，查不到别人的订单。
 */
#[Controller(prefix: '/merchant/orders')]
class OrderController extends AbstractController
{
    #[Inject]
    protected OrderService $orderService;

    #[Middleware(MerchantAuthMiddleware::class)]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->orderService->list($this->currentMerchant(), $this->request->all());
    }

    #[Middleware(MerchantAuthMiddleware::class)]
    #[GetMapping(path: '{orderNo}')]
    public function show(string $orderNo): array
    {
        return $this->orderService->detail($this->currentMerchant(), $orderNo);
    }

    #[Middleware(MerchantAuthMiddleware::class)]
    #[PostMapping(path: '{orderNo}/renotify')]
    public function renotify(string $orderNo): array
    {
        $this->orderService->renotify($this->currentMerchant(), $orderNo);

        return ['success' => true];
    }

    private function currentMerchant(): Merchant
    {
        /* @var Merchant $merchant */
        return $this->request->getAttribute('merchant');
    }
}
