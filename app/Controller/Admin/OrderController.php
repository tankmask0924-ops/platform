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
use App\Service\Admin\OrderAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 系统管理后台「订单管理」（requirements.md 8.3），逻辑见
 * App\Service\Admin\OrderAdminService。中间件顺序约定同 MerchantController。
 *
 * 三档权限：
 * - `order.view`：列表、详情（含成本价、供应商尝试记录）；
 * - `order.manage`：手动查询供应商、手动重推商户回调，不直接改钱；
 * - `order.resolve`：异常单人工置成功/置失败、发起供应商撤单、部分退款，会扣款、解冻、退款或改变供应商侧订单，单独一档。
 * 记得同步维护 App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS。
 */
#[Controller(prefix: '/admin/orders')]
class OrderController extends AbstractController
{
    #[Inject]
    protected OrderAdminService $orderAdminService;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Inject]
    protected ExpressWorkorderAdminService $workorderAdminService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->orderAdminService->list($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.view')]
    #[GetMapping(path: '{id}')]
    public function show(int $id): array
    {
        return $this->orderAdminService->detail($id);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.resolve')]
    #[PostMapping(path: '{id}/resolve')]
    public function resolve(int $id): array
    {
        return $this->orderAdminService->resolveAbnormal(
            $id,
            $this->request->input('result'),
            $this->request->input('remark'),
            $this->request->input('supplier_order_no'),
            $this->adminId(),
            $this->clientIp(),
        );
    }

    /**
     * 快递订单代提交云洋工单（requirements.md 7.2「售后不对商户开放」）。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('aftersale.handle')]
    #[PostMapping(path: '{id}/workorders')]
    public function submitWorkorder(int $id): array
    {
        return $this->workorderAdminService->submit($id, $this->request->all(), $this->adminId(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.resolve')]
    #[PostMapping(path: '{id}/cancel-supplier')]
    public function cancelAtSupplier(int $id): array
    {
        return $this->orderAdminService->cancelAtSupplier($id, $this->adminId(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.resolve')]
    #[PostMapping(path: '{id}/partial-refund')]
    public function partialRefund(int $id): array
    {
        return $this->orderAdminService->partialRefund(
            $id,
            $this->request->input('amount'),
            $this->request->input('remark'),
            $this->adminId(),
            $this->clientIp(),
        );
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.manage')]
    #[PostMapping(path: '{id}/query-supplier')]
    public function querySupplier(int $id): array
    {
        return $this->orderAdminService->querySupplier($id, $this->adminId(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('order.manage')]
    #[PostMapping(path: '{id}/renotify')]
    public function renotify(int $id): array
    {
        $this->orderAdminService->renotify($id, $this->adminId(), $this->clientIp());

        return ['success' => true];
    }

    private function adminId(): int
    {
        /** @var AdminUser $admin */
        $admin = $this->request->getAttribute('admin');

        return (int) $admin->id;
    }

    private function clientIp(): ?string
    {
        return $this->clientIpResolver->resolve($this->request);
    }
}
