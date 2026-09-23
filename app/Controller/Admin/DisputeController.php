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
use App\Service\Admin\DisputeAdminService;
use App\Service\Admin\DisputeSupplierAftersaleService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 系统管理后台「售后处理：话费卡券争议处理」，逻辑见 App\Service\Admin\DisputeAdminService。
 * 权限：`aftersale.view`（列表、详情）、`aftersale.handle`（驳回、确认未到账，会退款）。
 * 记得同步维护 App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS。
 */
#[Controller(prefix: '/admin/disputes')]
class DisputeController extends AbstractController
{
    #[Inject]
    protected DisputeAdminService $disputeAdminService;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Inject]
    protected DisputeSupplierAftersaleService $supplierAftersaleService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('aftersale.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->disputeAdminService->list($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('aftersale.view')]
    #[GetMapping(path: '{id:\d+}')]
    public function show(int $id): array
    {
        return $this->disputeAdminService->detail($id);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('aftersale.handle')]
    #[PostMapping(path: '{id:\d+}/reject')]
    public function reject(int $id): array
    {
        return $this->disputeAdminService->reject(
            $id,
            $this->request->input('remark'),
            $this->request->input('evidence'),
            $this->adminId(),
            $this->clientIpResolver->resolve($this->request),
        );
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('aftersale.handle')]
    #[PostMapping(path: '{id:\d+}/confirm')]
    public function confirm(int $id): array
    {
        return $this->disputeAdminService->confirm(
            $id,
            $this->request->input('remark'),
            $this->request->input('evidence'),
            $this->adminId(),
            $this->clientIpResolver->resolve($this->request),
        );
    }

    /**
     * 提交给卡速售售后接口核实（requirements.md 7.7），`content` + 可选 `images`（截图链接）。返回争议详情。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('aftersale.handle')]
    #[PostMapping(path: '{id:\d+}/supplier-aftersale')]
    public function submitSupplierAftersale(int $id): array
    {
        $this->supplierAftersaleService->submit($id, $this->request->all(), $this->adminId(), $this->clientIpResolver->resolve($this->request));

        return $this->disputeAdminService->detail($id);
    }

    private function adminId(): int
    {
        /** @var AdminUser $admin */
        $admin = $this->request->getAttribute('admin');

        return (int) $admin->id;
    }
}
