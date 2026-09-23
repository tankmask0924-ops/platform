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

use App\Dao\OrderDao;
use App\Dao\OrderRechargeDao;
use App\Dao\ProductDao;
use App\Exception\OpenApiException;
use App\Model\Merchant;
use App\Model\Order;
use App\Model\Product;
use App\OpenApi\ErrorCode;
use App\Service\AbstractService;
use App\Service\Merchant\BalanceService;
use App\Service\Merchant\SubscriptionService;
use App\Service\MerchantNotifyService;
use App\Service\Product\RebateCalculator;
use Hyperf\Database\Exception\QueryException;
use Hyperf\Di\Annotation\Inject;
use RuntimeException;

/**
 * 话费（`RechargeOrderPlacementService`）、卡券（`CardOrderPlacementService`）
 * 下单编排的共享部分（docs/modules.md 6.1「卡券下单（二期）」任务从
 * `RechargeOrderPlacementService` 抽出来的，requirements.md 7.1"话费与卡券下单"
 * 原文本身就说这两条业务线的下单机制——幂等、冻结后路由、只在明确失败才换供应商、
 * 尝试记录——是共享的，只有下单参数不同）。
 *
 * 【抽取边界，抽了什么、没抽什么】按任务要求，"genuinely business-line-agnostic"
 * 的部分整段搬进来：幂等重放查询、订单号生成与建单竞态处理、冻结、响应数组的形状。
 * **没有**抽的东西——因为它们本质上是业务线专属，不是"共享逻辑长在两个类里"：
 * 商品校验（`business_line`/`card_type` 取值不同）、`order_recharges` 行怎么建
 * （`recharge_account` 是否必填不同）、动态参数（`recharge_account`）本身的必填/
 * 禁止校验规则。这些留在各自子类的 `place()` 里，调用本类提供的模板方法拼起来。
 *
 * 【`?string $rechargeAccount` 为什么整段共享逻辑都收窄成"可为 null"】话费的
 * `recharge_account` 永远必填（非 null），卡券的直充类必填、卡密类禁止携带
 * （kasushou.md"卡密商品不传"）。共享的路由/失败切换循环不关心"这个字段该不该
 * 必填"这条业务规则本身，只关心"这次下单有没有一个动态参数要透传给供应商"——
 * 用 `null` 表达"没有"，直接决定路由传给驱动的 `attach` 数组
 * 是否为空数组，不需要额外的布尔开关。
 *
 * 【路由】按优先级选供应商、明确失败换下一家、切换时长、尝试记录都在
 * `App\Service\Order\SupplierRouter`，异步回调失败后的继续切换也走它；本类只负责
 * 下单前预检（没有可用供应商直接拒绝，不冻结）和同步下单时调用一次。卡速售驱动
 * 需要的 `isCardProduct` 由路由按 `orders.business_line` 决定。
 *
 * 【`orderNoPrefix()`】只是为了保留 `RechargeOrderPlacementService` 原有的
 * `'R'` 前缀完全不变（回归要求），卡券另起一个 `'C'` 前缀，纯粹为了人工在数据库/
 * 日志里能一眼分清订单属于哪条业务线，不是任何约束要求的格式。
 */
abstract class AbstractOrderPlacementService extends AbstractService
{
    private const MAX_ORDER_NO_RETRIES = 5;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderRechargeDao $orderRechargeDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected BalanceService $balanceService;

    #[Inject]
    protected SubscriptionService $subscriptionService;

    #[Inject]
    protected RebateCalculator $rebateCalculator;

    #[Inject]
    protected MerchantNotifyService $merchantNotifyService;

    #[Inject]
    protected SupplierRouter $supplierRouter;

    /**
     * `orders.business_line` 该写哪个值，见类注释。
     */
    abstract protected function businessLine(): string;

    /**
     * `generateOrderNo()` 用的单字符前缀，见类注释。
     */
    abstract protected function orderNoPrefix(): string;

    /**
     * 幂等重放快速路径：明显的重复请求（比如商户网络库自己重试）直接返回已有
     * 订单状态。真正兜底防止"同一次下单触发两次 freeze()"的是
     * `createOrderRow()`/`resolveReplayAfterCreateRace()` 里数据库唯一约束那道
     * 防线，见下面两个方法。
     */
    protected function findIdempotentReplay(Merchant $merchant, string $merchantOrderNo): ?array
    {
        $existing = $this->orderDao->findByMerchantOrderNoForMerchant($merchant->id, $merchantOrderNo);

        return $existing !== null ? $this->toResponseArray($existing) : null;
    }

    /**
     * requirements.md 4.2：业务线开通审核通过后才能下单。跟欠款拦截一样放在幂等重放*之后*——
     * 之前已经下成功的单，重新提交同一个 merchant_order_no 仍然原样返回。
     */
    protected function assertBusinessSubscribed(Merchant $merchant): void
    {
        if (! $this->subscriptionService->isSubscribed((int) $merchant->id, $this->businessLine())) {
            throw new OpenApiException(ErrorCode::BusinessNotSubscribed);
        }
    }

    /**
     * requirements.md 4.5「负余额」：可用余额 < 0 时暂停该商户所有下单，直到充值
     * 补足到 ≥ 0 后自动恢复。调用方必须保证这一步发生在幂等重放查询*之后*、
     * 校验商品/建单/冻结*之前*——具体理由见
     * `RechargeOrderPlacementService::place()` 里对应位置的注释（两条业务线
     * 下单顺序上的约束完全一致，不必在这里重复整段论证）。
     */
    protected function assertMerchantNotSuspended(Merchant $merchant): void
    {
        if ($this->balanceService->isSuspended($merchant)) {
            throw new OpenApiException(ErrorCode::MerchantSuspended);
        }
    }

