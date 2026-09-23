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
use App\Model\AdminUser;
use App\Network\ClientIpResolver;
use App\Service\Admin\ExpressWorkorderAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 系统管理后台「售后处理：快递工单」，逻辑见 App\Service\Admin\ExpressWorkorderAdminService。
 * 提交在订单详情（`POST /admin/orders/{id}/workorders`，见 OrderController）；这里是列表和结单。
 * 权限沿用售后争议：`aftersale.view`、`aftersale.handle`（结单，理赔会调账）。
 */
#[Controller(prefix: '/admin/express-workorders')]
class ExpressWorkorderController extends AbstractController
{
    #[Inject]
    protected ExpressWorkorderAdminService $workorderAdminService;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('aftersale.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->workorderAdminService->list($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('aftersale.handle')]
    #[PostMapping(path: '{id:\d+}/complete')]
    public function complete(int $id): array
    {
        return $this->workorderAdminService->complete($id, $this->request->all(), $this->adminId(), $this->clientIpResolver->resolve($this->request));
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('aftersale.handle')]
    #[PostMapping(path: '{id:\d+}/reject')]
    public function reject(int $id): array
    {
        return $this->workorderAdminService->reject($id, $this->request->all(), $this->adminId(), $this->clientIpResolver->resolve($this->request));
    }

    private function adminId(): int
    {
        /** @var AdminUser $admin */
        $admin = $this->request->getAttribute('admin');

        return (int) $admin->id;
    }
}
