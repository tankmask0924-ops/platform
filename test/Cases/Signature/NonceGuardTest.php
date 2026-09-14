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

use App\Signature\NonceGuard;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class NonceGuardTest extends TestCase
{
    public function testFirstUseIsAccepted()
    {
        $guard = $this->getContainer()->get(NonceGuard::class);
        $nonce = uniqid('nonce_', true);

        $this->assertTrue($guard->consume('app_key_a', $nonce));
    }

    public function testReplayWithinWindowIsRejected()
    {
        $guard = $this->getContainer()->get(NonceGuard::class);
        $nonce = uniqid('nonce_', true);

        $this->assertTrue($guard->consume('app_key_a', $nonce));
        $this->assertFalse($guard->consume('app_key_a', $nonce));
    }

    public function testSameNonceUnderDifferentAppKeyIsIndependent()
    {
        $guard = $this->getContainer()->get(NonceGuard::class);
        $nonce = uniqid('nonce_', true);

        $this->assertTrue($guard->consume('app_key_a', $nonce));
        $this->assertTrue($guard->consume('app_key_b', $nonce));
    }
}
