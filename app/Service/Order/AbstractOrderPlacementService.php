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

use App\Dao\OrderAttemptDao;
use App\Dao\OrderDao;
use App\Dao\OrderRechargeDao;
use App\Dao\ProductDao;
use App\Dao\SupplierDao;
use App\Dao\SupplierProductDao;
use App\Model\Merchant;
use App\Model\Order;
use App\Model\Product;
use App\Model\Supplier;
use App\Model\SupplierProduct;
use App\Service\AbstractService;
use App\Service\Merchant\BalanceService;
use App\Service\MerchantNotifyService;
use App\Service\Product\RebateCalculator;
use App\Supplier\DriverResult;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\Database\Exception\QueryException;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use RuntimeException;
use Throwable;

/**
 * 话费（`RechargeOrderPlacementService`）、卡券（`CardOrderPlacementService`）
 * 下单编排的共享部分（docs/modules.md 6.1「卡券下单（二期）」任务从
 * `RechargeOrderPlacementService` 抽出来的，requirements.md 7.1"话费与卡券下单"
 * 原文本身就说这两条业务线的下单机制——幂等、冻结后路由、只在明确失败才换供应商、
 * 尝试记录——是共享的，只有下单参数不同）。
 *
 * 【抽取边界，抽了什么、没抽什么】按任务要求，"genuinely business-line-agnostic"
 * 的部分整段搬进来：幂等重放查询、订单号生成与建单竞态处理、冻结、按优先级路由
 * 供应商 + 只在明确失败换下一家的循环、每次尝试记 `OrderAttempt`、结果收尾时
 * 预置 `cost_price` 估算值再交给 `OrderResultApplier`、响应数组的形状。
 * **没有**抽的东西——因为它们本质上是业务线专属，不是"共享逻辑长在两个类里"：
 * 商品校验（`business_line`/`card_type` 取值不同）、`order_recharges` 行怎么建
 * （`recharge_account` 是否必填不同）、动态参数（`recharge_account`）本身的必填/
 * 禁止校验规则。这些留在各自子类的 `place()` 里，调用本类提供的模板方法拼起来。
 *
 * 【`?string $rechargeAccount` 为什么整段共享逻辑都收窄成"可为 null"】话费的
 * `recharge_account` 永远必填（非 null），卡券的直充类必填、卡密类禁止携带
 * （kasushou.md"卡密商品不传"）。共享的路由/失败切换循环不关心"这个字段该不该
 * 必填"这条业务规则本身，只关心"这次下单有没有一个动态参数要透传给供应商"——
 * 用 `null` 表达"没有"，直接决定 `attemptSupplier()` 传给驱动的 `attach` 数组
 * 是否为空数组，不需要额外的布尔开关。
 *
 * 【`isCardProduct()` 抽象方法】卡速售驱动的 `placeOrder()`/`queryOrder()`/
 * `parseCallback()` 都要求调用方显式传 `isCardProduct`（`KasushouStatusMapper`
 * 内部靠这个标志决定"卡密类商品必须等到 `card_list` 真的到了才算成功"），这个值
 * 只由业务线决定、不随请求变化，收窄成一个每个子类各答一次的抽象方法。
 *
 * 【`orderNoPrefix()`】只是为了保留 `RechargeOrderPlacementService` 原有的
 * `'R'` 前缀完全不变（回归要求），卡券另起一个 `'C'` 前缀，纯粹为了人工在数据库/
 * 日志里能一眼分清订单属于哪条业务线，不是任何约束要求的格式。
 */
abstract class AbstractOrderPlacementService extends AbstractService
{
    private const MAX_ORDER_NO_RETRIES = 5;

    /**
     * App\Supplier\UnifiedResult 到 order_attempts.result 字符串的映射，跟
     * App\Model\OrderAttempt 类注释里记录的是同一份约定，改动请两处一起改。
     */
    private const RESULT_MAP = [
        'Success' => 'success',
        'DefiniteFailure' => 'failed',
        'Processing' => 'processing',
        'Unknown' => 'unknown',
    ];

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderRechargeDao $orderRechargeDao;

