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

namespace HyperfTest\Cases\Supplier\Yunyang;

use App\Supplier\Yunyang\YunyangSigner;
use PHPUnit\Framework\TestCase;

/**
 * yunyang.md 第 1 节「签名」：sign = md5(appid + requestId + timeStamp + secretKey)，
 * 请求内容不参与签名。
 *
 * @internal
 * @coversNothing
 */
class YunyangSignerTest extends TestCase
{
    public function testSignIsMd5OfTheFourConcatenatedParts()
    {
        $signer = new YunyangSigner();

        $this->assertSame(
            md5('appid-1req-11789000000secret-1'),
            $signer->sign('appid-1', 'req-1', '1789000000', 'secret-1')
        );
    }

    /**
     * 签名不覆盖请求内容，所以同一组参数无论发什么 content 签出来都一样——
     * 这正是 yunyang.md 第 3 节说的风险，驱动靠"只允许 https"来兜。
     */
    public function testAnyOfTheFourPartsChangingChangesTheSignature()
    {
        $signer = new YunyangSigner();
        $base = $signer->sign('appid-1', 'req-1', '1789000000', 'secret-1');

        $this->assertNotSame($base, $signer->sign('appid-2', 'req-1', '1789000000', 'secret-1'));
        $this->assertNotSame($base, $signer->sign('appid-1', 'req-2', '1789000000', 'secret-1'));
        $this->assertNotSame($base, $signer->sign('appid-1', 'req-1', '1789000001', 'secret-1'));
        $this->assertNotSame($base, $signer->sign('appid-1', 'req-1', '1789000000', 'secret-2'));
    }

    public function testRequestIdIsAFreshUuidEveryTime()
    {
        $signer = new YunyangSigner();
        $ids = [];
        for ($i = 0; $i < 50; ++$i) {
            $ids[] = $signer->requestId();
        }

        $this->assertCount(50, array_unique($ids), '每次请求都要新的 requestId');
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        }
    }

    public function testTimestampIsTenDigitSeconds()
    {
        $timestamp = (new YunyangSigner())->timestamp();

        $this->assertMatchesRegularExpression('/^\d{10}$/', $timestamp);
        $this->assertLessThanOrEqual(2, abs(time() - (int) $timestamp));
    }
}
