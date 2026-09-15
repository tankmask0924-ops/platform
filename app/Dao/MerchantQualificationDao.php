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

class MerchantQualificationDao extends AbstractDao
{
    protected string $model = MerchantQualification::class;

    public function findByMerchantId(int $merchantId): ?MerchantQualification
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->first();
    }

    /**
     * 系统管理后台（web/admin）「商户入驻审核」详情/审核动作用（requirements.md 4.1、8.3）。
     *
     * 跟 findByMerchantId() 不同：那个方法不排序、不过滤 status，只取「随便一条」，
     * 对审核这种必须精确定位「当前这条待审核提交」的场景太松——一个商户驳回后
     * 重新提交资质资料会在表里留下多条历史记录（驳回的旧记录 + 新提交的待审核记录），
     * findByMerchantId() 不保证拿到的是最新那条，也不保证是 pending 状态。
     * 这里显式按 created_at 倒序 + status = 'pending' 过滤，只取真正等待审核的那条。
     */
    public function findLatestPendingByMerchantId(int $merchantId): ?MerchantQualification
    {
        return $this->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->first();
    }
}
