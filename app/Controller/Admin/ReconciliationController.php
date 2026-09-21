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
use App\Model\ReconciliationDiff;
use App\Service\Admin\ReconciliationAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 系统管理后台「对账：差异列表 / 标记处理 / 重跑批次」（requirements.md 8.3）。
 *
 * 两个权限编码：`reconciliation.view` 看差异列表，`reconciliation.handle` 标记处理
 * 和重跑批次。重跑归到 handle 而不是单独开第三个编码：它改的是同一批差异记录
 * （整批替换），能重跑的人本来就该能对这批差异负责。
 * 记得同步维护 App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS。
 *
 * 没有新建和删除接口：差异只由对账任务产生，
 * 见 App\Service\Admin\ReconciliationAdminService 类注释。
 */
#[Controller(prefix: '/admin/reconciliations')]
class ReconciliationController extends AbstractController
{
    #[Inject]
    protected ReconciliationAdminService $reconciliationAdminService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('reconciliation.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->reconciliationAdminService->list($this->request->all());
    }

    /**
     * 手动重跑一个批次，同步执行（可能比较慢，见 ReconciliationAdminService 类注释）。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('reconciliation.handle')]
    #[PostMapping(path: 'run')]
    public function run(): array
    {
        return $this->reconciliationAdminService->run($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('reconciliation.handle')]
    #[PostMapping(path: '{id}/resolve')]
    public function resolve(int $id): array
    {
        return $this->reconciliationAdminService->markHandled($this->currentAdmin(), $id, ReconciliationDiff::STATUS_RESOLVED, $this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('reconciliation.handle')]
    #[PostMapping(path: '{id}/ignore')]
    public function ignore(int $id): array
    {
        return $this->reconciliationAdminService->markHandled($this->currentAdmin(), $id, ReconciliationDiff::STATUS_IGNORED, $this->request->all());
    }

    private function currentAdmin(): AdminUser
    {
        /* @var AdminUser $admin */
        return $this->request->getAttribute('admin');
    }
}
