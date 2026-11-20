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
use App\Service\Admin\CardBrandAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 系统管理后台「卡券品牌」，权限跟本地商品一样（product.view / product.manage）。
 */
#[Controller(prefix: '/admin/card-brands')]
class CardBrandController extends AbstractController
{
    #[Inject]
    protected CardBrandAdminService $cardBrandAdminService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        $status = $this->request->input('status');

        return $this->cardBrandAdminService->list($status !== null && $status !== '' ? (string) $status : null);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product.manage')]
    #[PostMapping(path: '')]
    public function store(): array
    {
        return $this->cardBrandAdminService->create($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product.manage')]
    #[PutMapping(path: '{id}')]
    public function update(int $id): array
    {
        return $this->cardBrandAdminService->update($id, $this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product.manage')]
    #[DeleteMapping(path: '{id}')]
    public function destroy(int $id): array
    {
        $this->cardBrandAdminService->delete($id);

        return ['success' => true];
    }
}
