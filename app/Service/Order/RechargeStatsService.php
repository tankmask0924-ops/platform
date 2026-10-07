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

namespace App\Service\Order;

use App\Dao\OrderDao;
use App\Dao\ProductDao;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 话费订单的耗时与成功率（系统后台 `GET /admin/recharge-stats`、商户后台 `GET /merchant/recharge-stats` 共用）。
 *
 * 口径：
 * - **以订单为单位、按下单时间取数**：一笔订单不管中间换了几家供应商都只算一次，看的是商户实际感受到的结果。
 *   按供应商看每次尝试的成功率在供应商详情的统计里（App\Service\Admin\SupplierStatsService），两边口径不同。
 * - **成功率 = 成功 ÷（成功 + 失败 + 已退款）**：已退款的话费订单是售后核实未到账或供应商成功后又全额退款，
 *   等于没充上，算作没成功；处理中、异常单还没有结论，不进分母，单独给出 `pending` 笔数。
 *   部分退款的订单仍是成功，按成功算。
 * - **耗时只算成功的订单，从下单到充值成功**（`completed_at - created_at`，秒）。给平均值、中位数、90 分位：
 *   话费到账有快有慢（快充几秒、慢充可能几小时），平均值容易被少数特别慢的单拉高，中位数说明大多数订单多快到账，
 *   90 分位说明慢的那一批大概多慢。分位数用最近秩法（排序后取第 ⌈p × n⌉ 个），没有成功订单时为 null。
 * - 维度：按天（补齐没有订单的日期）、按商品、按运营商。商品和运营商取自下单时的商品明细。
 * - 时间区间只收 `YYYY-MM-DD`，不传看最近 7 天，最多 92 天（跟供应商统计一致）。
 */
class RechargeStatsService extends AbstractService
{
    public const GROUP_BYS = ['day', 'product', 'operator'];

    public const DEFAULT_DAYS = 7;

    private const MAX_DAYS = 92;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected ProductDao $productDao;

    /**
     * @param array<string, mixed> $query group_by / created_from / created_to（系统后台另外可传 merchant_id）
     * @param null|int $merchantId 商户后台传当前商户 id，只统计自己的订单；系统后台传 null 再从 $query 里取筛选
     * @return array<string, mixed>
     */
    public function stats(array $query, ?int $merchantId = null): array
    {
        $groupBy = $this->validateGroupBy($query['group_by'] ?? null);
        [$fromDate, $toDate] = $this->validateRange($query);
        if ($merchantId === null) {
            $merchantId = $this->validateMerchantId($query['merchant_id'] ?? null);
        }
        $from = $fromDate . ' 00:00:00';
        $to = $toDate . ' 23:59:59';

        $counts = $this->orderDao->rechargeStatsCounts($from, $to, $groupBy, $merchantId);
        $histogram = $this->orderDao->rechargeDurationHistogram($from, $to, $groupBy, $merchantId);

        $durationsByKey = [];
        $allDurations = [];
        foreach ($histogram as $bucket) {
            $durationsByKey[$bucket['key'] ?? ''][$bucket['seconds']] = ($durationsByKey[$bucket['key'] ?? ''][$bucket['seconds']] ?? 0) + $bucket['count'];
            $allDurations[$bucket['seconds']] = ($allDurations[$bucket['seconds']] ?? 0) + $bucket['count'];
        }

        $rows = [];
        foreach ($counts as $row) {
            $rows[$row['key'] ?? ''] = $row;
        }
        if ($groupBy === 'day') {
            $rows = $this->fillDays($rows, $fromDate, $toDate);
        }
        $labels = $groupBy === 'product' ? $this->productLabels(array_keys($rows)) : [];

        $data = [];
        foreach ($rows as $key => $row) {
            $key = (string) $key;
            $data[] = $this->format(
                $key === '' ? null : $key,
                $labels[$key] ?? null,
                $row,
                $durationsByKey[$key] ?? []
            );
        }

        return [
            'group_by' => $groupBy,
            'created_from' => $fromDate,
            'created_to' => $toDate,
            'merchant_id' => $merchantId,
            'summary' => $this->format(null, null, $this->total($counts), $allDurations),
            'data' => $data,
        ];
    }

