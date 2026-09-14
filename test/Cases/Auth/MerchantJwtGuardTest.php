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

use App\Auth\MerchantJwtGuard;
use Firebase\JWT\JWT;
use Hyperf\Testing\TestCase;

use function Hyperf\Support\env;

/**
 * 过期 token / 篡改签名 token 需要用跟 MerchantJwtGuard 内部一致的密钥（MERCHANT_JWT_SECRET）
 * 直接用 firebase/php-jwt 构造，绕不开也不需要绕开——这就是在验证 resolve() 对「合法但
 * 已过期」「合法密钥但签名被篡改」这两类输入的处理，不是在测试签发流程本身
 * （签发流程已经被下面的 round-trip 用例覆盖）。
 *
 * @internal
 * @coversNothing
 */
class MerchantJwtGuardTest extends TestCase
{
    public function testIssueThenResolveRoundTripReturnsMerchantId()
    {
        $guard = $this->getContainer()->get(MerchantJwtGuard::class);
        $merchantId = random_int(100000, 999999);

        $token = $guard->issue($merchantId);

        $this->assertNotSame('', $token);
        $this->assertSame($merchantId, $guard->resolve($token));
    }

    public function testTamperedSignatureReturnsNull()
    {
        $guard = $this->getContainer()->get(MerchantJwtGuard::class);
        $token = $guard->issue(random_int(100000, 999999));

        $segments = explode('.', $token);
        $this->assertCount(3, $segments);
        // 翻转签名段第一个字符（不能翻最后一个字符——32 字节 HMAC-SHA256 签名
        // base64url 编码后最后一个字符只携带 4 位有效数据 + 2 位始终为 0 的填充位，
        // 翻转它可能只动到填充位，解码后字节值不变，测试就是假阳性），
        // 破坏签名但保持 base64url 字符集合法，这样失败原因确定落在
        // 「签名校验不通过」而不是「解码失败」。
        $segments[2] = $this->flipFirstChar($segments[2]);
        $tampered = implode('.', $segments);

        $this->assertNull($guard->resolve($tampered));
    }

    public function testTokenSignedWithDifferentSecretReturnsNull()
    {
        $guard = $this->getContainer()->get(MerchantJwtGuard::class);
        $merchantId = random_int(100000, 999999);

        $now = time();
        $token = JWT::encode([
            'sub' => (string) $merchantId,
            'iat' => $now,
            'exp' => $now + 3600,
        ], 'a-completely-different-secret-value', 'HS256');

        $this->assertNull($guard->resolve($token));
    }

    public function testExpiredTokenReturnsNull()
    {
        $guard = $this->getContainer()->get(MerchantJwtGuard::class);
        $secret = (string) env('MERCHANT_JWT_SECRET');
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
        $guard = $this->getContainer()->get(MerchantJwtGuard::class);

        $this->assertNull($guard->resolve('not-a-jwt-at-all'));
        $this->assertNull($guard->resolve('still.not.valid.jwt'));
        $this->assertNull($guard->resolve('garbage.garbage.garbage'));
    }

    public function testMissingSubClaimReturnsNull()
    {
        $guard = $this->getContainer()->get(MerchantJwtGuard::class);
        $secret = (string) env('MERCHANT_JWT_SECRET');

        $now = time();
        $token = JWT::encode([
            'iat' => $now,
            'exp' => $now + 3600,
        ], $secret, 'HS256');

        $this->assertNull($guard->resolve($token));
    }

    public function testNonNumericSubClaimReturnsNull()
    {
        $guard = $this->getContainer()->get(MerchantJwtGuard::class);
        $secret = (string) env('MERCHANT_JWT_SECRET');

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
