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
use App\Service\Admin\ProductAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 系统管理后台（web/admin）「本地商品库：CRUD + 各等级比例覆盖」（requirements.md
 * 5.2/8.3），docs/modules.md 第 8 节。
 *
 * 结构跟 App\Controller\Admin\MerchantLevelController 一致（中间件顺序原因见
 * MerchantController 类注释）。两个权限编码：
 * - `product.view`：看商品列表/详情（含各等级比例覆盖）；
 * - `product.manage`：新建/修改商品、上下架、设置/删除等级比例覆盖。
 * 已同步维护 App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS。
 *
 * 等级比例覆盖的路由形状延续 MerchantLevelController 的
 * `PUT /admin/merchant-levels/{id}/rates/{businessLine}` 风格，用商品自己的
 * `{id}` 前缀嵌套 `level-rebates/{levelId}`；删除额外提供 DELETE 方法
 * （requirements.md 5.2「没设置的等级继续用等级比例」，删除是有实际业务含义的
 * 操作，见 ProductAdminService::deleteLevelRebate() 类注释）。
 */
#[Controller(prefix: '/admin/products')]
class ProductController extends AbstractController
{
    #[Inject]
    protected ProductAdminService $productAdminService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        $page = (int) $this->request->input('page', 1);
        $perPage = (int) $this->request->input('per_page', 15);
        $businessLine = $this->request->input('business_line');
        $status = $this->request->input('status');

        return $this->productAdminService->list(
            $page,
            $perPage,
            $businessLine !== null ? (string) $businessLine : null,
            $status !== null ? (string) $status : null
        );
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product.view')]
    #[GetMapping(path: '{id}')]
    public function show(int $id): array
    {
        return $this->productAdminService->detail($id);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product.manage')]
    #[PostMapping(path: '')]
    public function store(): array
    {
        return $this->productAdminService->create($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product.manage')]
    #[PutMapping(path: '{id}')]
    public function update(int $id): array
    {
        return $this->productAdminService->update($id, $this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product.manage')]
    #[PostMapping(path: '{id}/status')]
    public function setStatus(int $id): array
    {
        $this->productAdminService->setStatus($id, $this->request->input('status'));

        return ['success' => true];
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product.manage')]
    #[PutMapping(path: '{id}/level-rebates/{levelId}')]
    public function setLevelRebate(int $id, int $levelId): array
    {
        return $this->productAdminService->setLevelRebate($id, $levelId, $this->request->input('rebate_rate'));
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product.manage')]
    #[DeleteMapping(path: '{id}/level-rebates/{levelId}')]
    public function deleteLevelRebate(int $id, int $levelId): array
    {
        $this->productAdminService->deleteLevelRebate($id, $levelId);

        return ['success' => true];
    }
}
