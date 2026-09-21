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

namespace HyperfTest\Cases\Express;

use App\Express\ExpressChannelCodec;
use Hyperf\Testing\TestCase;

/**
 * 快递渠道编号的对外编码（requirements.md 7.2「平台对外使用自己的渠道编号，
 * 不暴露供应商渠道 ID」）。
 *
 * @internal
 * @coversNothing
 */
class ExpressChannelCodecTest extends TestCase
{
    /**
     * 编号是确定性的：下单时重新查价、重新编码，能对回商户手里的那个编号。
     */
    public function testEncodingIsStableForTheSameChannel()
    {
        $codec = new ExpressChannelCodec();

        $this->assertSame($codec->encode(7, 'CH-SF'), $codec->encode(7, 'CH-SF'));
        $this->assertTrue($codec->matches($codec->encode(7, 'CH-SF'), 7, 'CH-SF'));
    }

    /**
     * 不同供应商的同名渠道、同一供应商的不同渠道，编号都必须不同——
     * 否则下单时会换回错误的渠道。
     */
    public function testDifferentChannelsAndSuppliersGetDifferentCodes()
    {
        $codec = new ExpressChannelCodec();

        $this->assertNotSame($codec->encode(7, 'CH-SF'), $codec->encode(8, 'CH-SF'));
        $this->assertNotSame($codec->encode(7, 'CH-SF'), $codec->encode(7, 'CH-ZTO'));
        $this->assertFalse($codec->matches($codec->encode(7, 'CH-SF'), 8, 'CH-SF'));
        $this->assertFalse($codec->matches($codec->encode(7, 'CH-SF'), 7, 'CH-ZTO'));
    }

    /**
     * 编号里不能还原出供应商 id 或渠道 id——它对商户只是个标签。
     */
    public function testCodeLeaksNeitherSupplierIdNorChannelId()
    {
        $code = (new ExpressChannelCodec())->encode(7, 'CH-SF');

        $this->assertStringStartsWith('EX', $code);
        $this->assertSame(18, strlen($code));
        $this->assertStringNotContainsString('CH-SF', $code);
        $this->assertStringNotContainsString('7:', $code);
        $this->assertMatchesRegularExpression('/^EX[0-9a-f]{16}$/', $code);
    }

    public function testGarbageCodeNeverMatches()
    {
        $codec = new ExpressChannelCodec();

        $this->assertFalse($codec->matches('', 7, 'CH-SF'));
        $this->assertFalse($codec->matches('EX0000000000000000', 7, 'CH-SF'));
        $this->assertFalse($codec->matches('CH-SF', 7, 'CH-SF'));
    }
}
