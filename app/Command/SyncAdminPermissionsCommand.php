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

namespace App\Command;

use App\Service\Admin\AdminBootstrapService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;

/**
 * 把代码里新增的权限编码补给超级管理员角色：
 * `docker exec pf php bin/hyperf.php admin:sync-permissions`。
 *
 * 每次上线带了新权限编码（AdminBootstrapService::KNOWN_PERMISSIONS 有新增）之后执行
 * 一次，已有的超级管理员账号才能访问新接口；可重复执行，没有缺的就什么都不做。
 * 逻辑在 AdminBootstrapService::syncSuperAdminPermissions()。
 */
#[Command]
class SyncAdminPermissionsCommand extends HyperfCommand
{
    public function __construct(private readonly AdminBootstrapService $adminBootstrapService)
    {
        parent::__construct('admin:sync-permissions');
    }

    public function configure(): void
    {
        $this->setDescription('把代码中已知的全部权限同步给超级管理员角色，并补建缺少的预置角色（幂等）');

        parent::configure();
    }

    public function handle(): int
    {
        $granted = $this->adminBootstrapService->syncSuperAdminPermissions();
        if ($granted === null) {
            $this->warn('还没有超级管理员角色，请先用 admin:create 创建管理员账号（会自动带上全部权限）');

            return self::SUCCESS;
        }

        $this->info($granted > 0 ? "已为超级管理员补充 {$granted} 个权限" : '超级管理员权限已是最新，无需补充');

        return self::SUCCESS;
    }
}
