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

use App\Crypto\Encryptor;
use App\Dao\OrderDao;
use App\Dao\OrderRechargeDao;
use App\Model\Merchant;
use App\OpenApi\ErrorCode;
use App\Service\AbstractService;
use App\Service\Order\ExpressOrderPresenter;
use App\Service\Order\ExpressOrderSettlementService;
use App\Service\Order\MovieOrderPresenter;
use App\Service\Order\MovieOrderSettlementService;
use Hyperf\Di\Annotation\Inject;

/**
 * 开放 API「订单查询」（requirements.md 8.1）：按平台订单号或商户订单号查询，
 * 卡密类订单（recharge/card）返回解密后的明文卡号卡密；快递订单多一个 `express`
 * 明细（运单号、物流状态、费用明细和费用调整，形状见 App\Service\Order\ExpressOrderPresenter），
 * 电影票订单多一个 `movie` 明细（场次、座位、每张售价、锁座有效期、取票码，见 MovieOrderPresenter）。
 */
class OrderQueryService extends AbstractService
{
    /**
     * 卡密类业务线：direct-charge 的 recharge 订单和 card 订单都可能挂 order_recharges，
     * 只有这两条业务线才去查卡密（movie/express 目前不涉及）。
     */
    private const CARD_BUSINESS_LINES = ['recharge', 'card'];

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderRechargeDao $orderRechargeDao;

    #[Inject]
    protected Encryptor $encryptor;

    #[Inject]
    protected ExpressOrderPresenter $expressOrderPresenter;

    #[Inject]
    protected MovieOrderPresenter $movieOrderPresenter;

    /**
     * 调用方（App\Controller\OpenApi\OrderController）已经校验过 $orderNo/
     * $merchantOrderNo 里恰好有一个非空字符串。这里仍做防御性兜底：
     * 优先用 $orderNo（平台单号，全局唯一，比商户单号更精确）；$orderNo 为空
     * 才退到 $merchantOrderNo；两者都为空则视为查不到，直接返回 null，
     * 不抛异常——是否算「参数错误」由 Controller 的输入校验负责，这一层
     * 只关心「给定条件下查不查得到订单」。
     *
     * 查不到时返回 null，由 Controller 转成 ErrorCode::OrderNotFound。
     *
     * @return null|array{order_no: string, merchant_order_no: string, business_line: string,
     *     status: string, sale_price: string, frozen_amount: string, deducted_amount: null|string,
     *     refunded_amount: string, completed_at: null|string, fail_code: null|int, fail_reason: null|string,
     *     card_no?: string, card_pwd?: string, express?: null|array<string, mixed>}
     */
    public function find(Merchant $merchant, ?string $orderNo, ?string $merchantOrderNo): ?array
    {
        $order = null;
        if ($orderNo !== null && $orderNo !== '') {
            $order = $this->orderDao->findByOrderNoForMerchant($merchant->id, $orderNo);
        } elseif ($merchantOrderNo !== null && $merchantOrderNo !== '') {
            $order = $this->orderDao->findByMerchantOrderNoForMerchant($merchant->id, $merchantOrderNo);
        }

        if (! $order) {
            return null;
        }

        $result = [
            'order_no' => $order->order_no,
            'merchant_order_no' => $order->merchant_order_no,
            'business_line' => $order->business_line,
            'status' => $order->merchantFacingStatus(),
            'sale_price' => $order->sale_price,
            'frozen_amount' => $order->frozen_amount,
            'deducted_amount' => $order->deducted_amount,
            'refunded_amount' => $order->refunded_amount,
            'completed_at' => $order->completed_at?->toDateTimeString(),
        ] + ErrorCode::presentOrderFailure($order->fail_reason);

        if (in_array($order->business_line, self::CARD_BUSINESS_LINES, true)) {
            $this->appendCardSecrets($result, $order->id);
        }
        if ($order->business_line === ExpressOrderSettlementService::BUSINESS_LINE) {
            $result['express'] = $this->expressOrderPresenter->present((int) $order->id);
        }
        if ($order->business_line === MovieOrderSettlementService::BUSINESS_LINE) {
            $result['movie'] = $this->movieOrderPresenter->present((int) $order->id);
        }

        return $result;
    }

    /**
     * 直充类 recharge 订单没有卡号卡密（card_no/card_pwd 为 null），这里选择
     * 直接不在响应里放 card_no/card_pwd 这两个 key（而不是放 null 值），
     * 跟「这个字段对这个订单没有意义」语义一致，也让有卡密的订单和没卡密的
     * 订单在响应形状上可以直接用 array_key_exists 区分。
     */
    private function appendCardSecrets(array &$result, int $orderId): void
    {
        $recharge = $this->orderRechargeDao->find($orderId);
        if (! $recharge) {
            return;
        }

        if ($recharge->card_no !== null) {
            $result['card_no'] = $this->encryptor->decrypt($recharge->card_no);
        }

        if ($recharge->card_pwd !== null) {
            $result['card_pwd'] = $this->encryptor->decrypt($recharge->card_pwd);
        }
    }
}
