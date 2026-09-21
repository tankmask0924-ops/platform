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

namespace App\Service\Admin;

use App\Dao\OrderAttemptDao;
use App\Dao\SupplierDao;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 供应商统计（requirements.md 6.8「供应商统计：订单量、成功率、平均到账时长、成本总额、
 * 供应商返佣总额，按天/商品查看，用于调整优先级」），docs/modules.md 第 8 节
 * 「供应商管理」行的最后一个子模块。
 *
 * 数据口径全部落在 App\Dao\OrderAttemptDao::statsForSupplier() 的注释里（为什么按尝试
 * 而不是按订单归属供应商、成功率分母算哪些、到账时长从哪算到哪），这里只负责参数校验、
 * 补齐没有订单的日期、算百分比和汇总。
 *
 * **供应商返佣总额固定是 0.00**：话费、卡券没有供应商返佣（requirements.md 5.3
 * 「话费、卡券的返佣基数是商品返佣金额」、5.4「电影票、快递才有供应商返佣」），
 * 而电影票、快递是三期，`order_movies`/`order_expresses` 两张带 `supplier_rebate`
 * 列的表还没建。字段先按最终形状返回，等三期建表后在 Dao 里补上 SUM 即可，
 * 前端和接口形状不用再改一次。
 *
 * 权限用 `supplier.view`：统计是只读的运营观察数据，跟看列表/详情/调用日志同一档，
 * 不单独开权限。
 */
class SupplierStatsService extends AbstractService
{
    public const GROUP_BY_DAY = 'day';

    public const GROUP_BY_PRODUCT = 'product';

    /**
     * 不传时间时默认看最近 7 天（含今天），跟商户后台首页统计的趋势天数一致。
     */
    public const DEFAULT_DAYS = 7;

    /**
     * 单次查询的最大跨度。按天分组时返回的数组长度就是天数，给一个上限避免
     * 前端一不小心传个 2020 年把三千多个空日期塞进响应里。
     */
    private const MAX_DAYS = 92;

    /**
     * 话费、卡券没有供应商返佣，见类注释。
     */
    private const SUPPLIER_REBATE_TOTAL = '0.00';

    #[Inject]
    protected OrderAttemptDao $orderAttemptDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    /**
     * @param array<string, mixed> $query group_by / created_from / created_to
     * @return array<string, mixed>
     */
    public function forSupplier(int $supplierId, array $query): array
    {
        if ($this->supplierDao->find($supplierId) === null) {
            throw new HttpException(404, '供应商不存在');
        }

        $groupBy = $this->validateGroupBy($query['group_by'] ?? self::GROUP_BY_DAY);
        [$fromDate, $toDate] = $this->validateRange($query);

        $rows = $this->orderAttemptDao->statsForSupplier(
            $supplierId,
            $fromDate . ' 00:00:00',
            $toDate . ' 23:59:59',
            $groupBy === self::GROUP_BY_PRODUCT,
        );

        return [
            'group_by' => $groupBy,
            'created_from' => $fromDate,
            'created_to' => $toDate,
            'summary' => $this->summarize($rows),
            'data' => $groupBy === self::GROUP_BY_DAY
                ? $this->fillDays($rows, $fromDate, $toDate)
                : array_map(fn (array $row) => $this->formatRow($row['group'] ?? '未知商品', $row), $rows),
        ];
    }

    /**
     * 汇总行在 Dao 返回的分组上再加一次，不另外查一次数据库：跨度有上限，
     * 分组数量本来就很小。平均到账时长要按成功笔数加权，不能对各组的平均值再求平均。
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function summarize(array $rows): array
    {
        $total = ['count' => 0, 'success' => 0, 'failed' => 0, 'cost_total' => '0.00'];
        $secondsSum = 0.0;
        foreach ($rows as $row) {
            $total['count'] += $row['count'];
            $total['success'] += $row['success'];
            $total['failed'] += $row['failed'];
            $total['cost_total'] = bcadd($total['cost_total'], $row['cost_total'], 2);
            if ($row['avg_seconds'] !== null) {
                $secondsSum += $row['avg_seconds'] * $row['success'];
            }
        }
        $total['avg_seconds'] = $total['success'] > 0 ? $secondsSum / $total['success'] : null;

        return $this->formatRow(null, $total);
    }

    /**
     * 按天分组时补齐没有任何尝试的日期（补 0），前端直接按数组画趋势，不用自己对齐日期。
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function fillDays(array $rows, string $fromDate, string $toDate): array
    {
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['group']] = $row;
        }

        $data = [];
        for ($day = $fromDate; $day <= $toDate; $day = date('Y-m-d', strtotime($day . ' +1 day'))) {
            $data[] = $this->formatRow($day, $byDay[$day] ?? [
                'count' => 0, 'success' => 0, 'failed' => 0, 'cost_total' => '0.00', 'avg_seconds' => null,
            ]);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatRow(?string $label, array $row): array
    {
        $finished = $row['success'] + $row['failed'];

        return [
            // 按天时是日期，按商品时是商品名；汇总行没有标签
            'label' => $label,
            'product_id' => $row['product_id'] ?? null,
            'order_count' => $row['count'],
            'success_count' => $row['success'],
            'failed_count' => $row['failed'],
            // 还没有结果的尝试：处理中、结果未知，不计入成功率
            'pending_count' => $row['count'] - $finished,
            // 百分比，保留一位小数；没有已出结果的尝试时为 null（不是 0%）
            'success_rate' => $finished > 0 ? round($row['success'] * 100 / $finished, 1) : null,
            // 平均到账时长，秒，保留一位小数；没有成功的尝试时为 null
            'avg_delivery_seconds' => $row['avg_seconds'] === null ? null : round($row['avg_seconds'], 1),
            'cost_total' => number_format((float) $row['cost_total'], 2, '.', ''),
            'supplier_rebate_total' => self::SUPPLIER_REBATE_TOTAL,
        ];
    }

    private function validateGroupBy(mixed $groupBy): string
    {
        if ($groupBy === null || $groupBy === '') {
            return self::GROUP_BY_DAY;
        }
        if (! in_array($groupBy, [self::GROUP_BY_DAY, self::GROUP_BY_PRODUCT], true)) {
            throw new HttpException(422, 'group_by 只能是 day 或 product');
        }

        return $groupBy;
    }

    /**
     * 只接受 YYYY-MM-DD：统计是按天看的，给到时分秒会让"按天分组"的边界变得含糊
     * （调用日志那边按时间点筛是另一回事）。两个都不传按最近 DEFAULT_DAYS 天，
     * 只传一个时另一个按跨度推出来。
     *
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
        $days = (int) floor((strtotime($to) - strtotime($from)) / 86400) + 1;
        if ($days > self::MAX_DAYS) {
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
