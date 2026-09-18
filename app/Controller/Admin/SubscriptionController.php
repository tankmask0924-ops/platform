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
use App\Service\Admin\SubscriptionAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 系统后台「服务开通审核」（requirements.md 4.2、8.3）。
 */
#[Controller(prefix: '/admin/subscriptions')]
class SubscriptionController extends AbstractController
{
    #[Inject]
    protected SubscriptionAdminService $subscriptionAdminService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('subscription.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->subscriptionAdminService->list($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('subscription.review')]
    #[PostMapping(path: '{id}/approve')]
    public function approve(int $id): array
    {
        $this->subscriptionAdminService->approve($id, $this->admin()->id);

        return ['success' => true];
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('subscription.review')]
    #[PostMapping(path: '{id}/reject')]
    public function reject(int $id): array
    {
        $this->subscriptionAdminService->reject($id, $this->request->input('reason'), $this->admin()->id);

        return ['success' => true];
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $admin */
        return $this->request->getAttribute('admin');
    }
}