    #[Inject]
    protected OrderAttemptDao $orderAttemptDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierProductDao $supplierProductDao;

    #[Inject]
    protected BalanceService $balanceService;

    #[Inject]
    protected RebateCalculator $rebateCalculator;

    #[Inject]
    protected MerchantNotifyService $merchantNotifyService;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected OrderResultApplier $orderResultApplier;

    /**
     * `orders.business_line` 该写哪个值，见类注释。
     */
    abstract protected function businessLine(): string;

    /**
     * `generateOrderNo()` 用的单字符前缀，见类注释。
     */
    abstract protected function orderNoPrefix(): string;

    /**
     * 每次驱动调用（`placeOrder()`）该传的 `isCardProduct`，见类注释。
     */
    abstract protected function isCardProduct(): bool;

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
     * requirements.md 4.5「负余额」：可用余额 < 0 时暂停该商户所有下单，直到充值
     * 补足到 ≥ 0 后自动恢复。调用方必须保证这一步发生在幂等重放查询*之后*、
     * 校验商品/建单/冻结*之前*——具体理由见
     * `RechargeOrderPlacementService::place()` 里对应位置的注释（两条业务线
     * 下单顺序上的约束完全一致，不必在这里重复整段论证）。
     */
    protected function assertMerchantNotSuspended(Merchant $merchant): void
    {
        if ($this->balanceService->isSuspended($merchant)) {
            throw new HttpException(422, '商户当前存在欠款，已暂停下单，请充值补足欠款后再试');
        }
    }

