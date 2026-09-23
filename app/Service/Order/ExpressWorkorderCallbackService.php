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

use App\Dao\ExpressWorkorderDao;
use App\Dao\OrderDao;
use App\Exception\CallbackOrderNotFoundException;
use App\Model\Supplier;
use App\Service\AbstractService;
use App\Supplier\SupplierDriverFactory;
use Carbon\Carbon;
use Hyperf\Di\Annotation\Inject;

/**
 * 云洋工单回调（yunyang.md 第 1 节「售后」：含重量赔付金额、理赔金额、状态异常处理结果）。
 * 由 App\Service\Order\ExpressCallbackService 在认出是工单回调时交过来。
 *
 * **回调没有签名，工单也没有带签名的查询接口可以复核**，所以：
 * 1. 回复内容、金额只记在工单上（`supplier_reply` / `supplier_amount` / `supplier_replied_at`），
 *    给客服核实，**不改工单状态、不动钱**——伪造一个"理赔 500 元"的回调不能让平台给任何人加钱。
 * 2. 重量核实、状态异常工单通过后退回的运费会体现在订单运费里，所以顺带做一次**带签名的订单查询**，
 *    交给快递结算（ExpressOrderSettlementService::refreshFromSupplier()），退回部分按费用调整自动退给商户。
 *    这一步跟普通订单回调走的是同一条可信路径，伪造回调最多触发一次多余的查询。
 * 3. 认不出的工单号按找不到处理（404），不回复成功——云洋会重推，比认下一个对不上的回调好。
 */
class ExpressWorkorderCallbackService extends AbstractService
{
    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected ExpressWorkorderDao $workorderDao;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected ExpressOrderSettlementService $settlementService;

    /**
     * @param array<string, mixed> $payload
     * @return null|string 不是工单回调返回 null（交回订单回调处理）；是则返回给云洋的应答
     */
    public function handle(Supplier $supplier, array $payload): ?string
    {
        $callback = $this->supplierDriverFactory->buildYunyang($supplier)->parseWorkOrderCallback($payload);
        if ($callback === null) {
            return null;
        }

        $workorder = $this->workorderDao->findBySupplierWorkorderNo((int) $supplier->id, $callback['workorder_no']);
        if ($workorder === null) {
            throw new CallbackOrderNotFoundException('ExpressWorkorderCallbackService: no workorder matches ' . $callback['workorder_no'] . '.');
        }

        $reply = trim(implode(' ', array_filter([
            $callback['status'] === null ? null : '[' . $callback['status'] . ']',
            $callback['reply'],
        ])));
        $workorder->fill([
            'supplier_reply' => $reply === '' ? '（供应商回调未带说明）' : mb_substr($reply, 0, 500),
            'supplier_amount' => $callback['amount'],
            'supplier_replied_at' => Carbon::now()->toDateTimeString(),
        ])->save();

        $order = $this->orderDao->find($workorder->order_id);
        if ($order !== null) {
            // 查询失败会抛出去：不回复成功，云洋重推时再查一次
            $this->settlementService->refreshFromSupplier($order);
        }

        return ExpressCallbackService::SUCCESS_REPLY;
    }
}
