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

use App\Crypto\Encryptor;
use App\Dao\MerchantDao;
use App\Network\ClientIpResolver;
use App\Network\IpWhitelist;
use App\OpenApi\ApiResponse;
use App\OpenApi\ErrorCode;
use App\Service\Merchant\RateLimitSettingService;
use App\Signature\MerchantRateLimiter;
use App\Signature\NonceGuard;
use App\Signature\SignatureSigner;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * 开放 API 签名鉴权中间件（requirements.md 8.1）：校验 app_key/timestamp/nonce/sign、
 * 商户 IP 白名单，以及按商户限流。
 *
 * 校验顺序按 requirements.md 7.1 时序图「验签 / IP 白名单 / 防重放 / 限流」：
 * 验签通过后才查白名单（没有 AppSecret 的人无法借 40008/40005 的区别探测某个 app_key
 * 的白名单配置），白名单放在 timestamp/nonce 之前（被拒的请求不消耗 nonce）。
 * 白名单判定规则见 App\Network\IpWhitelist，客户端 IP 的取法（默认不信任
 * X-Forwarded-For，只有配置了 OPEN_API_TRUSTED_PROXIES 才按可信代理链解析）
 * 见 App\Network\ClientIpResolver。之所以放进这个中间件而不是单独再挂一个中间件：
 * 所有开放 API Controller 都已经挂了本中间件，合在一起不会出现「某个 Controller
 * 忘了挂白名单/限流中间件」的漏洞，也不用再按 app_key 重复查一次商户。
 *
 * 限流放在最后（防重放之后），只有通过全部鉴权的请求才计入商户的配额，伪造签名、
 * 重放的请求不会把正常商户的配额耗光。被限流的请求已经消耗了 nonce，商户重试时
 * 本来就要换新的 nonce/timestamp 重新签名。限流值取法见
 * App\Service\Merchant\RateLimitSettingService，计数器见 App\Signature\MerchantRateLimiter。
 * Redis 出错时放行并记 error 日志：限流是保护性措施，不值得因为它让所有商户下单失败，
 * 资金安全靠余额冻结和订单幂等，不靠限流。
 *
 * 不注册进 config/autoload/middlewares.php 的全局 http 中间件列表（会连带拦住
 * 跟开放 API 无关的 / 和 /users 路由），而是在开放 API Controller 上通过 #[Middleware] 类级注解
 * 按需挂载，例如：
 *   #[Controller(prefix: '/open-api')]
 *   #[Middleware(OpenApiSignatureMiddleware::class)]
 * 注意不能写成 #[Controller(options: ['middleware' => [...]])]——这个版本的
 * DispatcherFactory::handleController() 会把 Controller 注解 options 里的 middleware 键
 * 整个覆盖掉（只认 #[Middleware]/#[Middlewares] 注解），那样中间件会静默不生效
 * （细节见 app/Controller/OpenApi/BalanceController.php 的类注释）。
 * （`/open-api` 前缀约定见 app/Controller/OpenApi/BalanceController.php）
 *
 * 验签通过后会把查到的 Merchant 通过 `$request->withAttribute('merchant', $merchant)`
 * 传给下一个 handler，开放 API Controller 直接从请求属性里取，不用重新按 app_key 查一次。
 *
 * 失败时直接返回 {code, message, data} 信封，错误码和 HTTP 状态码见 App\OpenApi\ErrorCode
 * 的 400xx 段（缺参数/签名/时间戳/nonce 401，商户状态/密钥/白名单 403，限流 429）。
 */
class OpenApiSignatureMiddleware implements MiddlewareInterface
{
    private const TIMESTAMP_WINDOW_SECONDS = 300;

    #[Inject]
    protected SignatureSigner $signer;

    #[Inject]
    protected NonceGuard $nonceGuard;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected Encryptor $encryptor;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    #[Inject]
    protected RateLimitSettingService $rateLimitSettingService;

    #[Inject]
    protected MerchantRateLimiter $rateLimiter;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $params = (array) $request->getQueryParams() + (array) $request->getParsedBody();

        $appKey = $params['app_key'] ?? null;
        $timestamp = $params['timestamp'] ?? null;
        $nonce = $params['nonce'] ?? null;

        if (! is_string($appKey) || $appKey === ''
            || ! is_string($nonce) || $nonce === ''
            || ! is_numeric($timestamp)
        ) {
            return ApiResponse::error(ErrorCode::MissingAuthParams);
        }

        $merchant = $this->merchantDao->findByAppKey($appKey);
        if (! $merchant) {
            return ApiResponse::error(ErrorCode::UnknownAppKey);
        }

        if ($merchant->status !== 'active') {
            return ApiResponse::error(ErrorCode::MerchantNotActive);
        }

        if (! is_string($merchant->app_secret) || $merchant->app_secret === '') {
            return ApiResponse::error(ErrorCode::AppSecretNotConfigured);
        }

        $secret = $this->encryptor->decrypt($merchant->app_secret);

        if (! $this->signer->verify($params, $secret)) {
            return ApiResponse::error(ErrorCode::InvalidSignature);
        }

        if (IpWhitelist::isConfigured($merchant->ip_whitelist)) {
            $clientIp = $this->clientIpResolver->resolve($request);
            if (! IpWhitelist::allows($merchant->ip_whitelist, $clientIp)) {
                $this->loggerFactory->get('open_api')->warning('open api request rejected by ip whitelist', [
                    'merchant_id' => $merchant->id,
                    'client_ip' => $clientIp,
                ]);

                // 响应里不回显白名单内容，也不回显解析出的 IP，避免泄露配置/代理拓扑
                return ApiResponse::error(ErrorCode::IpNotAllowed);
            }
        }

        if (abs(time() - (int) $timestamp) > self::TIMESTAMP_WINDOW_SECONDS) {
            return ApiResponse::error(ErrorCode::TimestampOutOfWindow);
        }

        if (! $this->nonceGuard->consume($appKey, $nonce)) {
            return ApiResponse::error(ErrorCode::NonceReplayed);
        }

        if (! $this->withinRateLimit($merchant->id)) {
            return ApiResponse::error(ErrorCode::RateLimited);
        }

        return $handler->handle($request->withAttribute('merchant', $merchant));
    }

    private function withinRateLimit(int $merchantId): bool
    {
        try {
            $limit = $this->rateLimitSettingService->effectiveLimit($merchantId)['limit_per_second'];

            return $this->rateLimiter->attempt($merchantId, $limit);
        } catch (Throwable $e) {
            $this->loggerFactory->get('open_api')->error('open api rate limit check failed, request allowed', [
                'merchant_id' => $merchantId,
                'exception' => $e->getMessage(),
            ]);

            return true;
        }
    }
}
