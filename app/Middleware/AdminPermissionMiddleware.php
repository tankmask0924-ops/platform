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

use App\Annotation\RequiresPermission;
use App\Dao\AdminRolePermissionDao;
use App\Model\AdminUser;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\HttpServer\Router\Dispatched;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionMethod;

/**
 * requirements.md 8.3「校验 admin_users.role_id 对应 admin_permissions」的落地，
 * docs/modules.md 第 1 节「后台角色权限中间件」这一行。
 *
 * 必须排在 App\Middleware\AdminAuthMiddleware 之后（依赖它已经把 AdminUser 通过
 * `$request->withAttribute('admin', $adminUser)` 放进 request）；两个中间件都以
 * 方法级 `#[Middleware(...)]` 注解挂在同一个 Controller 方法上，顺序由代码里
 * 注解的书写顺序决定——同优先级的 #[Middleware] 在 Hyperf\HttpServer\MiddlewareManager::
 * sortMiddlewares() 里用 Hyperf\Stdlib\SplPriorityQueue 排队，该类文档明确写了
 * 「相同优先级按插入顺序（FIFO）出队」，而 handleController() 里方法级中间件正是
 * 按 #[Middleware] 出现顺序被收集进 handleMiddleware() 的（Hyperf\Di\Annotation\
 * AbstractMultipleAnnotation 按反射到的 attribute 顺序 insert）。所以只要
 * #[Middleware(AdminAuthMiddleware::class)] 写在 #[Middleware(AdminPermissionMiddleware::class)]
 * 前面，就能保证鉴权先跑；这不是纯靠读源码推断，
 * test/Cases/Admin/MerchantControllerTest.php 里
 * testNoTokenAtAllReturns401NotAPermissionError() 是对这个顺序的真实 HTTP 端到端验证：
 * 如果顺序反了，没有 token 时权限中间件会先跑、在拿不到 'admin' attribute 的情况下
 * 直接 500，而不是这里断言的 401。
 *
 * 用反射从 `Hyperf\HttpServer\Router\Dispatched::$handler->callback`（形如
 * `[ControllerClass::class, 'methodName']`，见 vendor/hyperf/http-server/src/Router/
 * Handler.php + DispatcherFactory::handleController() 里 `$router->addRoute(...,
 * [$className, $methodName], ...)`）取出匹配到的方法，读它身上的
 * `#[RequiresPermission]` attribute：
 * - 没有这个 attribute：说明这个动作没有细分权限要求，登录（AdminAuthMiddleware
 *   已经保证）即可访问，直接放行——`/admin/auth/me` 这类「任何认证管理员都能看
 *   自己信息」的路由就是这种情况，本任务选择不给它挂 #[RequiresPermission]，
 *   也就不需要在这类路由上额外挂本中间件（挂不挂效果一样，都是放行，
 *   但没有需求就不多挂，保持“需要权限校验的路由才挂 AdminPermissionMiddleware”
 *   这条一致的规则）。
 * - 有这个 attribute：查 AdminUser.role_id 是否拥有该 code
 *   （App\Dao\AdminRolePermissionDao::roleHasPermission()），没有则 403。
 */
class AdminPermissionMiddleware implements MiddlewareInterface
{
    #[Inject]
    protected AdminRolePermissionDao $adminRolePermissionDao;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requiredCode = $this->resolveRequiredPermissionCode($request);
        if ($requiredCode !== null) {
            /** @var AdminUser $admin */
            $admin = $request->getAttribute('admin');

            if (! $this->adminRolePermissionDao->roleHasPermission($admin->role_id, $requiredCode)) {
                throw new HttpException(403, '无权限访问');
            }
        }

        return $handler->handle($request);
    }

    private function resolveRequiredPermissionCode(ServerRequestInterface $request): ?string
    {
        $dispatched = $request->getAttribute(Dispatched::class);
        if (! $dispatched instanceof Dispatched || ! $dispatched->handler) {
            return null;
        }

        $callback = $dispatched->handler->callback;
        if (! is_array($callback) || count($callback) !== 2) {
            return null;
        }

        [$class, $method] = $callback;
        if (! is_string($class) || ! is_string($method) || ! method_exists($class, $method)) {
            return null;
        }

        $attributes = (new ReflectionMethod($class, $method))->getAttributes(RequiresPermission::class);
        if ($attributes === []) {
            return null;
        }

        /** @var RequiresPermission $requiresPermission */
        $requiresPermission = $attributes[0]->newInstance();

        return $requiresPermission->code;
    }
}
