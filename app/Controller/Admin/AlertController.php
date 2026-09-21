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
use App\Model\Alert;
use App\Service\Admin\AlertAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 系统管理后台「告警：列表查看 / 标记处理」（requirements.md 8.3）。
 *
 * 两个权限编码：`alert.view` 看列表，`alert.handle` 标记已处理/已忽略。分成两档的理由
 * 跟其它模块一致——看告警是"知道平台现在什么状态"，任何运营角色都该能看；标记处理是
 * 在说"这个问题我负责了"，会把告警从未处理列表里拿掉，应该收窄。
 * 记得同步维护 App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS。
 *
 * 没有新建和删除接口：告警只由系统产生（App\Service\Alert\AlertService），
 * 见 App\Service\Admin\AlertAdminService 类注释。
 */
#[Controller(prefix: '/admin/alerts')]
class AlertController extends AbstractController
{
    #[Inject]
    protected AlertAdminService $alertAdminService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('alert.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->alertAdminService->list($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('alert.handle')]
    #[PostMapping(path: '{id}/resolve')]
    public function resolve(int $id): array
    {
        return $this->alertAdminService->markHandled($this->currentAdmin(), $id, Alert::STATUS_RESOLVED, $this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('alert.handle')]
    #[PostMapping(path: '{id}/ignore')]
    public function ignore(int $id): array
    {
        return $this->alertAdminService->markHandled($this->currentAdmin(), $id, Alert::STATUS_IGNORED, $this->request->all());
    }

    private function currentAdmin(): AdminUser
    {
        /* @var AdminUser $admin */
        return $this->request->getAttribute('admin');
    }
}
