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
use App\Service\Admin\SupplierGoodsAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 系统管理后台「供应商商品库」（只读，数据来自商品同步）。新增本地商品、添加映射时
 * 从这里选，所以跟商品映射共用 `product_mapping.view` 权限。
 */
#[Controller(prefix: '/admin/supplier-goods')]
class SupplierGoodsController extends AbstractController
{
    #[Inject]
    protected SupplierGoodsAdminService $supplierGoodsAdminService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product_mapping.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        $supplierId = $this->request->input('supplier_id');
        $businessLine = $this->request->input('business_line');
        $keyword = $this->request->input('keyword');
        $status = $this->request->input('status');
        $goodsType = $this->request->input('goods_type');

        return $this->supplierGoodsAdminService->list(
            (int) $this->request->input('page', 1),
            (int) $this->request->input('per_page', 20),
            $supplierId !== null && $supplierId !== '' ? (int) $supplierId : null,
            $businessLine !== null && $businessLine !== '' ? (string) $businessLine : null,
            $keyword !== null ? (string) $keyword : null,
            $status !== null && $status !== '' ? (string) $status : null,
            $goodsType !== null && $goodsType !== '' ? (string) $goodsType : null
        );
    }
}
