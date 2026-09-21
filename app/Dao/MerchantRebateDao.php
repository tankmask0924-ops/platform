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

namespace App\Dao;

use App\Model\MerchantRebate;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Collection;
use InvalidArgumentException;

class MerchantRebateDao extends AbstractDao
{
    protected string $model = MerchantRebate::class;

    /**
     * 按 order_id 查返佣记录（`merchant_rebates.order_id` 唯一）。
     */
    public function findByOrderId(int $orderId): ?MerchantRebate
    {
        return $this->newQuery()->where('order_id', $orderId)->first();
    }

    /**
     * requirements.md 5.4"入账"：定时任务扫描到期的待到账记录——
     * `status = pending AND due_at <= now()`，给 `App\Crontab\RebateSettlementCrontab`
     * 用。`due_at` 为 `null` 的行（订单本身完成时间还没到，比如快递未签收，本次
     * 任务范围内的话费返佣不会出现这种情况，但字段设计上允许）不会被
     * `<=` 比较命中，天然被排除，不需要额外加 `whereNotNull`。
     *
     * 订单有处理中的售后争议时暂停到账（requirements.md 5.4"争议暂停"），争议处理完
     * 再按结果作废或正常到账。
     */
    public function findDuePending(): Collection
    {
        return $this->newQuery()
            ->where('status', 'pending')
            ->where('due_at', '<=', date('Y-m-d H:i:s'))
            ->whereNotExists(static function ($query) {
                $query->selectRaw('1')
                    ->from('aftersale_disputes')
                    ->whereColumn('aftersale_disputes.order_id', 'merchant_rebates.order_id')
                    ->where('aftersale_disputes.status', 'processing');
            })
            ->get();
    }

    /**
     * 调用方必须已经在事务里。
     */
    public function lockForUpdate(int $id): ?MerchantRebate
    {
        return $this->newQuery()->where('id', $id)->lockForUpdate()->first();
    }

