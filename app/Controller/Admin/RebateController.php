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
use App\Service\Product\RebateQueryService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 系统后台「返佣管理 - 商户返佣明细」（requirements.md 8.3），只读。
 * 返佣固定期限在系统参数里改；供应商返佣明细（电影票、快递）三期随业务线一起做。
 */
#[Controller(prefix: '/admin/rebates')]
class RebateController extends AbstractController
{
    #[Inject]
    protected RebateQueryService $rebateQueryService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('rebate.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->rebateQueryService->listForAdmin($this->request->all());
    }
}
