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

namespace HyperfTest\Cases\Signature;

use App\Signature\MerchantRateLimiter;
use Hyperf\Redis\Redis;
use Hyperf\Testing\TestCase;
use ReflectionProperty;

use function Hyperf\Support\make;

/**
 * 走真实 Redis。商户 id 用一个远超真实数据的随机数，时间固定到同一秒，
 * 各用例之间、跟真实请求之间都不会共用计数 key。
 *
 * @internal
 * @coversNothing
 */
class MerchantRateLimiterTest extends TestCase
{
    public function testAllowsUpToLimitThenRejectsWithinSameSecond()
    {
        $limiter = $this->limiterAt(1_000_000);
        $merchantId = $this->randomMerchantId();

        $this->assertTrue($limiter->attempt($merchantId, 3));
        $this->assertTrue($limiter->attempt($merchantId, 3));
        $this->assertTrue($limiter->attempt($merchantId, 3));
        $this->assertFalse($limiter->attempt($merchantId, 3));
        $this->assertFalse($limiter->attempt($merchantId, 3));
    }

    public function testMerchantsAreCountedIndependently()
    {
        $limiter = $this->limiterAt(1_000_000);
        $a = $this->randomMerchantId();
        $b = $a + 1;

        $this->assertTrue($limiter->attempt($a, 1));
        $this->assertFalse($limiter->attempt($a, 1));
        $this->assertTrue($limiter->attempt($b, 1));
    }

    public function testNextSecondStartsANewWindow()
    {
        $merchantId = $this->randomMerchantId();

        $first = $this->limiterAt(1_000_000);
        $this->assertTrue($first->attempt($merchantId, 1));
        $this->assertFalse($first->attempt($merchantId, 1));

        $this->assertTrue($this->limiterAt(1_000_001)->attempt($merchantId, 1));
    }

    public function testCounterKeyExpiresShortly()
    {
        $merchantId = $this->randomMerchantId();
        $this->limiterAt(1_000_000)->attempt($merchantId, 10);

        $ttl = make(Redis::class)->ttl("open_api:rate_limit:{$merchantId}:1000000");

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(2, $ttl);
    }

    private function limiterAt(int $second): MerchantRateLimiter
    {
        $limiter = new class extends MerchantRateLimiter {
            public int $second = 0;

            protected function currentSecond(): int
            {
                return $this->second;
            }
        };
        $limiter->second = $second;
        (new ReflectionProperty(MerchantRateLimiter::class, 'redis'))->setValue($limiter, make(Redis::class));

        return $limiter;
    }

    private function randomMerchantId(): int
    {
        return random_int(900_000_000, 999_999_999);
    }
}
