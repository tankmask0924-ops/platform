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
 * 商户管理后台（web/merchant）登录态：应用方（用户）明确要求把上一版的
 * Redis-backed 不透明随机 token（见已删除的 App\Auth\MerchantTokenGuard，
 * git 历史 commit 2a1b849/00c2358）换成 JWT，这是一次明确的产品决策，不是本任务的判断。
 *
 * 用 firebase/php-jwt，HS256 签名，密钥从 `.env` 的 MERCHANT_JWT_SECRET 读取，
 * 读法跟 App\Crypto\Encryptor 读 APP_ENCRYPTION_KEY 是同一个模式（Hyperf\Support\env()）。
 *
 * 重要权衡（这是这次切换的真实代价，不是纯粹的收益）：JWT 是自包含、无状态的——
 * 签发之后服务端不再有一条可以删除的记录。旧的 Redis token 方案下「登出」只需要
 * `DEL` 对应的 key；换成 JWT 后，**在 `exp` 到期之前没有办法让某一个已签发的 token
 * 提前失效**，登出/主动吊销因此比之前更难做，而不是同样难。要在之后补上这个能力，
 * 需要引入下面两种方案之一（本任务范围外，均未实现）：
 * 1. 短生命周期 JWT + refresh token 双 token 机制；
 * 2. 在 token 里加一个 `jti` claim，配合 Redis 维护一个吊销黑名单（跟当前
 *    NonceGuard/旧 MerchantTokenGuard 一样的 Redis-backed 短状态风格）。
 * 登出本身在旧方案里就已经是 out of scope，这里只是把「以后要补登出，该怎么补」
 * 写清楚，因为切到 JWT 之后这件事的实现方式变了、也变得更必要了。
 *
 * TTL 沿用旧 MerchantTokenGuard 的 7 天，没有改变的理由——这是给人用的登录态，
 * 到期后重新登录即可，本任务没有引入新的 TTL 需求。
 */
class MerchantJwtGuard
{
    private const TTL_SECONDS = 7 * 86400;

    private const ALGO = 'HS256';

    /**
     * 签发一个新 JWT，claims：sub=merchant_id（字符串，JWT 惯例）、iat=签发时间、exp=签发时间+TTL、
     * pv=密码版本（见 passwordVersion()），改密码后旧 token 的 pv 对不上，中间件按失效处理。
     */
    public function issue(int $merchantId, string $passwordHash): string
    {
        $now = time();

        return JWT::encode([
            'sub' => (string) $merchantId,
            'pv' => $this->passwordVersion($passwordHash),
            'iat' => $now,
            'exp' => $now + self::TTL_SECONDS,
        ], $this->secret(), self::ALGO);
    }

    /**
     * 解码并校验 JWT（签名、过期时间、算法），返回 sub 里的 merchant_id 和 pv；
     * 任何失败（签名不对、已过期、格式不对、算法不对、sub 缺失或非数字、pv 缺失）一律返回 null，
     * 不让 firebase/php-jwt 的异常逃逸到中间件里。没有 pv 的旧 token 也按无效处理。
     *
     * @return null|array{id: int, pv: string}
     */
    public function resolve(string $token): ?array
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

        $id = $this->extractMerchantId($payload);
        $pv = $payload->pv ?? null;
        if ($id === null || ! is_string($pv)) {
            return null;
        }

        return ['id' => $id, 'pv' => $pv];
    }

    /**
     * 密码哈希的指纹，放进 token 里；用 HMAC 而不是直接截取哈希，JWT 载荷是明文可读的，
     * 不能把密码哈希的任何片段暴露出去。
     */
    public function passwordVersion(string $passwordHash): string
    {
        return substr(hash_hmac('sha256', $passwordHash, $this->secret()), 0, 16);
    }

    private function extractMerchantId(stdClass $payload): ?int
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
        $secret = env('MERCHANT_JWT_SECRET');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('MERCHANT_JWT_SECRET is not configured.');
        }

        return $secret;
    }
}
