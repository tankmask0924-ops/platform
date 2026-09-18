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

namespace App\Middleware;

use App\Auth\AdminJwtGuard;
use App\Dao\AdminUserDao;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 系统管理后台（web/admin）登录态鉴权中间件，跟 App\Middleware\MerchantAuthMiddleware
 * 结构完全一致，但读的是 App\Auth\AdminJwtGuard（独立密钥 ADMIN_JWT_SECRET）和
 * App\Dao\AdminUserDao——两套系统除了都用「Bearer JWT」这个形状之外，密钥、
 * Guard 类、Dao、挂载的 request attribute 名字（'admin' 而不是 'merchant'）
 * 全部独立，一个系统签发的 token 不会被另一个系统的中间件接受
 * （见 test/Cases/Admin/AuthControllerTest.php 的跨系统隔离测试）。
 *
 * 只在需要登录态的方法上挂 `#[Middleware(AdminAuthMiddleware::class)]`（方法级注解）。
 * 不能用 `#[Controller(options: ['middleware' => [...]])]`——这个版本的
 * DispatcherFactory::handleController() 会把它整个覆盖掉，见
 * app/Controller/OpenApi/BalanceController.php 类注释里记录的坑。
 *
 * 需要跟 App\Middleware\AdminPermissionMiddleware 一起挂的路由，这个中间件必须
 * 排在前面（先鉴权、把 'admin' attribute 放进 request，后面的权限中间件才能读到），
 * 顺序靠 #[Middleware] 注解在代码里的书写顺序决定（同优先级时 Hyperf\Stdlib\SplPriorityQueue
 * 按插入顺序 FIFO 出队，见 vendor/hyperf/stdlib/src/SplPriorityQueue.php），
 * 具体验证见 App\Controller\Admin\MerchantController 类注释 + 对应 HTTP 测试。
 */
class AdminAuthMiddleware implements MiddlewareInterface
{
    #[Inject]
    protected AdminJwtGuard $tokenGuard;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (! str_starts_with($header, 'Bearer ')) {
            throw new HttpException(401, '未登录或登录已过期');
        }

        $token = trim(substr($header, strlen('Bearer ')));
        if ($token === '') {
            throw new HttpException(401, '未登录或登录已过期');
        }

        $claims = $this->tokenGuard->resolve($token);
        if ($claims === null) {
            throw new HttpException(401, '未登录或登录已过期');
        }

        $adminUser = $this->adminUserDao->find($claims['id']);
        if (! $adminUser || $adminUser->status !== 'active') {
            throw new HttpException(401, '未登录或登录已过期');
        }

        // 改过密码（自己改或被其他管理员重置）之后，之前签发的 token 一律失效
        if (! hash_equals($this->tokenGuard->passwordVersion($adminUser->password), $claims['pv'])) {
            throw new HttpException(401, '密码已修改，请重新登录');
        }

        return $handler->handle($request->withAttribute('admin', $adminUser));
    }
}
