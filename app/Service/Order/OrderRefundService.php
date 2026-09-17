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

namespace App\Service\Order;

use App\Dao\MerchantRebateDao;
use App\Dao\OrderDao;
use App\Model\Order;
use App\Service\AbstractService;
use App\Service\Merchant\BalanceService;
use App\Service\MerchantNotifyService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;

/**
 * 成功订单核实未到账后的全额退款（requirements.md 7.7"确认未到账"）：
 * 1. 订单 success → refunded（条件更新，同一笔订单只会退一次），refunded_amount = 已扣款；
 * 2. 已扣款退回可用余额，记"退款"流水；
 * 3. 返佣：待到账的作废，已到账的从可用余额扣回（可能扣成负数，按 4.5 负余额处理）；
 * 4. 事务提交后回调通知商户。
 *
 * 目前的调用方是售后争议确认（App\Service\Admin\DisputeAdminService）；"供应商主动全额退款"
 * 自动按确认未到账处理（requirements.md 7.7）也应该走这里，那条检测链路还没建。
 */
class OrderRefundService extends AbstractService
{
    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected MerchantRebateDao $merchantRebateDao;

    #[Inject]
    protected BalanceService $balanceService;

    #[Inject]
    protected MerchantNotifyService $merchantNotifyService;

    /**
     * @param null|callable(): void $alsoInTransaction 需要跟退款一起提交的其它写入（比如争议状态）
     * @return bool 是否真的退了；订单已经不是 success（别人先处理了）返回 false，什么都不做
     */
    public function refundUndelivered(Order $order, string $reason, ?int $operatorId = null, ?callable $alsoInTransaction = null): bool
    {
        $refunded = Db::transaction(function () use ($order, $reason, $operatorId, $alsoInTransaction) {
            $amount = (string) ($order->deducted_amount ?? '0.00');

            $updated = $this->orderDao->finishIfStatus($order, [
                'status' => 'refunded',
                'refunded_amount' => $amount,
            ], 'success');
            if (! $updated) {
                return false;
            }

            // 先锁商户（退款），再动返佣，跟返佣入账/扣回的加锁顺序一致
            $this->balanceService->refundOrder((int) $order->merchant_id, (int) $order->id, $amount, $reason, $operatorId);

            $rebate = $this->merchantRebateDao->findByOrderId((int) $order->id);
            if ($rebate !== null && ! $this->merchantRebateDao->voidIfPending((int) $rebate->id)) {
                $this->balanceService->clawbackRebate((int) $rebate->id, $reason, $operatorId);
            }

            if ($alsoInTransaction !== null) {
                $alsoInTransaction();
            }

            return true;
        });

        if ($refunded) {
            $this->merchantNotifyService->notify((int) $order->id);
        }

        return $refunded;
    }
}
