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

namespace HyperfTest\Cases\Supplier\Mango;

use App\Supplier\Mango\MangoSigner;
use PHPUnit\Framework\TestCase;

/**
 * 芒果签名（mango.md 第 1 节）：key 字典序、key+value 直接拼、末尾拼 token、md5。
 *
 * @internal
 * @coversNothing
 */
class MangoSignerTest extends TestCase
{
    public function testSortsKeysAndConcatenatesWithoutSeparators()
    {
        $sign = (new MangoSigner())->sign(['b' => '2', 'a' => '1', 'c' => 3], 'tok');

        $this->assertSame(md5('a1b2c3tok'), $sign);
    }

    public function testSignidItselfIsExcluded()
    {
        $signer = new MangoSigner();

        $this->assertSame($signer->sign(['a' => '1'], 'tok'), $signer->sign(['a' => '1', 'signid' => 'whatever'], 'tok'));
    }

    public function testArraysAreJsonEncoded()
    {
        $sign = (new MangoSigner())->sign(['seat_data' => [['SeatCode' => '1-1', 'area_id' => '区A']]], 'tok');

        $this->assertSame(md5('seat_data[{"SeatCode":"1-1","area_id":"区A"}]tok'), $sign);
    }

    public function testAnyChangedValueChangesTheSignature()
    {
        $signer = new MangoSigner();

        $this->assertNotSame($signer->sign(['room_id' => 'S1'], 'tok'), $signer->sign(['room_id' => 'S2'], 'tok'));
    }
}
