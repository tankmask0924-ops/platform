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
use App\Service\Admin\OperationLogAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 系统设置 - 操作日志（requirements.md 8.3）。
 */
#[Controller(prefix: '/admin/operation-logs')]
class OperationLogController extends AbstractController
{
    #[Inject]
    protected OperationLogAdminService $operationLogAdminService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('operation_log.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->operationLogAdminService->list($this->request->all());
    }
}
