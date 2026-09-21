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

use App\Model\PricingRule;
use Hyperf\Database\Model\Collection;

class PricingRuleDao extends AbstractDao
{
    protected string $model = PricingRule::class;

    public function findByBusinessLine(string $businessLine): ?PricingRule
    {
        return $this->newQuery()->where('business_line', $businessLine)->first();
    }

    /**
     * @return Collection<int, PricingRule>
     */
    public function all(): Collection
    {
        return $this->newQuery()->get();
    }

    /**
     * 设置某条业务线的规则：有就改、没有就建。用数据库原生 upsert 按 `business_line`
     * 唯一索引原地更新（同 MerchantLevelBusinessRateDao::upsertRate()：`upsert` 是
     * passthru 到底层 Query Builder 的，不会自动维护时间戳，所以手动带上，
     * 冲突时保留原来的 created_at）。两个运营同时保存不会插出两行。
     */
    public function upsertRule(string $businessLine, string $ruleType, string $value, int $operatorId): PricingRule
    {
        $now = date('Y-m-d H:i:s');

        $this->newQuery()->upsert(
            [[
                'business_line' => $businessLine,
                'rule_type' => $ruleType,
                'value' => $value,
                'updated_by' => $operatorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['business_line'],
            ['rule_type', 'value', 'updated_by', 'updated_at']
        );

        return $this->findByBusinessLine($businessLine);
    }
}
