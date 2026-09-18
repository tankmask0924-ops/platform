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
use App\Service\Admin\AdminUserAdminService;
use App\Service\Admin\RoleAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 系统设置 - 管理员账号（requirements.md 8.3），规则见 AdminUserAdminService。
 */
#[Controller(prefix: '/admin/admin-users')]
class AdminUserController extends AbstractController
{
    #[Inject]
    protected AdminUserAdminService $adminUserAdminService;

    #[Inject]
    protected RoleAdminService $roleAdminService;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('admin_user.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->adminUserAdminService->list($this->request->all());
    }

    /**
     * 新建/编辑账号时的角色下拉，不要求"角色权限查看"权限。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('admin_user.view')]
    #[GetMapping(path: 'role-options')]
    public function roleOptions(): array
    {
        return $this->roleAdminService->options();
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('admin_user.manage')]
    #[PostMapping(path: '')]
    public function store(): array
    {
        return $this->adminUserAdminService->create($this->admin(), $this->request->all(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('admin_user.manage')]
    #[PutMapping(path: '{id:\d+}')]
    public function update(int $id): array
    {
        return $this->adminUserAdminService->update($this->admin(), $id, $this->request->all(), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('admin_user.manage')]
    #[PostMapping(path: '{id:\d+}/status')]
    public function changeStatus(int $id): array
    {
        return $this->adminUserAdminService->changeStatus($this->admin(), $id, $this->request->input('status'), $this->clientIp());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('admin_user.manage')]
    #[PostMapping(path: '{id:\d+}/password')]
    public function resetPassword(int $id): array
    {
        $this->adminUserAdminService->resetPassword($this->admin(), $id, $this->request->input('password'), $this->clientIp());

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
