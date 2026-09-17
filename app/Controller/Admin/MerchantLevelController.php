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
use App\Service\Admin\MerchantLevelAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 系统管理后台（web/admin）「商户等级：CRUD / 各业务线比例设置」（requirements.md 5.2），
 * docs/modules.md 第 8 节。
 *
 * 结构跟 App\Controller\Admin\SupplierController 一致（中间件顺序原因见
 * MerchantController 类注释）。两个权限编码：
 * - `merchant_level.view`：看等级列表/详情（含各业务线比例）；
 * - `merchant_level.manage`：新建/修改等级、设置比例——直接影响之后所有订单的返佣金额。
 * 已同步维护 App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS。
 *
 * 没有删除接口（原因见 MerchantLevelAdminService 类注释）。
 */
#[Controller(prefix: '/admin/merchant-levels')]
class MerchantLevelController extends AbstractController
{
    #[Inject]
    protected MerchantLevelAdminService $merchantLevelAdminService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant_level.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->merchantLevelAdminService->list();
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant_level.view')]
    #[GetMapping(path: '{id:\d+}')]
    public function show(int $id): array
    {
        return $this->merchantLevelAdminService->detail($id);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant_level.manage')]
    #[PostMapping(path: '')]
    public function store(): array
    {
        return $this->merchantLevelAdminService->create($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant_level.manage')]
    #[PutMapping(path: '{id:\d+}')]
    public function update(int $id): array
    {
        return $this->merchantLevelAdminService->update($id, $this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('merchant_level.manage')]
    #[PutMapping(path: '{id:\d+}/rates/{businessLine}')]
    public function setRate(int $id, string $businessLine): array
    {
        return $this->merchantLevelAdminService->setRate(
            $id,
            $businessLine,
            $this->request->input('rebate_rate')
        );
    }
}
