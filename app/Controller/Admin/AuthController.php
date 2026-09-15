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

use App\Controller\AbstractController;
use App\Middleware\AdminAuthMiddleware;
use App\Model\AdminUser;
use App\Service\Admin\AuthService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 系统管理后台（web/admin）账户接口（requirements.md 8.3），前端契约见
 * web/admin/src/api/auth.ts 的 LoginResult 形状。
 *
 * 跟 App\Controller\Merchant\AuthController 同样是普通 JSON body（不是开放 API 的
 * {code,message,data} 信封），鉴权失败靠 HttpException 抛真实 HTTP 状态码。
 *
 * `me` 用方法级 #[Middleware(AdminAuthMiddleware::class)] 单独挂载，login 不挂，
 * 保持公开——没有 register：管理员账号不是自助注册的，见 App\Service\Admin\AuthService
 * 类注释。`me` 没有挂 #[RequiresPermission]，所以也不挂
 * App\Middleware\AdminPermissionMiddleware：任何登录管理员都能看自己的信息，
 * 不需要按角色权限细分（见 AdminPermissionMiddleware 类注释「没有 #[RequiresPermission]
 * 的路由不需要挂这个中间件」）。
 */
#[Controller(prefix: '/admin/auth')]
class AuthController extends AbstractController
{
    #[Inject]
    protected AuthService $authService;

    #[PostMapping(path: 'login')]
    public function login(): array
    {
        $username = (string) $this->request->input('username', '');
        $password = (string) $this->request->input('password', '');

        return $this->authService->login($username, $password);
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[GetMapping(path: 'me')]
    public function me(): array
    {
        /** @var AdminUser $admin */
        $admin = $this->request->getAttribute('admin');

        return [
            'id' => $admin->id,
            'username' => $admin->username,
            'real_name' => $admin->real_name,
            'role_id' => $admin->role_id,
            'status' => $admin->status,
        ];
    }
}
