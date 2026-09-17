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

use App\Model\MerchantRateLimit;

class MerchantRateLimitDao extends AbstractDao
{
    protected string $model = MerchantRateLimit::class;

    public function findByMerchantId(int $merchantId): ?MerchantRateLimit
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->first();
    }

    /**
     * 设置商户的单独限流值：没有行就插入，已有行就原地更新。跟
     * App\Dao\MerchantLevelBusinessRateDao::upsertRate() 一样用原生 upsert
     * （冲突判定靠 merchant_id 主键），并发首次设置不会撞主键报错；表上没有
     * created_at，只手动维护 updated_at。
     */
    public function upsertLimit(int $merchantId, int $limitPerSecond): MerchantRateLimit
    {
        $this->newQuery()->upsert(
            [[
                'merchant_id' => $merchantId,
                'limit_per_second' => $limitPerSecond,
                'updated_at' => date('Y-m-d H:i:s'),
            ]],
            ['merchant_id'],
            ['limit_per_second', 'updated_at']
        );

        return $this->findByMerchantId($merchantId);
    }

    /**
     * 删掉单独配置，商户回落到全局默认值。返回是否真的删掉了一行。
     */
    public function deleteByMerchantId(int $merchantId): bool
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->delete() > 0;
    }
}
