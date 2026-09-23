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

use App\Supplier\Mango\MangoStatusMapper;
use App\Supplier\UnifiedResult;
use PHPUnit\Framework\TestCase;

/**
 * 逐行核对 mango.md 第 1 节 handle_step 表、第 3 节"订单溢价"。
 *
 * @internal
 * @coversNothing
 */
class MangoStatusMapperTest extends TestCase
{
    public function testHandleStepTable()
    {
        $mapper = new MangoStatusMapper();
        $expected = [
            -2 => UnifiedResult::DefiniteFailure,
            -1 => UnifiedResult::DefiniteFailure,
            0 => UnifiedResult::Processing,
            1 => UnifiedResult::Processing,
            2 => UnifiedResult::Processing,
            3 => UnifiedResult::Success,
            4 => UnifiedResult::Success,
        ];
        foreach ($expected as $step => $result) {
            $this->assertSame($result, $mapper->map($step), 'handle_step ' . $step);
        }
    }

    public function testUnmappedStepIsUnknown()
    {
        $mapper = new MangoStatusMapper();

        $this->assertSame(UnifiedResult::Unknown, $mapper->map(null));
        $this->assertSame(UnifiedResult::Unknown, $mapper->map(99));
    }

    public function testPriceChangedCodes()
    {
        $mapper = new MangoStatusMapper();

        $this->assertTrue($mapper->isPriceChanged('10040'));
        $this->assertTrue($mapper->isPriceChanged('10036'));
        $this->assertFalse($mapper->isPriceChanged('10041'));
        $this->assertFalse($mapper->isPriceChanged(null));
    }
}
