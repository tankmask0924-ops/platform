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
 */
class BalanceService extends AbstractService
{
    #[Inject]
    protected MerchantRebateDao $merchantRebateDao;

    /**
     * @return array{available_balance: string, frozen_balance: string, pending_rebate: string}
     */
    public function getBalance(Merchant $merchant): array
    {
        return [
            'available_balance' => $merchant->available_balance,
            'frozen_balance' => $merchant->frozen_balance,
            'pending_rebate' => $this->merchantRebateDao->sumPendingAmount($merchant->id),
        ];
    }
}
