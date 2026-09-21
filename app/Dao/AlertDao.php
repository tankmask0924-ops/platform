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

use App\Model\Alert;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Collection;
use Hyperf\DbConnection\Db;

class AlertDao extends AbstractDao
{
    protected string $model = Alert::class;

    public function find(int $id): ?Alert
    {
        return Alert::find($id);
    }

    /**
     * 同一 `(type, related_type, related_id)` 当前未处理的那条告警（去重键，
     * database-design.md 4.14）。related_id 为 null 的全局告警也按 null 匹配。
     */
    public function findOpen(string $type, ?string $relatedType, ?int $relatedId): ?Alert
    {
        $query = $this->newQuery()
            ->where('type', $type)
            ->where('status', Alert::STATUS_OPEN);

        $relatedType === null ? $query->whereNull('related_type') : $query->where('related_type', $relatedType);
        $relatedId === null ? $query->whereNull('related_id') : $query->where('related_id', $relatedId);

        return $query->first();
    }

    /**
     * 又触发了一次已有的未处理告警：只更新最近触发时间、累加次数、刷新内容
     * （内容里带的是最新数值，比如余额从 80 掉到 30）。
     *
     * `occurrence_count` 用数据库原地自增而不是读出来加一再写回：同一条告警可能被
     * 定时任务和下单链路同时触发，读-改-写会丢计数。
     */
    public function touchOccurrence(Alert $alert, string $message, string $level): void
    {
        $this->newQuery()
            ->where('id', $alert->id)
            ->where('status', Alert::STATUS_OPEN)
            ->update([
                'message' => $message,
                'level' => $level,
                'triggered_at' => date('Y-m-d H:i:s'),
                'occurrence_count' => Db::raw('occurrence_count + 1'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * 后台告警列表：默认未处理的在前，其次按最近触发倒序。
     *
     * @param array{status?: string, type?: string, level?: string, related_type?: string,
     *     related_id?: int, triggered_from?: string, triggered_to?: string} $filters
     * @return Collection<int, Alert>
     */
    public function paginateFiltered(array $filters, int $page, int $perPage): Collection
    {
        return $this->filterQuery($filters)
            ->orderByRaw("status = 'open' desc")
            ->orderByDesc('triggered_at')
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
     * 各状态各有多少条（后台顶部的未处理数用它，不用把整张表拉下来数）。
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
     * 后一个会看到 409 而不是默默把前一个的处理人覆盖掉。
     */
    public function resolveIfOpen(int $id, string $status, int $operatorId): bool
    {
        return $this->newQuery()
            ->where('id', $id)
            ->where('status', Alert::STATUS_OPEN)
            ->update([
                'status' => $status,
                'resolved_by' => $operatorId,
                'resolved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]) === 1;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function filterQuery(array $filters): Builder
    {
        $query = $this->newQuery();

        foreach (['status', 'type', 'level', 'related_type', 'related_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (isset($filters['triggered_from'])) {
            $query->where('triggered_at', '>=', $filters['triggered_from']);
        }
        if (isset($filters['triggered_to'])) {
            $query->where('triggered_at', '<=', $filters['triggered_to']);
        }

        return $query;
    }
}
