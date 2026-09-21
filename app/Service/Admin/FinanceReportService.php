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

use App\Dao\MerchantBalanceLogDao;
use App\Dao\MerchantDao;
use App\Dao\MerchantLevelDao;
use App\Dao\MerchantRebateDao;
use App\Dao\OrderDao;
use App\Dao\SupplierDao;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 财务报表（requirements.md 8.3「财务报表：资金流水；订单毛利与返佣收支分开统计；
 * 按商户/等级/业务线/供应商统计」），docs/modules.md 第 8 节。走查询聚合，不建表
 * （database-design.md 6「财务报表走查询/视图，不新增表」）。
 *
 * **订单毛利和返佣收支分开算、分开显示，最后才相加**（requirements.md 1.2）：
 * 两者是两条独立的账——毛利是"卖出去赚的差价"，返佣是"按等级返还给商户的钱"，
 * 混在一起算净利会让"哪一头出了问题"看不出来。所以每一行都给出
 * `gross_profit`（售价合计 − 成本合计）、`rebate_balance`（供应商返佣 − 商户返佣）
 * 和 `total_profit`（两者之和）三个数。
 *
 * **供应商返佣恒为 0.00**：话费、卡券没有供应商返佣（5.4），电影票、快递是三期，
 * 那两张带 `supplier_rebate` 列的表还没建。字段按最终形状先返回，三期在
 * Dao 里补 SUM 即可，接口和前端不用再改一次——跟
 * App\Service\Admin\SupplierStatsService 同一个处理方式。
 *
 * 口径细节（为什么按完成时间取数、为什么已退款单独成列、返佣为什么只算
 * pending+settled、按等级分组为什么用当前等级）全部写在两个 Dao 方法的注释里：
 * App\Dao\OrderDao::profitStats() 和 App\Dao\MerchantRebateDao::rebateStatsByOrderCompletion()。
 *
 * 权限 `report.view`，预置只给财务：报表把全平台的成本价、毛利摊开，
 * 是财务的账本，不是运营日常要看的东西。
 */
class FinanceReportService extends AbstractService
{
    public const GROUP_BY_DAY = 'day';

    public const GROUP_BYS = [self::GROUP_BY_DAY, 'merchant', 'level', 'business_line', 'supplier'];

    /**
     * 不传时间时默认看最近 30 天（含今天）：财务看的是月度口径，
     * 比供应商统计的 7 天长。
     */
    public const DEFAULT_DAYS = 30;

    /**
     * 单次查询的最大跨度，跟供应商统计一致（按天分组时返回的数组长度就是天数）。
     */
    private const MAX_DAYS = 92;

    /**
     * 话费、卡券没有供应商返佣，见类注释。
     */
    private const SUPPLIER_REBATE_TOTAL = '0.00';

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected MerchantRebateDao $merchantRebateDao;

    #[Inject]
    protected MerchantBalanceLogDao $merchantBalanceLogDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantLevelDao $merchantLevelDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    /**
     * 订单毛利与返佣收支。
     *
     * @param array<string, mixed> $query group_by / from / to / merchant_id
     * @return array<string, mixed>
     */
    public function profit(array $query): array
    {
        $groupBy = $this->validateGroupBy($query['group_by'] ?? self::GROUP_BY_DAY);
        [$from, $to] = $this->validateRange($query);
        $merchantId = $this->validateMerchantId($query);

        $orderRows = $this->orderDao->profitStats($from . ' 00:00:00', $to . ' 23:59:59', $groupBy, $merchantId);
        $rebateRows = $this->merchantRebateDao->rebateStatsByOrderCompletion($from . ' 00:00:00', $to . ' 23:59:59', $groupBy, $merchantId);

        $merged = $this->merge($orderRows, $rebateRows);
        $rows = $groupBy === self::GROUP_BY_DAY ? $this->fillDays($merged, $from, $to) : $merged;
        $labels = $this->labelsFor($groupBy, array_column($rows, 'key'));

        return [
            'group_by' => $groupBy,
            'from' => $from,
            'to' => $to,
            'merchant_id' => $merchantId,
            'summary' => $this->format(null, null, $this->total($merged)),
            'data' => array_map(
                fn (array $row) => $this->format($row['key'], $labels[$row['key'] ?? ''] ?? null, $row),
                $rows
            ),
        ];
    }