    /**
     * @param array{total: int, success: int, failed: int, refunded: int, pending: int, success_seconds: int} $row
     * @param array<int, int> $durations 耗时秒数 => 笔数
     * @return array<string, mixed>
     */
    private function format(?string $key, ?string $label, array $row, array $durations): array
    {
        $finished = $row['success'] + $row['failed'] + $row['refunded'];

        return [
            'key' => $key,
            'label' => $label,
            'total' => $row['total'],
            'success' => $row['success'],
            'failed' => $row['failed'],
            'refunded' => $row['refunded'],
            'pending' => $row['pending'],
            // 百分比，保留一位小数；还没有出结论的订单时为 null（不是 0%）
            'success_rate' => $finished > 0 ? round($row['success'] * 100 / $finished, 1) : null,
            // 秒，保留一位小数；没有成功订单时为 null
            'avg_seconds' => $row['success'] > 0 ? round($row['success_seconds'] / $row['success'], 1) : null,
            'p50_seconds' => $this->percentile($durations, 0.5),
            'p90_seconds' => $this->percentile($durations, 0.9),
        ];
    }

    /**
     * 最近秩法：把所有耗时从小到大排好，取第 ⌈p × n⌉ 个。`$durations` 是「秒数 => 笔数」的分布。
     *
     * @param array<int, int> $durations
     */
    private function percentile(array $durations, float $p): ?int
    {
        $n = array_sum($durations);
        if ($n === 0) {
            return null;
        }
        ksort($durations);
        $rank = (int) ceil($p * $n);
        $seen = 0;
        foreach ($durations as $seconds => $count) {
            $seen += $count;
            if ($seen >= $rank) {
                return (int) $seconds;
            }
        }

        return (int) array_key_last($durations);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{total: int, success: int, failed: int, refunded: int, pending: int, success_seconds: int}
     */
    private function total(array $rows): array
    {
        $total = ['total' => 0, 'success' => 0, 'failed' => 0, 'refunded' => 0, 'pending' => 0, 'success_seconds' => 0];
        foreach ($rows as $row) {
            foreach (array_keys($total) as $field) {
                $total[$field] += $row[$field];
            }
        }

        return $total;
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function fillDays(array $rows, string $fromDate, string $toDate): array
    {
        $filled = [];
        for ($day = $fromDate; $day <= $toDate; $day = date('Y-m-d', strtotime($day . ' +1 day'))) {
            $filled[$day] = $rows[$day] ?? ['key' => $day, 'total' => 0, 'success' => 0, 'failed' => 0, 'refunded' => 0, 'pending' => 0, 'success_seconds' => 0];
        }

        return $filled;
    }

    /**
     * 商品维度的显示名：商品名称。只查这一页出现过的商品。
     *
     * @param list<int|string> $keys
     * @return array<string, string>
     */
    private function productLabels(array $keys): array
    {
        $ids = array_values(array_filter(array_map('intval', $keys)));

        return $this->productDao->newQuery()->whereIn('id', $ids)->pluck('name', 'id')
            ->mapWithKeys(static fn ($name, $id) => [(string) $id => (string) $name])
            ->all();
    }

    private function validateGroupBy(mixed $groupBy): string
    {
        if ($groupBy === null || $groupBy === '') {
            return 'day';
        }
        if (! in_array($groupBy, self::GROUP_BYS, true)) {
            throw new HttpException(422, 'group_by 只能是 day、product 或 operator');
        }

        return $groupBy;
    }

    private function validateMerchantId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (int) $value <= 0) {
            throw new HttpException(422, 'merchant_id 不合法');
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: string, 1: string}
     */
    private function validateRange(array $query): array
    {
        $from = $this->validateDate($query['created_from'] ?? null, 'created_from');
        $to = $this->validateDate($query['created_to'] ?? null, 'created_to');

        if ($from === null && $to === null) {
            $to = date('Y-m-d');
            $from = date('Y-m-d', strtotime($to . ' -' . (self::DEFAULT_DAYS - 1) . ' days'));
        } elseif ($from === null) {
            $from = date('Y-m-d', strtotime($to . ' -' . (self::DEFAULT_DAYS - 1) . ' days'));
        } elseif ($to === null) {
            $to = date('Y-m-d', strtotime($from . ' +' . (self::DEFAULT_DAYS - 1) . ' days'));
        }

        if ($from > $to) {
            throw new HttpException(422, 'created_from 不能晚于 created_to');
        }
        if ((int) floor((strtotime($to) - strtotime($from)) / 86400) + 1 > self::MAX_DAYS) {
            throw new HttpException(422, '时间跨度最多 ' . self::MAX_DAYS . ' 天');
        }

        return [$from, $to];
    }

    private function validateDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || strtotime($value) === false) {
            throw new HttpException(422, $field . ' 必须是 YYYY-MM-DD 格式的日期');
        }

        return $value;
    }
}
