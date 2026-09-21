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

use App\Model\ReconciliationDiff;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Collection;
use Hyperf\DbConnection\Db;

class ReconciliationDiffDao extends AbstractDao
{
    /**
     * 一次写多少行。对账差异是一天一批，正常情况下只有个位数，但供应商侧整体延迟时
     * 可能一次冒出几千行，分批插避免单条 SQL 过长。
     */
    private const INSERT_CHUNK = 200;

    protected string $model = ReconciliationDiff::class;

    public function find(int $id): ?ReconciliationDiff
    {
        return ReconciliationDiff::find($id);
    }

    /**
     * 用这一批的结果整体替换某个批次：先删掉该 `(type, reconciliation_date)` 的旧行，
     * 再写入新行，同一个事务里完成（database-design.md 4.15「重跑同一天的对账先删除
     * 当天旧记录再重新写入，不追加」）。
     *
     * 重跑会把上一轮已经标记处理的行一起删掉：批次是"这一天对出来的事实"，重跑意味着
     * 重新认定事实，保留旧的处理标记反而会出现"差异还在、却显示已处理"。
     *
     * @param list<array<string, mixed>> $rows 已经拼好的整行数据（不含 id）
     * @return int 实际写入的行数
     */
    public function replaceBatch(string $type, string $reconciliationDate, array $rows): int
    {
        return Db::transaction(function () use ($type, $reconciliationDate, $rows): int {
            $this->newQuery()
                ->where('type', $type)
                ->where('reconciliation_date', $reconciliationDate)
                ->delete();

            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                Db::table('reconciliation_diffs')->insert($chunk);
            }

            return count($rows);
        });
    }

    /**
     * 后台差异列表：未处理的在前，其次按批次日期、id 倒序（同一批里后发现的在前）。
     *
     * @param array{type?: string, status?: string, field?: string, supplier_id?: int,
     *     order_id?: int, date_from?: string, date_to?: string} $filters
     * @return Collection<int, ReconciliationDiff>
     */
    public function paginateFiltered(array $filters, int $page, int $perPage): Collection
    {
        return $this->filterQuery($filters)
            ->orderByRaw("status = 'open' desc")
            ->orderByDesc('reconciliation_date')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters 同 paginateFiltered()
     */
    public function countFiltered(array $filters): int
    {
        return $this->filterQuery($filters)->count();
    }

    /**
     * 各状态各有多少条（页头的未处理数用它，不受当前筛选影响）。
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        return $this->newQuery()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(static fn ($row) => [(string) $row->status => (int) $row->n])
            ->all();
    }

    /**
     * 条件更新成已处理/已忽略：只有还是 `open` 时才生效，两个运营同时点只有一个成功，
     * 后一个会看到 409 而不是默默把前一个的处理人和备注覆盖掉（同 AlertDao）。
     */
    public function resolveIfOpen(int $id, string $status, int $operatorId, ?string $remark): bool
    {
        return $this->newQuery()
            ->where('id', $id)
            ->where('status', ReconciliationDiff::STATUS_OPEN)
            ->update([
                'status' => $status,
                'resolved_by' => $operatorId,
                'resolved_at' => date('Y-m-d H:i:s'),
                'remark' => $remark,
            ]) === 1;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function filterQuery(array $filters): Builder
    {
        $query = $this->newQuery();

        foreach (['type', 'status', 'field', 'supplier_id', 'order_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (isset($filters['date_from'])) {
            $query->where('reconciliation_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->where('reconciliation_date', '<=', $filters['date_to']);
        }

        return $query;
    }
}
