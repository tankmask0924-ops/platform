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

namespace HyperfTest\Cases\Movie;

use App\Movie\SeatSelectionException;
use App\Movie\SeatSelectionValidator;
use PHPUnit\Framework\TestCase;

/**
 * 锁座前置校验（requirements.md 7.3、mango.md 第 1 节）。座位图用字符串画：
 * 一个字符一个格子，`.` 可售、`x` 已售、`_` 过道（没有座位）、`L`/`R` 情侣座左/右（可售），
 * 座位编码是 "排-列"。
 *
 * @internal
 * @coversNothing
 */
class SeatSelectionValidatorTest extends TestCase
{
    public function testAcceptsANormalContiguousSelection()
    {
        $seats = (new SeatSelectionValidator())->validate(['1-3', '1-4'], $this->map(['........']));

        $this->assertSame(['1-3', '1-4'], array_column($seats, 'seat_code'));
    }

    public function testSeatCountLimits()
    {
        $map = $this->map(['..........']);
        $this->assertRejected([], $map, '至少选 1 个');
        $this->assertRejected(['1-1', '1-2', '1-3', '1-4', '1-5'], $map, '最多 4 个');
        $this->assertRejected(['1-1', '1-1'], $map, '不能重复');
    }

    public function testSeatMustExistAndBeAvailable()
    {
        $map = $this->map(['..x..']);
        $this->assertRejected(['9-9'], $map, '不存在');
        $this->assertRejected(['1-3'], $map, '已售');
    }

    public function testCannotCrossAreas()
    {
        $map = $this->map(['......']);
        $map[0]['area_id'] = 'A';
        $map[1]['area_id'] = 'B';

        $this->assertRejected(['1-1', '1-2'], $map, '跨区');
    }

    public function testLoveSeatsMustBeBoughtAsAPair()
    {
        $map = $this->map(['.LR.']);
        $this->assertRejected(['1-2'], $map, '只选左');
        $this->assertRejected(['1-3'], $map, '只选右');
        $this->assertCount(2, (new SeatSelectionValidator())->validate(['1-2', '1-3'], $map));
    }

    /**
     * 连续 8 个座位的一段：选第 2 个会让第 1 个成为孤座；选第 3 个左边留 2 个没问题。
     */
    public function testSingleGapIsRejectedInLongSegments()
    {
        $map = $this->map(['........']);
        $this->assertRejected(['1-2'], $map, '左边只剩 1 个空座');
        $this->assertRejected(['1-6', '1-7'], $map, '右边只剩 1 个空座');
        $this->assertCount(1, (new SeatSelectionValidator())->validate(['1-3'], $map));
        $this->assertCount(2, (new SeatSelectionValidator())->validate(['1-1', '1-2'], $map), '靠边选不留孤座');
    }

    /**
     * 已售座位挡住了计数：x 旁边隔一个空座再选，会把那个空座夹成孤座。
     */
    public function testSoldSeatsCountAsOccupied()
    {
        $map = $this->map(['x.......']);
        $this->assertRejected(['1-3'], $map, '1-2 被已售和所选夹成孤座');
        $this->assertCount(1, (new SeatSelectionValidator())->validate(['1-2'], $map));
    }

    /**
     * 过道把一排分成两段，每段 ≤ 5 个就不检查；数空座遇到过道就停。
     */
    public function testAislesSplitSegments()
    {
        $map = $this->map(['.....__.....']);
        $this->assertCount(1, (new SeatSelectionValidator())->validate(['1-2'], $map), '这一段只有 5 个座，不检查');

        $long = $this->map(['......_..']);
        $this->assertRejected(['1-5'], $long, '长段里右边只剩 1 个（过道前）');
        $this->assertCount(1, (new SeatSelectionValidator())->validate(['1-9'], $long), '短段不检查');
    }

    /**
     * @param list<string> $selected
     * @param list<array<string, mixed>> $map
     */
    private function assertRejected(array $selected, array $map, string $why): void
    {
        try {
            (new SeatSelectionValidator())->validate($selected, $map);
            $this->fail('should be rejected: ' . $why);
        } catch (SeatSelectionException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param list<string> $rows
     * @return list<array{seat_code: string, row: int, col: int, area_id: null|string, love_status: int, available: bool}>
     */
    private function map(array $rows): array
    {
        $seats = [];
        foreach ($rows as $r => $line) {
            foreach (str_split($line) as $c => $cell) {
                if ($cell === '_') {
                    continue;
                }
                $seats[] = [
                    'seat_code' => ($r + 1) . '-' . ($c + 1),
                    'row' => $r + 1,
                    'col' => $c + 1,
                    'area_id' => null,
                    'love_status' => match ($cell) {
                        'L' => 1,
                        'R' => 2,
                        default => 0,
                    },
                    'available' => $cell !== 'x',
                ];
            }
        }

        return $seats;
    }
}
