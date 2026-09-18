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

use App\Auth\MerchantJwtGuard;
use App\Dao\MerchantDao;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 商户管理后台（web/merchant）登录态鉴权中间件，跟开放 API 的
 * App\Middleware\OpenApiSignatureMiddleware 是两套完全独立的东西：这里是给人类浏览器
 * 会话用的 `Authorization: Bearer <token>`，不是服务器间的 HMAC 签名。token 本身是
 * HS256 签名的 JWT（App\Auth\MerchantJwtGuard），不是早期版本里的 Redis 不透明随机 token。
 *
 * 前端（web/shared/src/http.ts）用的是 axios，拿到 HTTP 401 会清本地登录态并跳转 /login，
 * 依赖的是真实 HTTP 状态码而不是响应体里的 code 字段（那是开放 API 的信封约定，这里不适用）。
 * 所以这里失败直接 throw HttpException(401, ...)，交给已经注册好的
 * Hyperf\HttpServer\Exception\Handler\HttpExceptionHandler（config/autoload/exceptions.php）
 * 转成纯文本 401 响应，不用再造一套异常处理基础设施。
 *
 * 只在需要登录态的方法上挂 `#[Middleware(MerchantAuthMiddleware::class)]`（方法级注解，
 * 这个版本的 Hyperf `#[Middleware]` 同时支持 TARGET_CLASS 和 TARGET_METHOD，
 * 见 vendor/hyperf/http-server/src/Annotation/Middleware.php），register/login 之类
 * 公开接口不挂。不能用 `#[Controller(options: ['middleware' => [...]])]`——这个版本的
 * DispatcherFactory::handleController() 会把它整个覆盖掉，见
 * app/Controller/OpenApi/BalanceController.php 类注释里记录的坑。
 */
class MerchantAuthMiddleware implements MiddlewareInterface
{
    #[Inject]
    protected MerchantJwtGuard $tokenGuard;

    #[Inject]
    protected MerchantDao $merchantDao;

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

        $merchant = $this->merchantDao->find($claims['id']);
        if (! $merchant) {
            throw new HttpException(401, '未登录或登录已过期');
        }

        // 改过密码（包括找回密码）之后，之前签发的 token 一律失效
        if (! hash_equals($this->tokenGuard->passwordVersion($merchant->password), $claims['pv'])) {
            throw new HttpException(401, '密码已修改，请重新登录');
        }

        // 后台禁用商户（App\Service\Admin\MerchantAdminService::changeStatus()）之前
        // 签发的 token 在 7 天 TTL 内仍然验签通过，必须在这里按实时状态拦下，否则
        // 禁用只挡得住重新登录。用 401 而不是登录接口用的 403：前端见 401 会清掉
        // 登录态跳回登录页，再登录时就能看到登录接口给出的「账号已被禁用」。
        // pending/rejected 商户仍需进后台看审核状态、重新提交资质，不拦。
        if ($merchant->status === 'disabled') {
            throw new HttpException(401, '账号已被禁用');
        }

        return $handler->handle($request->withAttribute('merchant', $merchant));
    }
}
