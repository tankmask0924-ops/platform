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

    /**
     * 导出（requirements.md 8.2「订单管理……导出」）：同一套筛选、不分页，返回 JSON
     * 由前端拼 CSV，原因见 App\Service\Merchant\BalanceLogService::export()。
     *
     * 路由声明在 `{orderNo}` 之前，且 FastRoute 本身也是静态段优先于变量段匹配，
     * 所以不会有订单号叫 "export" 的订单把这个接口挡掉（`testExportRouteIsNotShadowedByOrderNo`）。
     */
    #[Middleware(MerchantAuthMiddleware::class)]
    #[GetMapping(path: 'export')]
    public function export(): array
    {
        return $this->orderService->export($this->currentMerchant(), $this->request->all());
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
