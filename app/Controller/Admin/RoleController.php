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
use App\Service\Admin\RoleAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 系统设置 - 角色权限（requirements.md 8.3），规则见 RoleAdminService。
 */
#[Controller(prefix: '/admin/roles')]
class RoleController extends AbstractController
{
    #[Inject]
    protected RoleAdminService $roleAdminService;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('role.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->roleAdminService->list();
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('role.view')]
    #[GetMapping(path: 'permissions')]
    public function permissions(): array
    {
        return $this->roleAdminService->permissions();
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('role.manage')]
    #[PostMapping(path: '')]
    public function store(): array
    {
        return $this->roleAdminService->create($this->admin(), $this->request->all(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('role.manage')]
    #[PutMapping(path: '{id:\d+}')]
    public function update(int $id): array
    {
        return $this->roleAdminService->update($this->admin(), $id, $this->request->all(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('role.manage')]
    #[DeleteMapping(path: '{id:\d+}')]
    public function destroy(int $id): array
    {
        $this->roleAdminService->delete($this->admin(), $id, $this->clientIp());

        return ['success' => true];
    }

    private function admin(): AdminUser
    {
        /* @var AdminUser */
        return $this->request->getAttribute('admin');
    }

    private function clientIp(): ?string
    {
        return $this->clientIpResolver->resolve($this->request);
    }
}
