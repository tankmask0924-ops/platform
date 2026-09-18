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

use App\Auth\AdminJwtGuard;
use Firebase\JWT\JWT;
use Hyperf\Testing\TestCase;

use function Hyperf\Support\env;

/**
 * 跟 test/Cases/Auth/MerchantJwtGuardTest.php 逐条对应——AdminJwtGuard 和
 * MerchantJwtGuard 结构完全一致，唯一不同是读的密钥（ADMIN_JWT_SECRET）。
 *
 * @internal
 * @coversNothing
 */
class AdminJwtGuardTest extends TestCase
{
    public function testIssueThenResolveRoundTripReturnsAdminUserId()
    {
        $guard = $this->getContainer()->get(AdminJwtGuard::class);
        $adminUserId = random_int(100000, 999999);

        $token = $guard->issue($adminUserId, 'password-hash');

        $this->assertNotSame('', $token);
        $this->assertSame(['id' => $adminUserId, 'pv' => $guard->passwordVersion('password-hash')], $guard->resolve($token));
        $this->assertNotSame($guard->passwordVersion('password-hash'), $guard->passwordVersion('other-hash'));
    }

    public function testTamperedSignatureReturnsNull()
    {
        $guard = $this->getContainer()->get(AdminJwtGuard::class);
        $token = $guard->issue(random_int(100000, 999999), 'password-hash');

        $segments = explode('.', $token);
        $this->assertCount(3, $segments);
        // 见 MerchantJwtGuardTest 的同名方法注释：不能翻最后一个字符，
        // base64url 编码的 HMAC-SHA256 签名最后一个字符只携带填充位，
        // 翻转它可能不改变解码后的字节值，导致假阳性。
        $segments[2] = $this->flipFirstChar($segments[2]);
        $tampered = implode('.', $segments);

        $this->assertNull($guard->resolve($tampered));
    }

    public function testTokenSignedWithDifferentSecretReturnsNull()
    {
        $guard = $this->getContainer()->get(AdminJwtGuard::class);
        $adminUserId = random_int(100000, 999999);

        $now = time();
        $token = JWT::encode([
            'sub' => (string) $adminUserId,
            'iat' => $now,
            'exp' => $now + 3600,
        ], 'a-completely-different-secret-value', 'HS256');

        $this->assertNull($guard->resolve($token));
    }

    public function testExpiredTokenReturnsNull()
    {
        $guard = $this->getContainer()->get(AdminJwtGuard::class);
        $secret = (string) env('ADMIN_JWT_SECRET');
        $this->assertNotSame('', $secret);

        $past = time() - 3600;
        $token = JWT::encode([
            'sub' => '123456',
            'iat' => $past - 10,
            'exp' => $past,
        ], $secret, 'HS256');

        $this->assertNull($guard->resolve($token));
    }

    public function testMalformedGarbageStringReturnsNullNotException()
    {
        $guard = $this->getContainer()->get(AdminJwtGuard::class);

        $this->assertNull($guard->resolve('not-a-jwt-at-all'));
        $this->assertNull($guard->resolve('still.not.valid.jwt'));
        $this->assertNull($guard->resolve('garbage.garbage.garbage'));
    }

    public function testTokenWithoutPasswordVersionReturnsNull()
    {
        $guard = $this->getContainer()->get(AdminJwtGuard::class);
        $now = time();
        $token = JWT::encode([
            'sub' => '123',
            'iat' => $now,
            'exp' => $now + 3600,
        ], (string) env('ADMIN_JWT_SECRET'), 'HS256');

        $this->assertNull($guard->resolve($token));
    }

    public function testMissingSubClaimReturnsNull()
    {
        $guard = $this->getContainer()->get(AdminJwtGuard::class);
        $secret = (string) env('ADMIN_JWT_SECRET');

        $now = time();
        $token = JWT::encode([
            'iat' => $now,
            'exp' => $now + 3600,
        ], $secret, 'HS256');

        $this->assertNull($guard->resolve($token));
    }

    public function testNonNumericSubClaimReturnsNull()
    {
        $guard = $this->getContainer()->get(AdminJwtGuard::class);
        $secret = (string) env('ADMIN_JWT_SECRET');

        $now = time();
        $token = JWT::encode([
            'sub' => 'not-a-number',
            'iat' => $now,
            'exp' => $now + 3600,
        ], $secret, 'HS256');

        $this->assertNull($guard->resolve($token));
    }

    private function flipFirstChar(string $segment): string
    {
        $first = substr($segment, 0, 1);
        $flipped = $first === 'A' ? 'B' : 'A';

        return $flipped . substr($segment, 1);
    }
}
