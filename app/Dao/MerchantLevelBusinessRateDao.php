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
}
