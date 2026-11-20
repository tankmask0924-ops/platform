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
use App\Service\Storage\CloudStorageService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\HttpMessage\Upload\UploadedFile;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 后台上传图片到云存储（multipart 字段名 `file`），返回公开地址。目前只有商品图片用，权限跟着 product.manage。
 */
#[Controller(prefix: '/admin/uploads')]
class UploadController extends AbstractController
{
    #[Inject]
    protected CloudStorageService $cloudStorageService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('product.manage')]
    #[PostMapping(path: 'image')]
    public function image(): array
    {
        $file = $this->request->file('file');
        if (! $file instanceof UploadedFile) {
            throw new HttpException(422, '请选择要上传的图片（字段名 file）');
        }

        return $this->cloudStorageService->uploadImage($file);
    }
}
