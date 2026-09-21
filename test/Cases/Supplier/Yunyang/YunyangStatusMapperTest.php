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

use App\Supplier\UnifiedResult;
use App\Supplier\Yunyang\YunyangStatusMapper;
use PHPUnit\Framework\TestCase;

/**
 * yunyang.md 第 2 节「云洋状态与平台订单的对应」逐行核对。
 *
 * @internal
 * @coversNothing
 */
class YunyangStatusMapperTest extends TestCase
{
    /**
     * 判定成功与否看扣费标志，不看物流状态：运费只是冻结时，哪怕已经签收也还没结算。
     */
    public function testFrozenFeeIsAlwaysProcessingWhateverTheLogisticsStatus()
    {
        $mapper = new YunyangStatusMapper();

        foreach ([1, 2, 3, 4] as $typeCode) {
            $this->assertSame(UnifiedResult::Processing, $mapper->map($typeCode, 0), 'typeCode ' . $typeCode);
        }
    }

    public function testSettledFeeIsSuccess()
    {
        $mapper = new YunyangStatusMapper();

        $this->assertSame(UnifiedResult::Success, $mapper->map(2, 1));
        $this->assertSame(UnifiedResult::Success, $mapper->map(3, 1));
    }

    /**
     * 拒收退回不是失败：件发出去了，逆向费还要补扣，判失败会让平台去退款。
     */
    public function testRejectedParcelIsNotAFailure()
    {
        $mapper = new YunyangStatusMapper();

        $this->assertSame(UnifiedResult::Processing, $mapper->map(4, 0));
        $this->assertSame(UnifiedResult::Success, $mapper->map(4, 1));
    }

    public function testCancelledBeforeChargingIsDefiniteFailure()
    {
        $this->assertSame(UnifiedResult::DefiniteFailure, (new YunyangStatusMapper())->map(99, 0));
    }

    /**
     * 已经扣过费又报取消：两边说法对不上，转人工，不能自己判成功或失败。
     */
    public function testCancelledAfterChargingIsUnknown()
    {
        $this->assertSame(UnifiedResult::Unknown, (new YunyangStatusMapper())->map(99, 1));
    }

    public function testMissingOrUnexpectedFieldsAreUnknown()
    {
        $mapper = new YunyangStatusMapper();

        $this->assertSame(UnifiedResult::Unknown, $mapper->map(null, null));
        $this->assertSame(UnifiedResult::Unknown, $mapper->map(2, null));
        $this->assertSame(UnifiedResult::Unknown, $mapper->map(null, 0));
        $this->assertSame(UnifiedResult::Unknown, $mapper->map(7, 0), '文档没列过的 typeCode');
    }

    public function testOnlySignedCountsAsDelivered()
    {
        $mapper = new YunyangStatusMapper();

        $this->assertTrue($mapper->isSigned(3));
        $this->assertFalse($mapper->isSigned(4));
        $this->assertFalse($mapper->isSigned(null));
    }
}
