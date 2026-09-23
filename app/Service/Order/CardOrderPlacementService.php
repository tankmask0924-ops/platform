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

use App\Exception\OpenApiException;
use App\Model\Merchant;
use App\Model\Product;
use App\OpenApi\ErrorCode;

/**
 * 卡券下单编排（requirements.md 8.1「下单」的卡券一侧，话费参数不同、单独设计，
 * 见 `App\Service\Order\RechargeOrderPlacementService`；两者共享的路由/失败切换/
 * 幂等机制在 `App\Service\Order\AbstractOrderPlacementService`，见该类类注释）。
 *
 * 【`card_type` 决定 `recharge_account` 是否合法，本类唯一区别于话费的业务规则】
 * `products.card_type` 只有两种取值（`App\Model\Product::$card_type` 列注释，
 * kasushou.md"卡密"一节实测）：
 *   - `direct`（直充，例如游戏点卡直接充值到账号）：需要一个目标账号参数，
 *     跟话费的 `recharge_account` 同一种形状，任务要求直接沿用这个请求字段名，
 *     保持两条业务线参数命名一致；这条商品类型下必填。
 *   - `card_secret`（卡密，卡号+密码由商户拿去交给自己的终端用户）：
 *     kasushou.md"下单参数"一行原文"卡密商品不传"——没有任何动态参数，
 *     `recharge_account` 必须缺失，传了视为调用方对商品类型的理解有误，直接
 *     拒绝，不是静默忽略。
 * 校验放在 `validateRechargeAccountForCardType()`，在商品校验通过之后、建订单行
 * 之前执行——跟话费商品校验不通过时的既有约定一致：不创建任何 Order 行，直接抛
 * `OpenApiException(ErrorCode::InvalidParams)`。
 *
 * 【`isCardProduct: true`】卡速售驱动的 `placeOrder()`/`queryOrder()`/
 * `parseCallback()` 都要求这个业务线传 `true`（`KasushouStatusMapper` 内部靠它
 * 决定"卡密类商品必须等 `card_list` 真的到了才算成功"），由
 * `SupplierRouter`/`SupplierCallbackService` 按 `orders.business_line === 'card'` 传入。
 *
 * 【卡密落库】`direct` 类商品没有卡密（跟话费一样，只有 `recharge_account`）；
 * `card_secret` 类商品的卡号/卡密由 `App\Service\Order\OrderResultApplier::
 * applySuccess()` 在订单真正成功时从 `DriverResult::$cardList` 取出、加密后写进
 * `order_recharges.card_no`/`card_pwd`（共享逻辑，见该类类注释"卡密写入"一节），
 * 本类不重复实现，下单时建的 `order_recharges` 行只先写 `recharge_account`
 * （`card_secret` 类商品这里是 `null`）和返佣快照。
 */
class CardOrderPlacementService extends AbstractOrderPlacementService
{
    private const BUSINESS_LINE = 'card';

    private const ORDER_NO_PREFIX = 'C';

    private const CARD_TYPE_DIRECT = 'direct';

    /**
     * @return array{order_no: string, merchant_order_no: string, business_line: string,
     *     status: string, sale_price: string, frozen_amount: string, deducted_amount: null|string,
     *     refunded_amount: string, completed_at: null|string,
     *     fail_code: null|int, fail_reason: null|string}
     */
    public function place(
        Merchant $merchant,
        string $merchantOrderNo,
        int $productId,
        ?string $rechargeAccount,
        string $callbackUrl
    ): array {
        // 幂等重放快速路径必须在欠款拦截*之前*，理由跟话费一侧完全一致，见
        // RechargeOrderPlacementService::place() 对应位置的注释，不重复。
        $existing = $this->findIdempotentReplay($merchant, $merchantOrderNo);
        if ($existing !== null) {
            return $existing;
        }

        $this->assertBusinessSubscribed($merchant);
        $this->assertMerchantNotSuspended($merchant);

        $product = $this->validateProduct($productId);
        $this->validateRechargeAccountForCardType($product, $rechargeAccount);
        $this->assertProductHasSupplier($product);

        $order = $this->createOrderRow($merchant, $merchantOrderNo, $product->sale_price, $callbackUrl);
        if ($order === null) {
            return $this->resolveReplayAfterCreateRace($merchant, $merchantOrderNo);
        }

        $frozen = $this->balanceService->freeze($merchant->id, $order->id, $order->sale_price);
        if (! $frozen) {
            $this->handleFreezeFailure($order);
            return $this->toResponseArray($order);
        }

        $this->orderRechargeDao->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'recharge_account' => $rechargeAccount,
            'rebate_amount' => $this->rebateCalculator->calculate($product, $merchant->level_id),
        ]);

        $this->routeAndFinalize($order, $product, $rechargeAccount);

        return $this->toResponseArray($order);
    }

    protected function businessLine(): string
    {
        return self::BUSINESS_LINE;
    }

    protected function orderNoPrefix(): string
    {
        return self::ORDER_NO_PREFIX;
    }

    private function validateProduct(int $productId): Product
    {
        $product = $this->productDao->find($productId);
        if ($product === null) {
            throw new OpenApiException(ErrorCode::ProductNotFound);
        }

        if ($product->business_line !== self::BUSINESS_LINE) {
            throw new OpenApiException(ErrorCode::ProductBusinessLineMismatch, '商品不是卡券业务线');
        }

        if ($product->status !== 'on_shelf') {
            throw new OpenApiException(ErrorCode::ProductNotOnShelf);
        }

        return $product;
    }

    /**
     * 见类注释"`card_type` 决定 `recharge_account` 是否合法"一节。`card_type`
     * 只要不是 `direct` 就按"不接受动态参数"处理（含 `card_secret` 和任何理论上
     * 不该出现的其它取值），不是只白名单 `card_secret` 一个值——这条业务线唯一
     * 需要 `recharge_account` 的类型是 `direct`，其余一律拒绝携带这个参数。
     */
    private function validateRechargeAccountForCardType(Product $product, ?string $rechargeAccount): void
    {
        if ($product->card_type === self::CARD_TYPE_DIRECT) {
            if ($rechargeAccount === null) {
                throw new OpenApiException(ErrorCode::InvalidParams, '直充类卡券商品下单必须传 recharge_account');
            }
            return;
        }

        if ($rechargeAccount !== null) {
            throw new OpenApiException(ErrorCode::InvalidParams, '卡密类卡券商品不支持传 recharge_account');
        }
    }
}
