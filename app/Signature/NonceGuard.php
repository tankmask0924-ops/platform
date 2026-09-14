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
 * 开放 API 防重放（requirements.md 8.1）：同一个 nonce 5 分钟内不能重复。
 * 状态是短生命周期的一次性标记，故意不落 MySQL，走 Redis（database-design.md 5.6）。
 */
class NonceGuard
{
    private const TTL_SECONDS = 300;

    #[Inject]
    protected Redis $redis;

    /**
     * 第一次看到这个 nonce 返回 true 并记下来；5 分钟内再次出现返回 false。
     */
    public function consume(string $appKey, string $nonce): bool
    {
        $key = "open_api:nonce:{$appKey}:{$nonce}";

        return (bool) $this->redis->set($key, '1', ['nx', 'ex' => self::TTL_SECONDS]);
    }
}
