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
use App\Service\Admin\SystemSettingAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 系统设置 - 系统参数（requirements.md 8.3）。
 */
#[Controller(prefix: '/admin/settings')]
class SystemSettingController extends AbstractController
{
    #[Inject]
    protected SystemSettingAdminService $systemSettingAdminService;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('setting.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->systemSettingAdminService->list();
    }

    /**
     * body：{"value": ...}，value 为 null 或空字符串表示恢复默认值。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('setting.manage')]
    #[PutMapping(path: '{key:[a-z_]+}')]
    public function update(string $key): array
    {
        /** @var AdminUser $admin */
        $admin = $this->request->getAttribute('admin');

        return $this->systemSettingAdminService->update(
            $admin,
            $key,
            $this->request->input('value'),
            $this->clientIpResolver->resolve($this->request)
        );
    }
}
