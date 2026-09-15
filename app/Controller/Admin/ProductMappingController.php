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
use App\Service\Admin\ProductMappingAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 系统管理后台（web/admin）「商品映射与成本价」（requirements.md 6.4），
 * docs/modules.md 第 8 节「供应商管理：商品映射」这一行。
 *
 * 结构跟 App\Controller\Admin\SupplierController 同样的约定：方法级
 * `#[Middleware(AdminAuthMiddleware::class)]` 必须写在
 * `#[Middleware(AdminPermissionMiddleware::class)]` 前面。
 *
 * **路由形状的取舍**：一条映射行天然挂在"某个本地商品"下面，Hyperf 的
 * `#[Controller(prefix: ...)]` 是类级单一前缀（`DispatcherFactory::handleController()`
 * 总是拼成 `prefix . '/' . mapping->path`，不支持某个方法用绝对路径覆盖前缀），
 * 一个 Controller 类没法同时自然表达 `/admin/products/{productId}/mappings`
 * 和 `/admin/product-mappings/{id}` 两种前缀。本 Controller 选择整体挂在单一前缀
 * `/admin/product-mappings` 下：列表用 `?product_id=` 查询参数过滤（跟
 * `GET /admin/suppliers?page=&per_page=` 用查询参数过滤/分页是同一套约定，
 * 不是另起一套"路径参数表达从属关系"的风格），单条映射的改价/优先级/状态/详情
 * 更新则直接用映射自己的 `{id}`——比拆成两个 Controller 类更简单，且不需要为了
 * 凑绝对路径去传一个容易让人误解的 `prefix: '/'` 技巧。
 *
 * 两个权限编码，粒度跟供应商管理那一组（supplier.view / supplier.manage）一致：
 * `product_mapping.view`（列表）、`product_mapping.manage`（新建 + 所有改价/
 * 优先级/状态/详情更新动作）。记得同步维护
 * App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS。
 */
#[Controller(prefix: '/admin/product-mappings')]
class ProductMappingController extends AbstractController
{
    #[Inject]
    protected ProductMappingAdminService $productMappingAdminService;

    /**
     * 某个本地商品的全部供应商映射，按优先级排序。`product_id` 是必填查询参数——
     * 映射脱离本地商品单独列出没有意义（列表页面场景一定是先选中一个本地商品）。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product_mapping.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        $productId = (int) $this->request->input('product_id');

        return $this->productMappingAdminService->listForProduct($productId);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product_mapping.manage')]
    #[PostMapping(path: '')]
    public function store(): array
    {
        $data = $this->request->all();
        $productId = (int) ($data['product_id'] ?? 0);

        return $this->productMappingAdminService->create($productId, $data)->toArray();
    }

    /**
     * 人工改价，requirements.md 6.4「成本价每次变化都记历史」——转发到
     * ProductMappingAdminService::updateCostPrice()，最终经
     * SupplierProductDao::updateCostPriceManually()（`source=manual`）写库，
     * 跟 ProductSyncService 的自动同步（`source=sync`）共用同一套改价必留痕逻辑。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product_mapping.manage')]
    #[PostMapping(path: '{id}/cost-price')]
    public function updateCostPrice(int $id): array
    {
        $this->productMappingAdminService->updateCostPrice($id, (string) $this->request->input('cost_price'));

        return ['success' => true];
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product_mapping.manage')]
    #[PostMapping(path: '{id}/priority')]
    public function updatePriority(int $id): array
    {
        $this->productMappingAdminService->updatePriority($id, (int) $this->request->input('priority'));

        return ['success' => true];
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product_mapping.manage')]
    #[PostMapping(path: '{id}/status')]
    public function setStatus(int $id): array
    {
        $this->productMappingAdminService->setStatus($id, $this->request->input('status'));

        return ['success' => true];
    }

    /**
     * 通用详情更新（supplier_product_code / stock / param_mapping / sale_restrictions），
     * 刻意不接受 cost_price/priority/status——那三个字段各自有专门的动作接口
     * （见 ProductMappingAdminService::updateDetails() 类注释）。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product_mapping.manage')]
    #[PutMapping(path: '{id}')]
    public function update(int $id): array
    {
        return $this->productMappingAdminService->updateDetails($id, $this->request->all())->toArray();
    }
}
