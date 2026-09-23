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

use App\Dao\OrderExpressDao;
use App\Dao\OrderExpressFeeAdjustmentDao;
use App\Model\OrderExpressFeeAdjustment;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 快递订单对商户展示的明细（requirements.md 8.1「快递订单返回费用明细」）：下单返回、
 * 订单查询、取消都用这一份，形状只在这里定义。
 *
 * **不暴露成本**：`order_expresses` 存的是成本，运费要换成实际向商户收的
 * `freight_sale_price`；保价费、耗材费、逆向费本来就按成本转给商户，可以原样给。
 * 结算前（`fee_over_at` 为空）实际费用还不存在，`fees` 为 null，商户看订单上的冻结金额。
 * 费用调整记录里运费一项的金额本来就是按售价算的差额（见 ExpressOrderSettlementService）。
 */
class ExpressOrderPresenter extends AbstractService
{
    #[Inject]
    protected OrderExpressDao $orderExpressDao;

    #[Inject]
    protected OrderExpressFeeAdjustmentDao $feeAdjustmentDao;

    /**
     * @return null|array{company_code: string, company_name: string, waybill_no: null|string,
     *     logistics_status: string, insured_amount: null|string, signed_at: null|string,
     *     fees: null|array{freight: string, insured_fee: string, material_fee: string, reverse_fee: string},
     *     fee_adjustments: list<array{type: string, item: string, amount: string, created_at: string}>}
     */
    public function present(int $orderId): ?array
    {
        $express = $this->orderExpressDao->findByOrderId($orderId);
        if ($express === null) {
            return null;
        }

        $fees = null;
        if ($express->fee_over_at !== null) {
            $fees = [
                'freight' => $express->freight_sale_price ?? '0.00',
                'insured_fee' => $express->actual_insured_fee ?? '0.00',
                'material_fee' => $express->actual_material_fee ?? '0.00',
                'reverse_fee' => $express->actual_reverse_fee ?? '0.00',
            ];
        }

        return [
            'company_code' => $express->express_company_code,
            'company_name' => $express->express_company_name,
            'waybill_no' => $express->waybill_no,
            'logistics_status' => $express->logistics_status,
            'insured_amount' => $express->insured_amount,
            'signed_at' => $express->signed_at?->toDateTimeString(),
            'fees' => $fees,
            'fee_adjustments' => $this->feeAdjustmentDao->listForOrder($orderId)
                ->map(static fn (OrderExpressFeeAdjustment $adjustment) => [
                    'type' => $adjustment->type,
                    'item' => $adjustment->item,
                    'amount' => $adjustment->amount,
                    'created_at' => $adjustment->created_at->toDateTimeString(),
                ])
                ->values()
                ->all(),
        ];
    }
}