    /**
     * 资金流水汇总。跟毛利报表分成两个接口而不是塞进同一个响应：资金流水是
     * "钱进出了多少"，毛利是"赚了多少"，两者的时间轴和口径都不一样
     * （流水按发生时间、毛利按订单完成时间），硬凑在一行里只会让人误以为能相减。
     *
     * @param array<string, mixed> $query from / to / merchant_id
     * @return array<string, mixed>
     */
    public function balanceFlows(array $query): array
    {
        [$from, $to] = $this->validateRange($query);
        $merchantId = $this->validateMerchantId($query);

        $rows = $this->merchantBalanceLogDao->summarizeByType($from . ' 00:00:00', $to . ' 23:59:59', $merchantId);

        return [
            'from' => $from,
            'to' => $to,
            'merchant_id' => $merchantId,
            'data' => array_map(static fn (array $row) => [
                'type' => $row['type'],
                'count' => $row['count'],
                'amount' => number_format((float) $row['amount'], 2, '.', ''),
            ], $rows),
            'total_count' => array_sum(array_column($rows, 'count')),
        ];
    }

    /**
     * 把订单侧和返佣侧按分组键合并。两边的键集合不一定一样：有订单没返佣（商品没配
     * 返佣金额）、有返佣没订单（订单完成在区间内但当前状态既不是成功也不是已退款，
     * 比如被人工改判成失败）都可能，取并集，缺的一边补 0。
     *
     * @param list<array<string, mixed>> $orderRows
     * @param list<array{key: null|string, amount: string}> $rebateRows
     * @return list<array<string, mixed>>
     */
    private function merge(array $orderRows, array $rebateRows): array
    {
        $rebateByKey = [];
        foreach ($rebateRows as $row) {
            $rebateByKey[$row['key'] ?? ''] = $row['amount'];
        }

        $merged = [];
        foreach ($orderRows as $row) {
            $key = $row['key'] ?? '';
            $row['merchant_rebate'] = $rebateByKey[$key] ?? '0.00';
            unset($rebateByKey[$key]);
            $merged[] = $row;
        }
        foreach ($rebateByKey as $key => $amount) {
            $merged[] = [
                'key' => $key === '' ? null : (string) $key,
                'orders' => 0,
                'sale_total' => '0.00',
                'cost_total' => '0.00',
                'refunded_count' => 0,
                'refunded_amount' => '0.00',
                'merchant_rebate' => $amount,
            ];
        }

        return $merged;
    }

