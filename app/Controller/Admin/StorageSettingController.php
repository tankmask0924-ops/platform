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
use App\Network\ClientIpResolver;
use App\Service\Admin\StorageSettingAdminService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 系统设置 - 云存储（阿里云 OSS / 腾讯云 COS），权限沿用系统参数的 setting.view / setting.manage。
 * 单独一个前缀，不挂在 /admin/settings/{key} 下面，免得跟系统参数的 key 路由混在一起。
 */
#[Controller(prefix: '/admin/storage-settings')]
class StorageSettingController extends AbstractController
{
    #[Inject]
    protected StorageSettingAdminService $storageSettingAdminService;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('setting.view')]
    #[GetMapping(path: '')]
    public function show(): array
    {
        return $this->storageSettingAdminService->show();
    }

    /**
     * body：driver/bucket/region/access_key_id/access_key_secret/domain/path_prefix，access_key_secret 留空表示不改。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('setting.manage')]
    #[PutMapping(path: '')]
    public function update(): array
    {
        /** @var AdminUser $admin */
        $admin = $this->request->getAttribute('admin');

        return $this->storageSettingAdminService->update($admin, $this->request->all(), $this->clientIpResolver->resolve($this->request));
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('setting.manage')]
    #[PostMapping(path: 'test')]
    public function test(): array
    {
        return $this->storageSettingAdminService->test();
    }
}
