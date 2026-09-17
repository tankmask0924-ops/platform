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
 * 话费下单编排（requirements.md 8.1「下单」的话费一侧，卡券参数不同、单独设计，
 * 见 `App\Service\Order\CardOrderPlacementService`）。这是这个代码库第一条把
 * 「商品校验 -> 冻结 -> 路由到供应商 -> 判定成败 -> 扣款/解冻 -> 回调通知」串起来
 * 的完整下单链路，编排目标本身比任何一步内部的业务规则更重要。
 *
 * 【本类现在只剩话费专属的部分】docs/modules.md 6 节"卡券下单（二期）"任务把
 * "genuinely business-line-agnostic"的部分（幂等、订单号生成/建单竞态、冻结、
 * 按优先级路由供应商 + 只在明确失败换供应商、尝试记录、结果收尾）整段抽到
 * `App\Service\Order\AbstractOrderPlacementService`，本类只保留话费专属的三件
 * 事：商品校验（`business_line = 'recharge'`）、`order_recharges` 行怎么建
 * （`recharge_account` 永远必填）、`place()` 把这些跟共享的模板方法拼起来。
 * 抽取前后的行为通过 `RechargeOrderPlacementServiceTest` 全套既有用例原样验证
 * （未修改任何断言）没有变化。
 *
 * 【`cost_price` 建单时机的占位值】见 `AbstractOrderPlacementService::
 * createOrderRow()` 类注释——本类不重复。
 *
 * 【幂等 + 冻结最多一次】见 `AbstractOrderPlacementService` 类注释与
 * `place()` 方法内联注释——核心保证（`orders` 表 `(merchant_id,
 * merchant_order_no)` 唯一约束 + "建订单必须先于冻结"的调用顺序）搬进了共享基类，
 * 本类 `place()` 仍然显式按这个顺序调用各个模板方法，顺序本身不是基类能替调用方
 * 保证的事。
 *
 * 【返佣，5.4】本类只把已经查过的 `Product` 原样透传给
 * `OrderResultApplier::apply()`（经由 `routeAndFinalize()`），真正"订单成功时
 * 生成待到账返佣记录"的逻辑在 `OrderResultApplier` 里，不是本类职责。
 *
 * 【测试方式】跟基类共用同一套 `SupplierDriverFactory` 容器 swap 手法，见
 * `RechargeOrderPlacementServiceTest` 类注释。
 *
 * 【范围外，见任务说明】商户业务线开通校验、`Processing`/`Unknown` 结果的异步
 * 推进、熔断、供应商余额预警——一律不在本类职责内。
 */
class RechargeOrderPlacementService extends AbstractOrderPlacementService
{
    private const BUSINESS_LINE = 'recharge';

    private const ORDER_NO_PREFIX = 'R';

    /**
     * @return array{order_no: string, merchant_order_no: string, business_line: string,
     *     status: string, sale_price: string, frozen_amount: string, deducted_amount: null|string,
     *     refunded_amount: string, supplier_order_no: null|string, completed_at: null|string,
     *     fail_reason: null|string}
     */
    public function place(
        Merchant $merchant,
        string $merchantOrderNo,
        int $productId,
        string $rechargeAccount,
        string $callbackUrl
    ): array {
        // 幂等重放快速路径必须在欠款拦截*之前*：一个商户在欠款之前已经成功的订单，
        // 重新提交同一个 merchant_order_no 必须原样拿回那笔旧订单的状态，不能因为
        // 商户现在恰好欠款就被拦下来。
        $existing = $this->findIdempotentReplay($merchant, $merchantOrderNo);
        if ($existing !== null) {
            return $existing;
        }

        // requirements.md 4.5「负余额」：必须在幂等重放快速路径*之后*、校验商品/
        // 生成 order_no/创建 Order 行/调用 freeze() *之前*——这是一次真正的新下单
        // 尝试，商户欠款状态下应该被干净、快速地拒绝，不留下任何 Order 行或余额
        // 变动，不应该走到后面任何一步才发现拒单。
        $this->assertMerchantNotSuspended($merchant);

        $product = $this->validateProduct($productId);

        $order = $this->createOrderRow($merchant, $merchantOrderNo, $product, $callbackUrl);
        if ($order === null) {
            // 建单时撞上了 (merchant_id, merchant_order_no) 唯一约束：输掉了并发
            // 建单竞态，必须原样返回那笔订单的状态，绝不能再往下走去调用 freeze()。
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

    protected function isCardProduct(): bool
    {
        return false;
    }

    private function validateProduct(int $productId): Product
    {
        $product = $this->productDao->find($productId);
        if ($product === null) {
            throw new OpenApiException(ErrorCode::ProductNotFound);
        }

        if ($product->business_line !== self::BUSINESS_LINE) {
            throw new OpenApiException(ErrorCode::ProductBusinessLineMismatch, '商品不是话费业务线');
        }

        if ($product->status !== 'on_shelf') {
            throw new OpenApiException(ErrorCode::ProductNotOnShelf);
        }

        return $product;
    }
}
