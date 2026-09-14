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

use App\Model\MerchantRebate;

class MerchantRebateDao extends AbstractDao
{
    protected string $model = MerchantRebate::class;

    /**
     * 汇总某个商户「待到账」（status = pending）的返佣金额。
     *
     * amount 是 decimal(10,2)，PDO MySQL 驱动对 DECIMAL 列（含 SUM 聚合后的结果，
     * 精度不变）返回的是原样字符串而不是 float，这里直接透传字符串，不转 float
     * 再格式化，避免浮点误差污染金额。没有匹配行时 Hyperf 的 sum() 会退化成 int 0，
     * 这里统一补成 '0.00' 保持返回值形状一致。
     */
    public function sumPendingAmount(int $merchantId): string
    {
        $sum = $this->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('status', 'pending')
            ->sum('amount');

        if ($sum === 0 || $sum === '0' || $sum === null) {
            return '0.00';
        }

        return (string) $sum;
    }
}
