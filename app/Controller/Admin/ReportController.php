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
use App\Service\Admin\FinanceReportService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 系统管理后台「财务报表」（requirements.md 8.3）。两个只读接口：订单毛利与返佣收支
 * （按天/商户/等级/业务线/供应商分组）、资金流水汇总。口径见
 * App\Service\Admin\FinanceReportService 类注释。
 *
 * 只有一个权限编码 `report.view`：报表全是只读聚合，没有写操作可分档。
 * 记得同步维护 App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS。
 */
#[Controller(prefix: '/admin/reports')]
class ReportController extends AbstractController
{
    #[Inject]
    protected FinanceReportService $financeReportService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('report.view')]
    #[GetMapping(path: 'profit')]
    public function profit(): array
    {
        return $this->financeReportService->profit($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('report.view')]
    #[GetMapping(path: 'balance-flows')]
    public function balanceFlows(): array
    {
        return $this->financeReportService->balanceFlows($this->request->all());
    }
}
