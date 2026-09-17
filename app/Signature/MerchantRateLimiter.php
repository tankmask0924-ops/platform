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

namespace App\Signature;

use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;

/**
 * 开放 API 按商户限流的计数器（requirements.md 8.1）。跟 NonceGuard 一样是短生命周期的
 * 高频状态，故意不落 MySQL，走 Redis（database-design.md 5.6）；限流值这个「配置」
 * 另见 App\Service\Merchant\RateLimitSettingService。
 *
 * 算法是按自然秒的固定窗口：key 带上当前 Unix 秒，INCR 计数，第一次创建时设 2 秒过期。
 * INCR 和 EXPIRE 放在同一段 Lua 里原子执行，不会出现 INCR 成功但进程挂掉、key 永不
 * 过期的情况。固定窗口在两秒交界处最多可能放过 2 倍限额的突发，对「每秒 N 次」这种
 * 保护性限流足够，换来的是每次请求只有一次 O(1) 的 Redis 调用；超限的请求同样计数。
 */
class MerchantRateLimiter
{
    private const KEY_TTL_SECONDS = 2;

    private const SCRIPT = <<<'LUA'
local count = redis.call('INCR', KEYS[1])
if count == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return count
LUA;

    #[Inject]
    protected Redis $redis;

    /**
     * 本秒内该商户的请求数（含本次）不超过 $limitPerSecond 时返回 true。
     */
    public function attempt(int $merchantId, int $limitPerSecond): bool
    {
        $key = "open_api:rate_limit:{$merchantId}:{$this->currentSecond()}";
        $count = (int) $this->redis->eval(self::SCRIPT, [$key, self::KEY_TTL_SECONDS], 1);

        return $count <= $limitPerSecond;
    }

    /**
     * 单独拆出来，测试里可以固定到同一秒，避免几次请求恰好跨秒导致用例不稳定。
     */
    protected function currentSecond(): int
    {
        return time();
    }
}