    /**
     * 建订单行，`cost_price` 用 '0.00' 占位（真实成本要等真的调用了供应商才知道）。
     * 返回 null 表示这次 `Order::create()` 撞上了 `(merchant_id, merchant_order_no)`
     * 唯一约束——调用方据此判断"输掉了并发建单竞态"，必须调用
     * `resolveReplayAfterCreateRace()` 去重新查那笔已存在的订单并原样返回，
     * 绝不能继续往下调用 `freeze()`。
     */
    protected function createOrderRow(Merchant $merchant, string $merchantOrderNo, Product $product, string $callbackUrl): ?Order
    {
        for ($attempt = 0; $attempt < self::MAX_ORDER_NO_RETRIES; ++$attempt) {
            try {
                return $this->orderDao->create([
                    'order_no' => $this->generateOrderNo(),
                    'merchant_id' => $merchant->id,
                    'merchant_order_no' => $merchantOrderNo,
                    'business_line' => $this->businessLine(),
                    'status' => 'processing',
                    'sale_price' => $product->sale_price,
                    'cost_price' => '0.00',
                    'frozen_amount' => $product->sale_price,
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
            'fail_reason' => '商户可用余额不足，下单前冻结失败',
            'finished_at' => date('Y-m-d H:i:s'),
        ])->save();

        $this->merchantNotifyService->notify($order->id);
    }

    /**
     * 按优先级迭代 supplier_products 映射行，调用驱动下单，落 OrderAttempt，
     * 按 requirements.md 6.2「只有明确失败才换下一个供应商」决定继续还是停止，
     * 最后把结果落到 Order 行上。`$rechargeAccount` 为 null 表示这次下单没有
     * 动态参数要透传给供应商（卡密类卡券），见类注释。
     */
    protected function routeAndFinalize(Order $order, Product $product, ?string $rechargeAccount): void
    {
        $mappings = $this->supplierProductDao->listForProduct($product->id);

        $attemptNo = 0;
        $lastMapping = null;
        $lastDriverResult = null;

        foreach ($mappings as $mapping) {
            if (! $this->isMappingEligible($mapping)) {
                continue;
            }

            $supplier = $this->supplierDao->find($mapping->supplier_id);
            if ($supplier === null || $supplier->status !== 'active') {
                continue;
            }

            ++$attemptNo;
            $driverResult = $this->attemptSupplier($order, $mapping, $supplier, $rechargeAccount, $attemptNo);
            $lastMapping = $mapping;
            $lastDriverResult = $driverResult;

            if ($driverResult->result !== UnifiedResult::DefiniteFailure) {
                // 非明确失败（成功/处理中/未知）：这个供应商拿下了这笔订单，停止路由。
                break;
            }
        }

        $this->finalizeOrder($order, $product, $lastMapping, $lastDriverResult);
    }

    /**
     * 占位实现：供应商回调接收路由（`/notify/{code}`）本身已经建好
     * （`App\Controller\NotifySupplierController`），但这里仍然没有接入"给每个
     * 供应商生成带随机令牌的回调地址"这个机制（不在本类任何一个子类对应任务的
     * 范围内），这里只需要给 `KasushouDriver::placeOrder()` 的 `url` 参数一个
     * 语法合法的值（卡速售下单请求体的 `url` 字段不能省），不影响同步下单结果
     * 判定。
     */
    protected function buildSupplierNotifyUrl(Supplier $supplier): string
    {
        return sprintf('https://platform.example.com/notify/%s', $supplier->code);
    }

    /**
     * @return array{order_no: string, merchant_order_no: string, business_line: string,
     *     status: string, sale_price: string, frozen_amount: string, deducted_amount: null|string,
     *     refunded_amount: string, supplier_order_no: null|string, completed_at: null|string,
     *     fail_reason: null|string}
     */
    protected function toResponseArray(Order $order): array
    {
        return [
            'order_no' => $order->order_no,
            'merchant_order_no' => $order->merchant_order_no,
            'business_line' => $order->business_line,
            'status' => $order->status,
            'sale_price' => $order->sale_price,
            'frozen_amount' => $order->frozen_amount,
            'deducted_amount' => $order->deducted_amount,
            'refunded_amount' => $order->refunded_amount,
            'supplier_order_no' => $order->supplier_order_no,
            'completed_at' => $order->completed_at?->toDateTimeString(),
            'fail_reason' => $order->fail_reason,
        ];
    }

    private function generateOrderNo(): string
    {
        return $this->orderNoPrefix() . date('YmdHis') . random_int(100000, 999999);
    }

    /**
     * requirements.md 6.3 + 6.5：只路由到在售、（不限库存或库存>0）的映射行，
     * 且所属供应商本身状态是 active——被禁用的供应商不接新单。
     */
    private function isMappingEligible(SupplierProduct $mapping): bool
    {
        if ($mapping->status !== 'active') {
            return false;
        }

        if ($mapping->stock !== null && $mapping->stock <= 0) {
            return false;
        }

        return true;
    }

    private function attemptSupplier(
        Order $order,
        SupplierProduct $mapping,
        Supplier $supplier,
        ?string $rechargeAccount,
        int $attemptNo
    ): DriverResult {
        $externalOrderNo = $order->order_no . '-' . $attemptNo;

        try {
            $driver = $this->supplierDriverFactory->build($supplier);

            $attach = $rechargeAccount !== null
                ? [$this->resolveAttachField($mapping) => $rechargeAccount]
                : [];

            $driverResult = $driver->placeOrder(
                externalOrderNo: $externalOrderNo,
                supplierGoodsId: $mapping->supplier_product_code,
                safePrice: $order->sale_price,
                notifyUrl: $this->buildSupplierNotifyUrl($supplier),
                attach: $attach,
                quantity: 1,
                isCardProduct: $this->isCardProduct(),
            );
        } catch (Throwable $e) {
            // 驱动构造/解密失败（比如供应商配置损坏）：跟"拿不准一律 Unknown，
            // 不猜明确失败"是同一个原则的延伸——一个平台侧的配置问题不该被误判成
            // "供应商明确拒单"进而永远跳过这个供应商,也不该让整个下单请求 500。
            $driverResult = new DriverResult(
                result: UnifiedResult::Unknown,
                failReason: static::class . ': failed to dispatch to supplier driver: ' . $e->getMessage(),
                rawRequest: ['external_orderno' => $externalOrderNo],
            );
        }

        $this->orderAttemptDao->create([
            'order_id' => $order->id,
            'supplier_id' => $supplier->id,
            'attempt_no' => $attemptNo,
            'result' => self::RESULT_MAP[$driverResult->result->name],
            'fail_reason' => $driverResult->failReason,
            'request_snapshot' => $driverResult->rawRequest,
            'response_snapshot' => $driverResult->rawResponse,
        ]);

        return $driverResult;
    }

    /**
     * supplier_products.param_mapping 形如 `{"recharge_account": "<供应商侧字段名>"}`
     * ——两条业务线的动态参数在请求层都叫 `recharge_account`（任务里明确要求卡券
     * 直充类沿用这个字段名，保持一致性），所以这里的映射键名不需要按业务线区分。
     * 缺失/为空一律退回直接用 'recharge_account' 作为字段名——这是本方法的
     * fallback 行为，不是猜供应商真实需要的字段名。
     */
    private function resolveAttachField(SupplierProduct $mapping): string
    {
        $mapped = $mapping->param_mapping['recharge_account'] ?? null;

        return is_string($mapped) && $mapped !== '' ? $mapped : 'recharge_account';
    }

    /**
     * "只有明确失败才换下一个供应商"这条失败换供应商的循环逻辑本身在
     * `routeAndFinalize()` 里，这里只做"路由循环选出的最终结果该怎么落到订单上"
     * 这一步单次判断，状态转换+余额+通知那部分共享逻辑已经抽到
     * `App\Service\Order\OrderResultApplier`（另一个调用方是
     * `App\Service\Order\SupplierCallbackService`），这里只负责"一次都没试成"
     * 这个 `OrderResultApplier` 管不到的特殊分支（连 `DriverResult`/供应商都不
     * 存在，没法调用 `apply()`），以及把这次尝试对应映射行的估算成本价预置到
     * 订单上。`$product` 是下单时已经查过的商品行，直接透传给
     * `OrderResultApplier::apply()`，成功时用来生成返佣待到账记录
     * （requirements.md 5.4），不需要 `OrderResultApplier` 再反查一次。
     */
    private function finalizeOrder(Order $order, Product $product, ?SupplierProduct $mapping, ?DriverResult $driverResult): void
    {
        if ($mapping === null || $driverResult === null) {
            // 一次都没试成——要么这个商品压根没有映射行，要么全部都被状态/库存/
            // 供应商禁用过滤掉了。等效于"路由后全部明确失败"，且没有供应商/驱动
            // 结果可言，不经过 OrderResultApplier（它的签名要求一个真实的
            // DriverResult + supplierId，这里两者都没有）。
            $this->finalizeAsNoSupplierAvailable($order);
            return;
        }

        // 预置这次尝试对应映射行的估算成本价（只改内存属性，不 save()）：
        // OrderResultApplier::apply() 内部统一 save() 时会把它跟状态字段一起写进
        // 同一条 UPDATE——Success 分支如果驱动给了 actualCost 会覆盖掉这个估算值，
        // DefiniteFailure/Processing/Unknown 分支保留它作为最终 cost_price，跟被
        // 抽取前的行为完全一致。
        $order->fill(['cost_price' => $mapping->cost_price]);

        $this->orderResultApplier->apply($order, $driverResult, $mapping->supplier_id, $product);
    }

    private function finalizeAsNoSupplierAvailable(Order $order): void
    {
        $order->fill([
            'status' => 'failed',
            'cost_price' => '0.00',
            'fail_reason' => '无可用供应商',
            'finished_at' => date('Y-m-d H:i:s'),
        ])->save();

        $this->balanceService->unfreeze($order->merchant_id, $order->id, $order->frozen_amount);
        $this->merchantNotifyService->notify($order->id);
    }
}
