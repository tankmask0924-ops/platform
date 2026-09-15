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

namespace App\Auth;

use DomainException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

use function Hyperf\Support\env;

/**
 * 系统管理后台（web/admin）登录态：跟 App\Auth\MerchantJwtGuard 结构完全一致
 * （同一套 firebase/php-jwt、HS256、同样的 claim 形状），但读的是完全独立的
 * `.env` 密钥 `ADMIN_JWT_SECRET`，跟 MERCHANT_JWT_SECRET 不是同一个值。
 *
 * 这个密钥隔离是这两套鉴权系统之间真正的隔离机制：一个用 MerchantJwtGuard 签发的
 * token，拿到 AdminAuthMiddleware 这边用 AdminJwtGuard::resolve() 校验，签名永远
 * 验不过（不同密钥），反之亦然。不是靠 claim 里加一个 "aud"/"iss" 字段之类的应用层
 * 标记去区分身份，是密钥本身不同这件事保证了 token 无法跨系统重放
 * （见 test/Cases/Admin/AuthControllerTest.php 里的显式跨系统隔离测试）。
 *
 * TTL 选择：管理员账号权限比商户自助账号高（能看到全平台数据、后续会挂
 * 审核/调账/等级调整等敏感操作），会话应该比商户的 7 天短——这是本任务的判断，
 * 选 8 小时（一个工作日的量级），到期后重新登录，不需要更复杂的 refresh token
 * 机制（那属于「登出/主动吊销」这个更大话题的一部分，同 MerchantJwtGuard 类注释
 * 里记录的权衡，本任务同样不引入）。
 */
class AdminJwtGuard
{
    private const TTL_SECONDS = 8 * 3600;

    private const ALGO = 'HS256';

    /**
     * 签发一个新 JWT，claims：sub=admin_user_id（字符串，JWT 惯例）、iat=签发时间、exp=签发时间+TTL。
     */
    public function issue(int $adminUserId): string
    {
        $now = time();

        return JWT::encode([
            'sub' => (string) $adminUserId,
            'iat' => $now,
            'exp' => $now + self::TTL_SECONDS,
        ], $this->secret(), self::ALGO);
    }

    /**
     * 解码并校验 JWT（签名、过期时间、算法），返回 sub 里的 admin_user_id；
     * 任何失败（签名不对、已过期、格式不对、算法不对、sub 缺失或非数字）一律返回 null，
     * 不让 firebase/php-jwt 的异常逃逸到中间件里。
     */
    public function resolve(string $token): ?int
    {
        if ($token === '') {
            return null;
        }

        try {
            $payload = JWT::decode($token, new Key($this->secret(), self::ALGO));
        } catch (DomainException|ExpiredException|SignatureInvalidException|UnexpectedValueException) {
            // UnexpectedValueException（ExpiredException/SignatureInvalidException 是它的子类）
            // 覆盖签名不对、已过期、segment 数量不对、算法不支持等；DomainException
            // 单独捕获——它不是 UnexpectedValueException 的子类，是 firebase/php-jwt
            // 在 base64 解码出的内容不是合法 JSON（典型如乱码 token）时抛出的。
            return null;
        }

        return $this->extractAdminUserId($payload);
    }

    private function extractAdminUserId(stdClass $payload): ?int
    {
        $sub = $payload->sub ?? null;
        if (! is_string($sub) && ! is_int($sub)) {
            return null;
        }

        $sub = (string) $sub;
        if (! ctype_digit($sub)) {
            return null;
        }

        return (int) $sub;
    }

    private function secret(): string
    {
        $secret = env('ADMIN_JWT_SECRET');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('ADMIN_JWT_SECRET is not configured.');
        }

        return $secret;
    }
}
