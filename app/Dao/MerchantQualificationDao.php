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

use App\Model\MerchantQualification;
use Hyperf\Database\Model\Collection;

class MerchantQualificationDao extends AbstractDao
{
    protected string $model = MerchantQualification::class;

    /**
     * 商户当前的资质资料：驳回后重新提交会留下多条历史记录，取最新提交的一条
     * （按 id 倒序，同一秒内提交两次也不会取错）。
     */
    public function findLatestByMerchantId(int $merchantId): ?MerchantQualification
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->orderByDesc('id')->first();
    }

    /**
     * 系统管理后台（web/admin）「商户入驻审核」审核动作用（requirements.md 4.1、8.3）：
     * 只取真正等待审核的那条。
     */
    public function findLatestPendingByMerchantId(int $merchantId): ?MerchantQualification
    {
        return $this->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 全部提交记录，最新在前（商户和审核人员看历次驳回原因用）。
     *
     * @return Collection<int, MerchantQualification>
     */
    public function listByMerchantId(int $merchantId): Collection
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->orderByDesc('id')->get();
    }
}
