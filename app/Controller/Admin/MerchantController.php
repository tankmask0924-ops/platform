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
use App\Service\Admin\MerchantAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 系统管理后台（web/admin）「商户管理 - 商户列表」（requirements.md 8.3），
 * 这是第 1 节「后台角色权限中间件」这行第一个真实落地的受权限保护的接口。
 *
 * `#[Middleware(AdminAuthMiddleware::class)]` 必须写在
 * `#[Middleware(AdminPermissionMiddleware::class)]` 前面——两者都是方法级注解，
 * 同优先级时按代码里的书写顺序 FIFO 出队执行（见 AdminPermissionMiddleware
 * 类注释里对 SplPriorityQueue/AbstractMultipleAnnotation 行为的引用），
 * 顺序反了的话权限中间件会在 AdminAuthMiddleware 还没来得及把 'admin' attribute
 * 放进 request 之前跑，读 `$request->getAttribute('admin')` 会拿到 null，
 * 后面访问 `$admin->role_id` 直接 500——这个顺序不是靠读代码猜的，
 * test/Cases/Admin/MerchantControllerTest.php::testNoTokenAtAllReturns401NotAPermissionError()
 * 用真实 HTTP 派发验证了「没有 token → 401」而不是「没有 token → 500」，
 * 反过来证明了两个中间件确实按这个顺序执行。
 *
 * 不能用 `#[Controller(options: ['middleware' => [...]])]`，见
 * app/Controller/OpenApi/BalanceController.php 类注释里记录的坑。
 */
#[Controller(prefix: '/admin/merchants')]
class MerchantController extends AbstractController
{
    #[Inject]
    protected MerchantAdminService $merchantAdminService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        $page = (int) $this->request->input('page', 1);
        $perPage = (int) $this->request->input('per_page', 15);

        return $this->merchantAdminService->list($page, $perPage);
    }

    /**
     * 商户详情，跟列表用同一个权限编码（看详情跟看列表是同一档权限，
     * 没有理由为「多看一点字段」单独设一个权限编码）。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.view')]
    #[GetMapping(path: '{id}')]
    public function show(int $id): array
    {
        return $this->merchantAdminService->detail($id);
    }

    /**
     * 入驻审核 - 通过。用独立的 'merchant.review' 权限编码，跟 'merchant.view'
     * 分开——查看商户列表/详情，和真正做出审核这种会改变商户状态的决定，
     * 不应该是同一档权限（记得同步维护 App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS）。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.review')]
    #[PostMapping(path: '{id}/approve')]
    public function approve(int $id): array
    {
        /** @var AdminUser $admin */
        $admin = $this->request->getAttribute('admin');

        $this->merchantAdminService->approve($id, $this->request->input('level_id'), $admin->id);

        return ['success' => true];
    }

    /**
     * 入驻审核 - 驳回，同样是 'merchant.review' 权限。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.review')]
    #[PostMapping(path: '{id}/reject')]
    public function reject(int $id): array
    {
        /** @var AdminUser $admin */
        $admin = $this->request->getAttribute('admin');

        $this->merchantAdminService->reject($id, $this->request->input('reason'), $admin->id);

        return ['success' => true];
    }

    /**
     * 手动调账（requirements.md 4.3），独立权限编码 'merchant.balance_adjust'——
     * 财务改余额是有实际资金影响的动作，不该跟"查看"（merchant.view）共用一档
     * 权限，见 App\Service\Admin\MerchantAdminService::adjustBalance() 类注释。
     * `amount` 可正可负（正数加、负数扣），`reason` 必填，operator_id 从
     * `admin` request attribute 取，不接受请求体传入。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.balance_adjust')]
    #[PostMapping(path: '{id}/balance-adjustments')]
    public function adjustBalance(int $id): array
    {
        /** @var AdminUser $admin */
        $admin = $this->request->getAttribute('admin');

        $this->merchantAdminService->adjustBalance(
            $id,
            $this->request->input('amount'),
            $this->request->input('reason'),
            $admin->id
        );

        return ['success' => true];
    }

    /**
     * 资金流水，跟 show() 用同一档 'merchant.view' 权限——看流水跟看详情是同一档
     * 权限，见 App\Service\Admin\MerchantAdminService::balanceLogs() 类注释。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant.view')]
    #[GetMapping(path: '{id}/balance-logs')]
    public function balanceLogs(int $id): array
    {
        $page = (int) $this->request->input('page', 1);
        $perPage = (int) $this->request->input('per_page', 15);
        $type = $this->request->input('type');

        return $this->merchantAdminService->balanceLogs($id, $page, $perPage, $type);
    }
}
