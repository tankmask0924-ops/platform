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

namespace App\Service\OpenApi;

use App\Dao\MerchantRebateDao;
use App\Model\Merchant;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 开放 API「查询余额」（requirements.md 8.1）：可用余额、冻结余额直接取自
 * merchants 表快照字段，待到账返佣是实时汇总 merchant_rebates 里 status = pending 的金额。
 *
 * `debt_since`（requirements.md 4.5「负余额」）原样透传 merchants 表这一列：非
 * `null` 就表示商户当前处于欠款、下单已被暂停，商户自己的系统可以拿这个字段
 * 做程序化判断，不用反过来猜「可用余额是不是负数」（`debt_since` 是
 * `App\Service\Merchant\BalanceService::persistBalance()` 维护的权威信号）。
 * 这一列不是 Carbon 类型（`App\Model\Merchant` 没有把它加进 `$dates`），直接是
 * MySQL 驱动给回来的 `Y-m-d H:i:s` 字符串或 `null`，天然就是 ISO 兼容的日期时间
 * 字符串，不需要额外格式化。
 */
class BalanceService extends AbstractService
{
    #[Inject]
    protected MerchantRebateDao $merchantRebateDao;

    /**
     * @return array{available_balance: string, frozen_balance: string, pending_rebate: string, debt_since: null|string}
     */
    public function getBalance(Merchant $merchant): array
    {
        return [
            'available_balance' => $merchant->available_balance,
            'frozen_balance' => $merchant->frozen_balance,
            'pending_rebate' => $this->merchantRebateDao->sumPendingAmount($merchant->id),
            'debt_since' => $merchant->debt_since,
        ];
    }
}
