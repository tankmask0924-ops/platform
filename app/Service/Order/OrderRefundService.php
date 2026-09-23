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
 * 调用方：售后争议确认（App\Service\Admin\DisputeAdminService）、"供应商主动全额退款"自动按确认未到账处理
 * （requirements.md 7.7，App\Service\Order\SupplierRefundAfterSuccessService）、后台部分退款退到全额时。
 * 部分退款见 refundPartially()。
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
            $deducted = (string) ($order->deducted_amount ?? '0.00');
            // 之前部分退过的不再退第二次，只退剩下的
            $amount = bcsub($deducted, (string) $order->refunded_amount, 2);

            // 条件里带上读到的已退金额：跟部分退款同时执行时只有一个能成功，不会多退
            $attributes = ['status' => 'refunded', 'refunded_amount' => $deducted, 'updated_at' => date('Y-m-d H:i:s')];
            $affected = $this->orderDao->newQuery()
                ->where('id', $order->id)
                ->where('status', 'success')
                ->where('refunded_amount', $order->refunded_amount)
                ->update($attributes);
            if ($affected !== 1) {
                return false;
            }
            $order->forceFill($attributes)->syncOriginal();

            // 先锁商户（退款），再动返佣，跟返佣入账/扣回的加锁顺序一致
            if (bccomp($amount, '0', 2) > 0) {
                $this->balanceService->refundOrder((int) $order->merchant_id, (int) $order->id, $amount, $reason, $operatorId);
            }

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

    /**
     * 部分退款（requirements.md 7.1「供应商部分退款：转人工处理」，客服在系统后台核实后执行）：
     * 订单保持成功，`refunded_amount` 累加，这部分钱退回商户可用余额、记"退款"流水，回调商户。
     *
     * 退到跟已扣款一样多就不是"部分"了，交给 refundUndelivered() 走全额退款（订单改已退款、返佣作废或扣回）。
     * **部分退款不动返佣**：需求没规定部分退款时返佣怎么算，先按"订单仍然成功，返佣照常"处理，客服需要的话
     * 另外调账（docs/modules.md 第 8 节「订单管理」说明里记了这条待确认）。
     *
     * 并发：以调用方读到的 `refunded_amount` 为条件更新，两个客服同时退同一单只有一个成功，不会退超。
     *
     * @return bool 是否真的退了；订单已经不是成功，或者期间被别人退过，返回 false
     */
    public function refundPartially(Order $order, string $amount, string $reason, ?int $operatorId = null): bool
    {
        $deducted = (string) ($order->deducted_amount ?? '0.00');
        $alreadyRefunded = (string) $order->refunded_amount;
        $newRefunded = bcadd($alreadyRefunded, $amount, 2);
        if (bccomp($newRefunded, $deducted, 2) >= 0) {
            return $this->refundUndelivered($order, $reason, $operatorId);
        }

        $refunded = Db::transaction(function () use ($order, $amount, $reason, $operatorId, $alreadyRefunded, $newRefunded) {
            $affected = $this->orderDao->newQuery()
                ->where('id', $order->id)
                ->where('status', 'success')
                ->where('refunded_amount', $alreadyRefunded)
                ->update(['refunded_amount' => $newRefunded, 'updated_at' => date('Y-m-d H:i:s')]);
            if ($affected !== 1) {
                return false;
            }

            $this->balanceService->refundOrder((int) $order->merchant_id, (int) $order->id, $amount, $reason, $operatorId);

            return true;
        });

        if ($refunded) {
            $order->refresh();
            $this->merchantNotifyService->notify((int) $order->id);
        }

        return $refunded;
    }
}
