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

use App\Signature\SignatureSigner;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SignatureSignerTest extends TestCase
{
    public function testSignIsOrderIndependent()
    {
        $signer = new SignatureSigner();
        $secret = 'test-secret';

        $signA = $signer->sign(['b' => 2, 'a' => 1, 'c' => 3], $secret);
        $signB = $signer->sign(['a' => 1, 'c' => 3, 'b' => 2], $secret);

        $this->assertSame($signA, $signB);
    }

    public function testSignMatchesKnownVector()
    {
        $signer = new SignatureSigner();

        // 手工按算法拼出 stringA = "a=1&b=2"，用 openssl_hmac 校验实现没有跑偏
        $sign = $signer->sign(['a' => 1, 'b' => 2], 'secret');

        $this->assertSame(hash_hmac('sha256', 'a=1&b=2', 'secret'), $sign);
    }

    public function testVerifySucceedsForValidSignature()
    {
        $signer = new SignatureSigner();
        $secret = 'test-secret';

        $params = ['app_key' => 'abc', 'timestamp' => 1700000000, 'nonce' => 'xyz'];
        $params['sign'] = $signer->sign($params, $secret);

        $this->assertTrue($signer->verify($params, $secret));
    }

    public function testVerifyFailsForTamperedParam()
    {
        $signer = new SignatureSigner();
        $secret = 'test-secret';

        $params = ['app_key' => 'abc', 'timestamp' => 1700000000, 'nonce' => 'xyz'];
        $params['sign'] = $signer->sign($params, $secret);

        $params['timestamp'] = 1700000001;

        $this->assertFalse($signer->verify($params, $secret));
    }

    public function testVerifyFailsForWrongSecret()
    {
        $signer = new SignatureSigner();

        $params = ['app_key' => 'abc', 'nonce' => 'xyz'];
        $params['sign'] = $signer->sign($params, 'correct-secret');

        $this->assertFalse($signer->verify($params, 'wrong-secret'));
    }

    public function testVerifyFailsWhenSignMissing()
    {
        $signer = new SignatureSigner();

        $this->assertFalse($signer->verify(['app_key' => 'abc'], 'secret'));
    }
}
