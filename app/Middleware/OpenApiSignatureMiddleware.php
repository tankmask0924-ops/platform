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
use App\Signature\NonceGuard;
use App\Signature\SignatureSigner;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Base\Response;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 开放 API 签名鉴权中间件（requirements.md 8.1）：校验 app_key/timestamp/nonce/sign，
 * IP 白名单和按商户限流不在这个中间件的职责范围内（见 docs/modules.md 对应行）。
 *
 * 还没有开放 API 的 Controller 可以挂载它，所以暂时不注册进
 * config/autoload/middlewares.php 的全局 http 中间件列表（会连带拦住
 * 跟开放 API 无关的 / 和 /users 路由）。等第 6 节的开放 API 接口落地后，
 * 在对应 Controller 上通过注解挂载，例如：
 *   #[Controller(prefix: '/open', options: ['middleware' => [OpenApiSignatureMiddleware::class]])]
 *
 * 统一返回格式（{code, message, data}）还没有平台级方案（另一个 ⬜ 任务），
 * 这里失败时直接返回同样形状的 JSON，错误码是本中间件内部的占位编号，
 * 不是平台统一错误码：
 *   40001 缺少必要参数（app_key/timestamp/nonce）
 *   40002 app_key 不存在
 *   40003 商户状态不是 active
 *   40004 商户尚未配置 app_secret
 *   40005 签名校验失败
 *   40006 timestamp 超出 ±5 分钟窗口
 *   40007 nonce 重复（重放）
 */
class OpenApiSignatureMiddleware implements MiddlewareInterface
{
    private const TIMESTAMP_WINDOW_SECONDS = 300;

    private const CODE_MISSING_PARAMS = 40001;

    private const CODE_UNKNOWN_APP_KEY = 40002;

    private const CODE_MERCHANT_NOT_ACTIVE = 40003;

    private const CODE_APP_SECRET_NOT_CONFIGURED = 40004;

    private const CODE_INVALID_SIGNATURE = 40005;

    private const CODE_TIMESTAMP_OUT_OF_WINDOW = 40006;

    private const CODE_NONCE_REPLAYED = 40007;

    #[Inject]
    protected SignatureSigner $signer;

    #[Inject]
    protected NonceGuard $nonceGuard;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected Encryptor $encryptor;

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
            return $this->reject(self::CODE_MISSING_PARAMS, 'missing or invalid app_key/timestamp/nonce');
        }

        $merchant = $this->merchantDao->findByAppKey($appKey);
        if (! $merchant) {
            return $this->reject(self::CODE_UNKNOWN_APP_KEY, 'unknown app_key', 401);
        }

        if ($merchant->status !== 'active') {
            return $this->reject(self::CODE_MERCHANT_NOT_ACTIVE, 'merchant is not active', 403);
        }

        if (! is_string($merchant->app_secret) || $merchant->app_secret === '') {
            return $this->reject(self::CODE_APP_SECRET_NOT_CONFIGURED, 'app_secret is not configured', 403);
        }

        $secret = $this->encryptor->decrypt($merchant->app_secret);

        if (! $this->signer->verify($params, $secret)) {
            return $this->reject(self::CODE_INVALID_SIGNATURE, 'invalid signature', 401);
        }

        if (abs(time() - (int) $timestamp) > self::TIMESTAMP_WINDOW_SECONDS) {
            return $this->reject(self::CODE_TIMESTAMP_OUT_OF_WINDOW, 'timestamp out of window', 401);
        }

        if (! $this->nonceGuard->consume($appKey, $nonce)) {
            return $this->reject(self::CODE_NONCE_REPLAYED, 'nonce replayed', 401);
        }

        return $handler->handle($request);
    }

    private function reject(int $code, string $message, int $status = 400): ResponseInterface
    {
        $body = (string) json_encode(['code' => $code, 'message' => $message, 'data' => null], JSON_UNESCAPED_UNICODE);

        return (new Response())
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new SwooleStream($body));
    }
}
