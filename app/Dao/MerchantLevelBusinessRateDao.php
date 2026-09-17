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

use App\Model\MerchantLevelBusinessRate;
use Hyperf\Database\Model\Collection;

class MerchantLevelBusinessRateDao extends AbstractDao
{
    protected string $model = MerchantLevelBusinessRate::class;

    /**
     * 按 (level_id, business_line) 查该等级在该业务线的默认返佣比例
     * （requirements.md 5.3 返佣比例取法第 2 步），没有设置时返回 null。
     */
    public function findForLevelAndBusinessLine(int $levelId, string $businessLine): ?MerchantLevelBusinessRate
    {
        return $this->newQuery()
            ->where('level_id', $levelId)
            ->where('business_line', $businessLine)
            ->first();
    }

    /**
     * 某个等级已设置的全部业务线比例（没设置的业务线没有行）。
     */
    public function listForLevel(int $levelId): Collection
    {
        return $this->newQuery()
            ->where('level_id', $levelId)
            ->orderBy('id')
            ->get();
    }

    /**
     * 设置某等级在某业务线的默认返佣比例：没有行就插入，已有行就原地更新
     * rebate_rate。
     *
     * 用数据库原生 upsert（MySQL 下编译成 INSERT ... ON DUPLICATE KEY UPDATE，
     * 冲突判定靠 `(level_id, business_line)` 唯一索引），单条语句原子完成，
     * 两个并发请求同时给同一对 (level, business_line) 首次设置比例也不会撞唯一
     * 约束报错，不需要先查后写再 catch QueryException 的兜底。
     *
     * `upsert` 在 Model Builder 里是 passthru 直接转给底层 Query Builder 的，
     * 不会自动维护时间戳，所以这里手动带上 created_at/updated_at，并且冲突时只
     * 更新 rebate_rate/updated_at，保留原来的 created_at。
     */
    public function upsertRate(int $levelId, string $businessLine, string $rebateRate): MerchantLevelBusinessRate
    {
        $now = date('Y-m-d H:i:s');

        $this->newQuery()->upsert(
            [[
                'level_id' => $levelId,
                'business_line' => $businessLine,
                'rebate_rate' => $rebateRate,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['level_id', 'business_line'],
            ['rebate_rate', 'updated_at']
        );

        return $this->findForLevelAndBusinessLine($levelId, $businessLine);
    }
}
