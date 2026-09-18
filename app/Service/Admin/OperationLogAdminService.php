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

use App\Dao\AdminOperationLogDao;
use App\Dao\AdminUserDao;
use App\Model\AdminOperationLog;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 系统设置 - 操作日志查看（requirements.md 8.3），只读。
 */
class OperationLogAdminService extends AbstractService
{
    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    /**
     * @param array<string, mixed> $query admin_user_id / module / action / target_type / target_id / created_from / created_to
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));

        $builder = $this->operationLogDao->newQuery();
        foreach (['admin_user_id', 'target_id'] as $field) {
            if (isset($query[$field]) && is_numeric($query[$field])) {
                $builder->where($field, (int) $query[$field]);
            }
        }
        foreach (['module', 'action', 'target_type'] as $field) {
            if (is_string($query[$field] ?? null) && $query[$field] !== '') {
                $builder->where($field, $query[$field]);
            }
        }
        if ($this->isDate($query['created_from'] ?? null)) {
            $builder->where('created_at', '>=', $query['created_from'] . ' 00:00:00');
        }
        if ($this->isDate($query['created_to'] ?? null)) {
            $builder->where('created_at', '<=', $query['created_to'] . ' 23:59:59');
        }

        $total = (clone $builder)->count();
        $logs = $builder->orderByDesc('id')->forPage($page, $perPage)->get();
        $admins = $this->adminUserDao->newQuery()
            ->whereIn('id', $logs->pluck('admin_user_id')->unique()->all())
            ->get(['id', 'username', 'real_name'])
            ->keyBy('id');

        return [
            'data' => $logs->map(fn (AdminOperationLog $log) => [
                'id' => $log->id,
                'admin_user_id' => $log->admin_user_id,
                'admin_username' => $admins->get($log->admin_user_id)?->username,
                'admin_real_name' => $admins->get($log->admin_user_id)?->real_name,
                'module' => $log->module,
                'action' => $log->action,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'before_data' => $log->before_data,
                'after_data' => $log->after_data,
                'ip' => $log->ip,
                'created_at' => $log->created_at?->toDateTimeString(),
            ])->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
