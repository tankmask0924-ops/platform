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

use App\Model\AdminOperationLog;
use Hyperf\Context\Context;
use Hyperf\Database\Model\Collection;

class AdminOperationLogDao extends AbstractDao
{
    public const RECORDED_CONTEXT_KEY = 'admin_operation_log.recorded';

    protected string $model = AdminOperationLog::class;

    /**
     * @param null|array<string, mixed> $before
     * @param null|array<string, mixed> $after
     */
    public function record(
        int $adminUserId,
        string $module,
        string $action,
        ?string $targetType,
        ?int $targetId,
        ?array $before,
        ?array $after,
        ?string $ip
    ): AdminOperationLog {
        // 告诉 AdminOperationLogAspect 这个请求已经记过带前后对比的日志，不用再自动记一条
        Context::set(self::RECORDED_CONTEXT_KEY, true);

        return $this->newQuery()->create([
            'admin_user_id' => $adminUserId,
            'module' => $module,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before_data' => $before,
            'after_data' => $after,
            'ip' => $ip,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return Collection<int, AdminOperationLog>
     */
    public function listForTarget(string $module, string $targetType, int $targetId): Collection
    {
        return $this->newQuery()
            ->where('module', $module)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->orderBy('id')
            ->get();
    }
}
