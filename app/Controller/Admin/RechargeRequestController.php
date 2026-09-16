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
use App\Service\Admin\RechargeRequestAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 系统管理后台（web/admin）「充值与调账 - 充值申请审核」（requirements.md 4.3、8.3），
 * docs/modules.md 第 8 节。结构、中间件挂载顺序跟
 * App\Controller\Admin\MerchantController 完全一致：`#[Middleware(AdminAuthMiddleware::class)]`
 * 必须写在 `#[Middleware(AdminPermissionMiddleware::class)]` 前面（同优先级按
 * 注解书写顺序 FIFO 出队，顺序反了会在鉴权中间件跑之前就去读
 * `$request->getAttribute('admin')`，拿到 null 后面直接 500）。
 *
 * 两个权限编码：'recharge.view'（列表）、'recharge.manage'（审核通过/驳回），
 * 粒度跟 'merchant.view'/'merchant.review' 一致——查看列表和真正做出会改变
 * 商户余额的审核决定不是同一档权限。记得同步维护
 * App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS。
 */
#[Controller(prefix: '/admin/recharge-requests')]
class RechargeRequestController extends AbstractController
{
    #[Inject]
    protected RechargeRequestAdminService $rechargeRequestAdminService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('recharge.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        $page = (int) $this->request->input('page', 1);
        $perPage = (int) $this->request->input('per_page', 15);
        $status = $this->request->input('status');

        return $this->rechargeRequestAdminService->list($page, $perPage, $status);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('recharge.manage')]
    #[PostMapping(path: '{id}/approve')]
    public function approve(int $id): array
    {
        /** @var AdminUser $admin */
        $admin = $this->request->getAttribute('admin');

        $this->rechargeRequestAdminService->approve($id, $admin->id);

        return ['success' => true];
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('recharge.manage')]
    #[PostMapping(path: '{id}/reject')]
    public function reject(int $id): array
    {
        /** @var AdminUser $admin */
        $admin = $this->request->getAttribute('admin');

        $this->rechargeRequestAdminService->reject($id, $this->request->input('reason'), $admin->id);

        return ['success' => true];
    }
}
