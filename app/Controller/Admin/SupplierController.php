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
use App\Service\Admin\SupplierAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 系统管理后台（web/admin）「供应商管理 - 配置 CRUD」（requirements.md 6.3），
 * docs/modules.md 第 8 节。
 *
 * 跟 App\Controller\Admin\MerchantController 同样的结构约定：方法级
 * `#[Middleware(AdminAuthMiddleware::class)]` 必须写在
 * `#[Middleware(AdminPermissionMiddleware::class)]` 前面（顺序原因见
 * MerchantController 类注释 / AdminPermissionMiddleware 类注释）。
 *
 * 两个权限编码，粒度跟商户管理那一组（merchant.view / merchant.review）一致：
 * - `supplier.view`：看列表/详情，包括脱敏后的接口配置有哪些字段——不涉及
 *   任何密钥明文，属于「知道供应商怎么接的」这一档权限；
 * - `supplier.manage`：新建/修改/启停，会真正改变供应商的接口凭证和路由资格，
 *   是明显更高的一档权限，不应该跟"只是想看看列表"的权限混在一起。
 * 记得同步维护 App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS。
 */
#[Controller(prefix: '/admin/suppliers')]
class SupplierController extends AbstractController
{
    #[Inject]
    protected SupplierAdminService $supplierAdminService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('supplier.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        $page = (int) $this->request->input('page', 1);
        $perPage = (int) $this->request->input('per_page', 15);

        return $this->supplierAdminService->list($page, $perPage);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('supplier.view')]
    #[GetMapping(path: '{id}')]
    public function show(int $id): array
    {
        return $this->supplierAdminService->detail($id);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('supplier.manage')]
    #[PostMapping(path: '')]
    public function store(): array
    {
        return $this->supplierAdminService->create($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('supplier.manage')]
    #[PutMapping(path: '{id}')]
    public function update(int $id): array
    {
        return $this->supplierAdminService->update($id, $this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('supplier.manage')]
    #[PostMapping(path: '{id}/status')]
    public function setStatus(int $id): array
    {
        $this->supplierAdminService->setStatus($id, $this->request->input('status'));

        return ['success' => true];
    }
}
