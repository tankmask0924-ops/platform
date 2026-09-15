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
use App\Service\Admin\MerchantAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

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
}
