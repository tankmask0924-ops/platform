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

use App\Dao\AdminUserDao;
use App\Dao\AlertDao;
use App\Model\AdminUser;
use App\Model\Alert;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统管理后台「告警：列表查看 / 标记处理」（requirements.md 8.3），docs/modules.md 第 8 节。
 *
 * 告警的产生在 App\Service\Alert\AlertService，这里只读和标记状态——**后台永远不产生、
 * 也不删除告警**：告警是系统自己观察到的事实，运营能做的是"我看过了/我处理完了/这条不用管"，
 * 不是把它抹掉。所以只有 resolve（已处理）和 ignore（已忽略）两个动作，没有删除接口。
 *
 * 两者的区别只在语义，不在行为：`resolved` = 问题确实存在并且处理了，
 * `ignored` = 看过了，判断不需要处理（比如测试环境触发的）。分开记是为了让后面回看
 * 告警历史时能分辨"我们修过多少次"和"我们忽略过多少次"。
 */
class AlertAdminService extends AbstractService
{
    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected AlertDao $alertDao;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    /**
     * @param array<string, mixed> $query status / type / level / related_type / related_id /
     *                                    triggered_from / triggered_to / page / per_page
     * @return array<string, mixed>
     */
    public function list(array $query): array
    {
        $filters = $this->normalizeFilters($query);
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));

        $alerts = $this->alertDao->paginateFiltered($filters, $page, $perPage);
        $operators = $this->adminUserDao->newQuery()
            ->whereIn('id', $alerts->pluck('resolved_by')->filter()->unique()->all())
            ->pluck('real_name', 'id');

        $counts = $this->alertDao->countByStatus();

        return [
            'data' => $alerts->map(static fn (Alert $alert) => [
                'id' => $alert->id,
                'type' => $alert->type,
                'level' => $alert->level,
                'related_type' => $alert->related_type,
                'related_id' => $alert->related_id,
                'message' => $alert->message,
                'status' => $alert->status,
                'occurrence_count' => $alert->occurrence_count,
                'resolved_by' => $alert->resolved_by !== null ? ($operators[$alert->resolved_by] ?? null) : null,
                'resolved_at' => $alert->resolved_at?->toDateTimeString(),
                'triggered_at' => $alert->triggered_at?->toDateTimeString(),
                'created_at' => $alert->created_at?->toDateTimeString(),
            ])->values()->all(),
            'total' => $this->alertDao->countFiltered($filters),
            'page' => $page,
            'per_page' => $perPage,
            // 未处理数给菜单/页头的红点用，不受当前筛选影响
            'open_count' => $counts[Alert::STATUS_OPEN] ?? 0,
        ];
    }

    /**
     * 标记为已处理 / 已忽略。只能处理还是 `open` 的告警：两个运营同时点时后一个拿到
     * 409，而不是默默把前一个的处理人覆盖掉。
     *
     * @return array<string, mixed>
     */
    public function markHandled(AdminUser $operator, int $id, string $status, array $query): array
    {
        if (! in_array($status, [Alert::STATUS_RESOLVED, Alert::STATUS_IGNORED], true)) {
            throw new HttpException(422, 'status 只能是 resolved 或 ignored');
        }

        $alert = $this->alertDao->find($id);
        if ($alert === null) {
            throw new HttpException(404, '告警不存在');
        }
        if ($alert->status !== Alert::STATUS_OPEN) {
            throw new HttpException(409, '这条告警已经被处理过了');
        }

        if (! $this->alertDao->resolveIfOpen($id, $status, (int) $operator->id)) {
            throw new HttpException(409, '这条告警已经被处理过了');
        }

        return $this->list($query);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $query): array
    {
        $filters = [];

        foreach (['status' => Alert::STATUSES, 'type' => Alert::TYPES, 'level' => Alert::LEVELS, 'related_type' => Alert::RELATED_TYPES] as $key => $allowed) {
            $value = $query[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (! in_array($value, $allowed, true)) {
                throw new HttpException(422, $key . ' 不合法');
            }
            $filters[$key] = $value;
        }

        $relatedId = $query['related_id'] ?? null;
        if ($relatedId !== null && $relatedId !== '') {
            if (! is_numeric($relatedId)) {
                throw new HttpException(422, 'related_id 不合法');
            }
            $filters['related_id'] = (int) $relatedId;
        }

        // 只给日期时，开始取当天 0 点、结束取当天 23:59:59（跟返佣、调用日志一致）
        foreach (['triggered_from' => '00:00:00', 'triggered_to' => '23:59:59'] as $key => $timeOfDay) {
            $value = $query[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $time = is_string($value) ? strtotime($value) : false;
            if ($time === false) {
                throw new HttpException(422, $key . ' 不是合法的时间');
            }
            $filters[$key] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? "{$value} {$timeOfDay}" : date('Y-m-d H:i:s', $time);
        }

        return $filters;
    }
}
