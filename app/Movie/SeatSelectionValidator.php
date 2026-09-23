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

namespace App\Movie;

/**
 * 电影票锁座前置校验（requirements.md 7.3「锁座前置校验」，mango.md 第 1 节）：不满足任何一条
 * 直接拒绝，**不调用供应商**——芒果锁座没有防重复单号，一次注定失败的锁座也可能留下
 * 结果未知的订单和冻结。
 *
 * 输入是驱动归一化后的座位图（App\Supplier\Mango\MangoDriver::querySeats() 的返回值，
 * 每个座位 `seat_code`/`row`/`col`/`area_id`/`love_status`/`available`），`row`/`col` 是
 * 座位图上的格子坐标：同一排相邻两个座位 `col` 差 1，中间隔着过道就会跳号。
 *
 * 规则：
 * 1. 1 ~ 4 个座位（单笔上限 4）；不能重复、必须都在这个场次的座位图里、必须都可售。
 * 2. **不可跨区**：所选座位的分区必须相同（不同分区价格不同）。分区 ID 本身由驱动锁座时
 *    从座位数据回填，这里只管"同一个区"。
 * 3. **情侣座成对且相邻**：`love_status` 1（左）必须同时选它右边紧挨着的 2（右），反之亦然。
 * 4. **隔空选座**：同一排里被过道/墙隔开的每一段，有效座位数 > 5 时，所选座位左右任意一边
 *    不能只剩 1 个空座（遇到已选、已售或这一段的尽头就停止数）。已售座位也算有效座位，
 *    只是不空。
 */
class SeatSelectionValidator
{
    public const MAX_SEATS = 4;

    /** 一段连续有效座位数超过这个值才检查"不能留单个空座" */
    private const GAP_RULE_MIN_SEGMENT = 5;

    private const LOVE_LEFT = 1;

    private const LOVE_RIGHT = 2;

    /**
     * @param list<string> $selectedCodes 商户选的座位编码
     * @param list<array{seat_code: string, row: int, col: int, area_id: null|string, love_status: int, available: bool}> $seatMap
     * @return list<array{seat_code: string, row: int, col: int, area_id: null|string, love_status: int, available: bool}> 通过校验的座位（座位图里的原条目，锁座时直接交给驱动）
     * @throws SeatSelectionException
     */
    public function validate(array $selectedCodes, array $seatMap): array
    {
        $count = count($selectedCodes);
        if ($count < 1 || $count > self::MAX_SEATS) {
            throw new SeatSelectionException('一次最多选 ' . self::MAX_SEATS . ' 个座位，至少选 1 个');
        }
        if (count(array_unique($selectedCodes)) !== $count) {
            throw new SeatSelectionException('座位不能重复');
        }

        $byCode = [];
        $byPosition = [];
        foreach ($seatMap as $seat) {
            $byCode[$seat['seat_code']] = $seat;
            $byPosition[$seat['row']][$seat['col']] = $seat;
        }

        $selected = [];
        foreach ($selectedCodes as $code) {
            $seat = $byCode[$code] ?? null;
            if ($seat === null) {
                throw new SeatSelectionException('座位 ' . $code . ' 不存在');
            }
            if (! $seat['available']) {
                throw new SeatSelectionException('座位 ' . $code . ' 已售出或不可选');
            }
            $selected[$code] = $seat;
        }

        $areas = array_unique(array_map(static fn (array $seat) => (string) $seat['area_id'], $selected));
        if (count($areas) > 1) {
            throw new SeatSelectionException('不能跨分区选座');
        }

        $this->assertLoveSeatsPaired($selected, $byPosition);
        $this->assertNoSingleGap($selected, $byPosition);

        return array_values($selected);
    }

    /**
     * @param array<string, array<string, mixed>> $selected
     * @param array<int, array<int, array<string, mixed>>> $byPosition
     */
    private function assertLoveSeatsPaired(array $selected, array $byPosition): void
    {
        foreach ($selected as $seat) {
            $partnerCol = match ($seat['love_status']) {
                self::LOVE_LEFT => $seat['col'] + 1,
                self::LOVE_RIGHT => $seat['col'] - 1,
                default => null,
            };
            if ($partnerCol === null) {
                continue;
            }

            $partner = $byPosition[$seat['row']][$partnerCol] ?? null;
            $expected = $seat['love_status'] === self::LOVE_LEFT ? self::LOVE_RIGHT : self::LOVE_LEFT;
            if ($partner === null || $partner['love_status'] !== $expected || ! isset($selected[$partner['seat_code']])) {
                throw new SeatSelectionException('情侣座必须成对购买：座位 ' . $seat['seat_code'] . ' 需要同时选择旁边的座位');
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $selected
     * @param array<int, array<int, array<string, mixed>>> $byPosition
     */
    private function assertNoSingleGap(array $selected, array $byPosition): void
    {
        foreach ($selected as $seat) {
            $row = $byPosition[$seat['row']];
            if ($this->segmentLength($row, $seat['col']) <= self::GAP_RULE_MIN_SEGMENT) {
                continue;
            }

            foreach ([-1, 1] as $direction) {
                if ($this->emptySeatsTowards($row, $seat['col'], $direction, $selected) === 1) {
                    throw new SeatSelectionException('座位 ' . $seat['seat_code'] . ' 旁边会只剩 1 个空座，请换一个座位或连着选');
                }
            }
        }
    }

    /**
     * 这个座位所在的、被过道隔开的那一段连续座位数。
     *
     * @param array<int, array<string, mixed>> $row
     */
    private function segmentLength(array $row, int $col): int
    {
        $length = 1;
        for ($c = $col - 1; isset($row[$c]); --$c) {
            ++$length;
        }
        for ($c = $col + 1; isset($row[$c]); ++$c) {
            ++$length;
        }

        return $length;
    }

    /**
     * 从某个已选座位往一个方向数连续的空座（可售且没被选），遇到已选、已售、过道就停。
     *
     * @param array<int, array<string, mixed>> $row
     * @param array<string, array<string, mixed>> $selected
     */
    private function emptySeatsTowards(array $row, int $col, int $direction, array $selected): int
    {
        $empty = 0;
        for ($c = $col + $direction; isset($row[$c]); $c += $direction) {
            $seat = $row[$c];
            if (! $seat['available'] || isset($selected[$seat['seat_code']])) {
                break;
            }
            ++$empty;
        }

        return $empty;
    }
}
