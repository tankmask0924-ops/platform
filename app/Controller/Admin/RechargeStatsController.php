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

namespace App\Controller\Admin;

use App\Annotation\RequiresPermission;
use App\Controller\AbstractController;
use App\Middleware\AdminAuthMiddleware;
use App\Middleware\AdminPermissionMiddleware;
use App\Service\Order\RechargeStatsService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 系统后台「话费时效」：话费订单的耗时与成功率，口径见 App\Service\Order\RechargeStatsService。
 * 权限用 `order.view`：看的是订单结果，运营、客服、财务都要看。
 */
#[Controller(prefix: '/admin/recharge-stats')]
class RechargeStatsController extends AbstractController
{
    #[Inject]
    protected RechargeStatsService $statsService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->statsService->stats($this->request->all());
    }
}