    /**
     * 待到账的返佣作废（requirements.md 5.4"作废"）。带 `status = pending` 条件更新，
     * 已经到账的不会被改；返回是否真的作废了。
     */
    public function voidIfPending(int $id): bool
    {
        return $this->newQuery()
            ->where('id', $id)
            ->where('status', 'pending')
            ->update(['status' => 'voided', 'voided_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]) === 1;
    }

    /**
     * 汇总某个商户「待到账」（status = pending）的返佣金额。
     *
     * amount 是 decimal(10,2)，PDO MySQL 驱动对 DECIMAL 列（含 SUM 聚合后的结果，
     * 精度不变）返回的是原样字符串而不是 float，这里直接透传字符串，不转 float
     * 再格式化，避免浮点误差污染金额。没有匹配行时 Hyperf 的 sum() 会退化成 int 0，
     * 这里统一补成 '0.00' 保持返回值形状一致。
     */
    public function sumPendingAmount(int $merchantId): string
    {
        $sum = $this->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('status', 'pending')
            ->sum('amount');

        if ($sum === 0 || $sum === '0' || $sum === null) {
            return '0.00';
        }

        return (string) $sum;
    }

    /**
     * 返佣明细分页（商户后台、系统后台共用），最新在前。
     *
     * @param array<string, mixed> $filters merchant_id / status / business_line / order_no / created_from / created_to
     * @return Collection<int, MerchantRebate>
     */
    public function paginateFiltered(array $filters, int $page, int $perPage): Collection
    {
        return $this->filterQuery($filters)->orderByDesc('id')->forPage($page, $perPage)->get();
    }

    /**
     * @param array<string, mixed> $filters 同 paginateFiltered()
     */
    public function countFiltered(array $filters): int
    {
        return $this->filterQuery($filters)->count();
    }

    /**
     * 按状态汇总笔数和金额（金额是 DECIMAL 求和后的字符串）。
     *
     * @param array<string, mixed> $filters 同 paginateFiltered()
     * @return array<string, array{count: int, amount: string}>
     */
    public function summarizeByStatus(array $filters): array
    {
        $rows = $this->filterQuery($filters)
            ->selectRaw('status, count(*) as n, sum(amount) as total')
            ->groupBy('status')
            ->get();

        $summary = [];
        foreach ($rows as $row) {
            $summary[$row->status] = ['count' => (int) $row->n, 'amount' => bcadd((string) $row->total, '0', 2)];
        }

        return $summary;
    }

    /**
     * 财务报表（requirements.md 8.3）的返佣侧：按**订单完成时间**落在区间里的商户返佣，
     * 按维度聚合金额。订单侧在 App\Dao\OrderDao::profitStats()，两边共用同一套分组
     * 表达式（都写在 `orders` 上，见下面的 join）和同一条时间轴，否则合并出来的行对不上。
     *
     * **只算 `pending` 和 `settled`**：这两个状态代表"这笔佣金平台欠着或已经付了"，
     * 是实打实的支出。`voided`（订单没成/被判未到账作废）和 `clawed_back`（已从余额扣回）
     * 等于没付出去，计进支出会把利润压低。
     *
     * **不看订单当前状态，只看返佣状态**：订单退款后返佣本来就该被作废或扣回，
     * 真出现"订单已退款、返佣还挂着 pending"的情况，那笔钱确实还欠着，报表照记，
     * 这样报表和返佣明细页永远是同一个口径。
     *
     * 时间条件下在 `orders.completed_at` 而不是 `merchant_rebates.order_completed_at`：
     * 后者是下单时写进来的快照，订单完成时间被人工改判修正过的话两张表会不一致，
     * 以订单表为准。
     *
     * @param string $groupBy day / merchant / level / business_line / supplier
     * @return list<array{key: null|string, amount: string}>
     */
    public function rebateStatsByOrderCompletion(string $from, string $to, string $groupBy, ?int $merchantId = null): array
    {
        $query = $this->newQuery()
            ->join('orders', 'orders.id', '=', 'merchant_rebates.order_id')
            ->whereIn('merchant_rebates.status', ['pending', 'settled'])
            ->whereBetween('orders.completed_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(merchant_rebates.amount), 0) as amount_total');

        if ($merchantId !== null) {
            $query->where('merchant_rebates.merchant_id', $merchantId);
        }
        $this->applyReportGrouping($query, $groupBy);

        return $query->get()
            ->map(static fn ($row) => [
                'key' => $row->g === null ? null : (string) $row->g,
                'amount' => (string) $row->amount_total,
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function filterQuery(array $filters): Builder
    {
        $query = $this->newQuery();
        foreach (['merchant_id', 'status', 'business_line'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (isset($filters['order_no'])) {
            $query->whereIn('order_id', fn ($sub) => $sub->select('id')->from('orders')->where('order_no', $filters['order_no']));
        }
        if (isset($filters['created_from'])) {
            $query->where('created_at', '>=', $filters['created_from']);
        }
        if (isset($filters['created_to'])) {
            $query->where('created_at', '<=', $filters['created_to']);
        }

        return $query;
    }

    /**
     * 跟 App\Dao\OrderDao::applyReportGrouping() 必须保持一致（分组键要能一一对上），
     * 改了要一起改。`level` 用商户当前等级的理由写在那边。
     */
    private function applyReportGrouping(Builder $query, string $groupBy): void
    {
        match ($groupBy) {
            'day' => $query->selectRaw('DATE(orders.completed_at) as g')->groupBy('g')->orderBy('g'),
            'merchant' => $query->selectRaw('orders.merchant_id as g')->groupBy('g'),
            'business_line' => $query->selectRaw('orders.business_line as g')->groupBy('g'),
            'supplier' => $query->selectRaw('orders.supplier_id as g')->groupBy('g'),
            'level' => $query->leftJoin('merchants', 'merchants.id', '=', 'orders.merchant_id')
                ->selectRaw('merchants.level_id as g')->groupBy('g'),
            default => throw new InvalidArgumentException('unsupported report group_by: ' . $groupBy),
        };
    }
}