    /**
     * 汇总行在已经取回来的分组上再加一次，不另外查一次数据库（跨度有上限，
     * 分组数量本来就很小）。
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function total(array $rows): array
    {
        $total = [
            'orders' => 0,
            'sale_total' => '0.00',
            'cost_total' => '0.00',
            'refunded_count' => 0,
            'refunded_amount' => '0.00',
            'merchant_rebate' => '0.00',
        ];
        foreach ($rows as $row) {
            $total['orders'] += $row['orders'];
            $total['refunded_count'] += $row['refunded_count'];
            foreach (['sale_total', 'cost_total', 'refunded_amount', 'merchant_rebate'] as $column) {
                $total[$column] = bcadd($total[$column], $row[$column], 2);
            }
        }

        return $total;
    }

    /**
     * 金额一律用 bcmath 算、用字符串返回，不转 float（database-design.md 5：
     * 金额禁止用浮点数）。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format(?string $key, ?string $label, array $row): array
    {
        $saleTotal = $this->money($row['sale_total']);
        $costTotal = $this->money($row['cost_total']);
        $merchantRebate = $this->money($row['merchant_rebate']);
        $grossProfit = bcsub($saleTotal, $costTotal, 2);
        $rebateBalance = bcsub(self::SUPPLIER_REBATE_TOTAL, $merchantRebate, 2);

        return [
            'key' => $key,
            'label' => $label,
            'orders' => $row['orders'],
            'sale_total' => $saleTotal,
            'cost_total' => $costTotal,
            'gross_profit' => $grossProfit,
            'merchant_rebate' => $merchantRebate,
            'supplier_rebate' => self::SUPPLIER_REBATE_TOTAL,
            // 返佣收支 = 供应商返佣 − 商户返佣，话费/卡券只有支出，所以是负数
            'rebate_balance' => $rebateBalance,
            'total_profit' => bcadd($grossProfit, $rebateBalance, 2),
            'refunded_count' => $row['refunded_count'],
            'refunded_amount' => $this->money($row['refunded_amount']),
        ];
    }

    private function money(mixed $value): string
    {
        return bcadd((string) $value, '0', 2);
    }

    /**
     * 按天分组时补齐没有订单的日期，前端画趋势不用自己补洞（同
     * SupplierStatsService::fillDays()）。
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function fillDays(array $rows, string $from, string $to): array
    {
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['key']] = $row;
        }

        $filled = [];
        for ($day = $from; $day <= $to; $day = date('Y-m-d', strtotime($day . ' +1 day'))) {
            $filled[] = $byDay[$day] ?? [
                'key' => $day,
                'orders' => 0,
                'sale_total' => '0.00',
                'cost_total' => '0.00',
                'refunded_count' => 0,
                'refunded_amount' => '0.00',
                'merchant_rebate' => '0.00',
            ];
        }

        return $filled;
    }

    /**
     * 分组键的显示名：商户按 `#id 手机号/邮箱`（跟返佣明细页一致，商户表没有名称列），
     * 等级和供应商取名称，业务线和日期由前端自己转中文。只查这一页出现过的 id。
     *
     * @param list<null|string> $keys
     * @return array<string, string>
     */
    private function labelsFor(string $groupBy, array $keys): array
    {
        $ids = array_values(array_filter(array_map(static fn ($key) => (int) $key, array_filter($keys, static fn ($key) => $key !== null && $key !== ''))));
        if ($ids === [] || in_array($groupBy, [self::GROUP_BY_DAY, 'business_line'], true)) {
            return [];
        }

        $rows = match ($groupBy) {
            'merchant' => $this->merchantDao->newQuery()->whereIn('id', $ids)->get(['id', 'phone', 'email'])
                ->mapWithKeys(static fn ($m) => [(string) $m->id => (string) ($m->phone ?? $m->email ?? '')]),
            'level' => $this->merchantLevelDao->newQuery()->whereIn('id', $ids)->pluck('name', 'id'),
            'supplier' => $this->supplierDao->newQuery()->whereIn('id', $ids)->pluck('name', 'id'),
            default => [],
        };

        $labels = [];
        foreach ($rows as $id => $label) {
            $labels[(string) $id] = (string) $label;
        }

        return $labels;
    }

    private function validateGroupBy(mixed $groupBy): string
    {
        if ($groupBy === null || $groupBy === '') {
            return self::GROUP_BY_DAY;
        }
        if (! in_array($groupBy, self::GROUP_BYS, true)) {
            throw new HttpException(422, 'group_by 只能是 ' . implode(' / ', self::GROUP_BYS));
        }

        return $groupBy;
    }

    private function validateMerchantId(array $query): ?int
    {
        $merchantId = $query['merchant_id'] ?? null;
        if ($merchantId === null || $merchantId === '') {
            return null;
        }
        if (! is_numeric($merchantId)) {
            throw new HttpException(422, 'merchant_id 不合法');
        }

        return (int) $merchantId;
    }

    /**
     * 只接受 YYYY-MM-DD（同 SupplierStatsService::validateRange()）：报表是按天看的，
     * 给到时分秒会让"按天分组"的边界变得含糊。两个都不传按最近 DEFAULT_DAYS 天，
     * 只传一个时另一个按跨度推出来。
     *
     * @param array<string, mixed> $query
     * @return array{0: string, 1: string}
     */
    private function validateRange(array $query): array
    {
        $from = $this->validateDate($query['from'] ?? null, 'from');
        $to = $this->validateDate($query['to'] ?? null, 'to');

        if ($from === null && $to === null) {
            $to = date('Y-m-d');
            $from = date('Y-m-d', strtotime($to . ' -' . (self::DEFAULT_DAYS - 1) . ' days'));
        } elseif ($from === null) {
            $from = date('Y-m-d', strtotime($to . ' -' . (self::DEFAULT_DAYS - 1) . ' days'));
        } elseif ($to === null) {
            $to = date('Y-m-d', strtotime($from . ' +' . (self::DEFAULT_DAYS - 1) . ' days'));
        }

        if ($from > $to) {
            throw new HttpException(422, 'from 不能晚于 to');
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
