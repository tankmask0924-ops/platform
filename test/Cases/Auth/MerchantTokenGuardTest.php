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

namespace HyperfTest\Cases\Auth;

use App\Auth\MerchantTokenGuard;
use Hyperf\Testing\TestCase;

/**
 * 跟 test/Cases/Signature/NonceGuardTest.php 一样，针对真实 Redis 跑（不是 mock）。
 *
 * @internal
 * @coversNothing
 */
class MerchantTokenGuardTest extends TestCase
{
    public function testIssueThenResolveRoundTripReturnsMerchantId()
    {
        $guard = $this->getContainer()->get(MerchantTokenGuard::class);
        $merchantId = random_int(100000, 999999);

        $token = $guard->issue($merchantId);

        $this->assertNotSame('', $token);
        $this->assertSame($merchantId, $guard->resolve($token));
    }

    public function testResolvingATokenThatWasNeverIssuedReturnsNull()
    {
        $guard = $this->getContainer()->get(MerchantTokenGuard::class);

        $this->assertNull($guard->resolve('never-issued-token-' . uniqid('', true)));
    }
}