    /**
     * 建订单行。返回 null 表示这次 `Order::create()` 撞上了
     * `(merchant_id, merchant_order_no)` 唯一约束——调用方据此判断"输掉了并发建单竞态"，
     * 必须调用 `resolveReplayAfterCreateRace()` 去重新查那笔已存在的订单并原样返回，
     * 绝不能继续往下调用 `freeze()`。
     *
     * **收的是售价而不是 Product**：话费、卡券的售价来自本地商品
     * （`products.sale_price`），快递的售价是下单时按最新运费成本 + 加价规则现算的
     * （requirements.md 7.2「下单前重新向供应商检测价格」），根本没有 Product 行。
     * 幂等重放、订单号冲突重试、竞态收尾这些逻辑对三条业务线是一样的，所以参数收窄到
     * 它真正需要的那一个值。
     *
     * `cost_price` 用 `$costPrice` 传进来的预估值（话费/卡券传 '0.00' 占位——真实成本
     * 要等真的调用了供应商才知道；快递传查价拿到的成本，下单前它就是已知的）。
     */
    protected function createOrderRow(
        Merchant $merchant,
        string $merchantOrderNo,
        string $salePrice,
        string $callbackUrl,
        string $costPrice = '0.00'
    ): ?Order {
        for ($attempt = 0; $attempt < self::MAX_ORDER_NO_RETRIES; ++$attempt) {
            try {
                return $this->orderDao->create([
                    'order_no' => $this->generateOrderNo(),
                    'merchant_id' => $merchant->id,
                    'merchant_order_no' => $merchantOrderNo,
                    'business_line' => $this->businessLine(),
                    'status' => 'processing',
                    'sale_price' => $salePrice,
                    'cost_price' => $costPrice,
                    'frozen_amount' => $salePrice,
                    'refunded_amount' => '0.00',
                    'callback_url' => $callbackUrl,
                ]);
            } catch (QueryException $e) {
                $message = $e->getMessage();

                // 顺序很重要：'merchant_order_no' 作为字符串本身就包含 'order_no'，
                // 必须先判更具体的那个唯一键名，否则永远走不到这个分支。
                if (str_contains($message, 'merchant_order_no')) {
                    return null;
                }

                if (str_contains($message, 'order_no')) {
                    // order_no 单键冲突（理论上概率极低）：换一个重新生成的号重试。
                    continue;
                }

                // 不认识的约束冲突：不是本方法能处理的场景，原样抛出，不静默吞掉。
                throw $e;
            }
        }

        throw new RuntimeException(sprintf(
            '%s: failed to generate a unique order_no after %d attempts.',
            static::class,
            self::MAX_ORDER_NO_RETRIES
        ));
    }

    /**
     * `createOrderRow()` 返回 null（输掉并发建单竞态）之后的收尾：重新查那笔
     * 已存在的订单并原样返回。理论上一定能查到（刚刚才撞过唯一约束），查不到就是
     * 系统异常，防御性地抛出，不静默返回空结果。
     */
    protected function resolveReplayAfterCreateRace(Merchant $merchant, string $merchantOrderNo): array
    {
        $raced = $this->orderDao->findByMerchantOrderNoForMerchant($merchant->id, $merchantOrderNo);
        if ($raced === null) {
            throw new RuntimeException(static::class . ': lost create race but replay order not found.');
        }

        return $this->toResponseArray($raced);
    }

    /**
     * 【余额不足是否持久化订单行】选择：持久化，标记为失败——订单行必须先于
     * `freeze()` 存在（见 `createOrderRow()`），所以订单行此时必然已经落库，让它
     * 保留下来、状态标成 `failed`、`fail_reason` 写清楚，商户拿订单号能查到明确
     * 原因，比凭空消失对商户更友好。`frozen_amount` 改写为 `'0.00'`——
     * `freeze()` 返回 false 时事务里没有任何写操作，真实一分钱都没冻结。
     */
    protected function handleFreezeFailure(Order $order): void
    {
        $order->fill([
            'status' => 'failed',
            'frozen_amount' => '0.00',
            'fail_reason' => ErrorCode::InsufficientBalance->message(),
            'finished_at' => date('Y-m-d H:i:s'),
        ])->save();

        $this->merchantNotifyService->notify($order->id);
    }

    /**
     * 订单已建好、已冻结：交给 SupplierRouter 按固定优先级路由并落结果。
     * `$rechargeAccount` 为 null 表示这次下单没有动态参数要透传给供应商（卡密类卡券）。
     */
    protected function routeAndFinalize(Order $order, Product $product, ?string $rechargeAccount): void
    {
        $this->supplierRouter->routeNewOrder($order, $product, $rechargeAccount);
    }

    /**
     * requirements.md 6.5：没有可用供应商时下单接口直接返回失败，不建单、不冻结余额。
     * 调用方必须放在商品校验之后、建订单行之前。
     */
    protected function assertProductHasSupplier(Product $product): void
    {
        if (! $this->supplierRouter->hasEligibleSupplier($product)) {
            throw new OpenApiException(ErrorCode::ProductUnavailable);
        }
    }

    /**
     * @return array{order_no: string, merchant_order_no: string, business_line: string,
     *     status: string, sale_price: string, frozen_amount: string, deducted_amount: null|string,
     *     refunded_amount: string, completed_at: null|string,
     *     fail_code: null|int, fail_reason: null|string}
     */
    protected function toResponseArray(Order $order): array
    {
        return [
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
    }

    private function generateOrderNo(): string
    {
        return $this->orderNoPrefix() . date('YmdHis') . random_int(100000, 999999);
    }
}
