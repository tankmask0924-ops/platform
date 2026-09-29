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

namespace App\Service\Alert;

use App\Model\Alert;
use App\Model\Order;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * `rebate_loss` 返佣后亏本告警（requirements.md 8.3「告警」）：生成商户返佣的那一刻，算这笔订单
 * 平台合计赚多少——订单毛利（售价 − 成本）+ 供应商返佣 − 商户返佣，小于 0 就报一条。
 *
 * 保存商品、等级比例时前端已经按 5.5 提示过，这里兜的是提示之后的情况：成本价涨了没跟着调售价、
 * 运营看到提示仍然保存、电影票等级比例超过 100% 且超出部分吃掉了毛利。
 *
 * 去重对象跟"该去改哪里"对齐：话费、卡券挂在商品上（改售价或商品返佣），同一商品持续亏本只累加次数；
 * 电影票挂在商户上（亏本来自这个商户等级的比例）。告警内容是最近一笔订单的明细。
 */
class RebateLossAlertService extends AbstractService
{
    private const SCALE = 2;

    #[Inject]
    protected AlertService $alertService;

    /**
     * 刚生成商户返佣之后调用；不抛异常。
     *
     * @param null|int $productId 话费、卡券订单的商品 id；电影票传 null，告警挂到商户上
     */
    public function checkOrder(Order $order, string $merchantRebate, string $supplierRebate = '0.00', ?int $productId = null): void
    {
        $grossProfit = bcsub((string) $order->sale_price, (string) $order->cost_price, self::SCALE);
        $net = bcsub(bcadd($grossProfit, $supplierRebate, self::SCALE), $merchantRebate, self::SCALE);
        if (bccomp($net, '0', self::SCALE) >= 0) {
            return;
        }

        [$relatedType, $relatedId, $subject] = $productId !== null
            ? ['product', $productId, sprintf('商品 #%d', $productId)]
            : ['merchant', (int) $order->merchant_id, sprintf('商户 #%d', $order->merchant_id)];

        $this->alertService->raise(
            Alert::TYPE_REBATE_LOSS,
            Alert::LEVEL_WARNING,
            sprintf(
                '%s 返佣后亏本：订单 %s 毛利 %s + 供应商返佣 %s − 商户返佣 %s = %s',
                $subject,
                $order->order_no,
                $grossProfit,
                bcadd($supplierRebate, '0', self::SCALE),
                bcadd($merchantRebate, '0', self::SCALE),
                $net
            ),
            $relatedType,
            $relatedId
        );
    }
}
