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

use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;

/**
 * 商户管理后台（web/merchant）登录态：不是开放 API 的 HMAC 签名鉴权，是给人类浏览器
 * 会话用的不透明随机 token，风格上跟 App\Signature\NonceGuard 一样是 Redis-backed，
 * 状态短生命周期、不落 MySQL。
 *
 * 项目里没有装 JWT 库，也不需要装——一个随机 token 映射到 merchant_id，跟 session id
 * 是一回事，没有 JWT 那种「客户端可自解析」的需求。
 *
 * TTL 定 7 天，纯粹是个折中：比开放 API 场景的 5 分钟量级长得多（这是给人用的登录态，
 * 不是防重放窗口），但也没长到需要考虑滑动续期；到期后商户重新登录即可，本任务范围内
 * 没有「记住我」或续期需求。
 *
 * 登出/主动吊销 token 是明确的后续任务（out of scope）：会需要在这里加一个
 * `revoke(string $token): void` 方法，内部 `DEL` 掉对应的 key。
 */
class MerchantTokenGuard
{
    private const TTL_SECONDS = 7 * 86400;

    #[Inject]
    protected Redis $redis;

    /**
     * 签发一个新 token 并记录 merchant_id 映射，返回 token 原文。
     */
    public function issue(int $merchantId): string
    {
        $token = bin2hex(random_bytes(32));
        $key = $this->key($token);

        $this->redis->set($key, (string) $merchantId, ['ex' => self::TTL_SECONDS]);

        return $token;
    }

    /**
     * 解析 token，返回对应的 merchant_id；token 不存在或已过期返回 null。
     */
    public function resolve(string $token): ?int
    {
        if ($token === '') {
            return null;
        }

        $value = $this->redis->get($this->key($token));
        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function key(string $token): string
    {
        return "merchant_portal:token:{$token}";
    }
}
