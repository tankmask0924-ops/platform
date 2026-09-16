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
 * 话费下单编排（requirements.md 8.1「下单」的话费一侧，卡券参数不同，单独设计，
 * 不在这次任务范围）。这是这个代码库第一条把「商品校验 -> 冻结 -> 路由到供应商 ->
 * 判定成败 -> 扣款/解冻 -> 回调通知」串起来的完整下单链路，编排目标本身比任何一步
 * 内部的业务规则更重要。
 *
 * 下面把几个没有现成代码可抄、必须现场决定的关键设计点记录清楚：
 *
 * 【幂等 + 冻结最多一次 —— 全篇最重要的正确性属性】App\Service\Merchant\BalanceService::
 * freeze() 类注释明确写了它自己不做同订单重复调用防护，这个责任移交给本类。做法：
 * 数据库层面 `orders` 表有 `unique(['merchant_id', 'merchant_order_no'])`
 * （见 migrations/2026_09_14_091800_create_orders_table.php），本类保证
 * `Order::create()`（订单落库）严格发生在 `BalanceService::freeze()` 之前
 * （createOrderRow() -> place() 里先建订单，冻结成功才走到下一步）。这样两个并发
 * 的重复请求（同一 merchant_id + merchant_order_no）里，只有一个能把 Order 行插
 * 成功，另一个会在 `Order::create()` 这一步就被数据库唯一约束挡下（抛
 * `QueryException`），根本走不到 `freeze()` 调用那一行代码——不是"业务逻辑判断后
 * 决定不调用"，是物理上不可能执行到那一行。请求最前面另外做了一次
 * `findByMerchantOrderNoForMerchant()` 快速路径查询，只是为了让明显的重复请求
 * （比如商户网络库自己重试）不用绕一圈插入失败才发现，不是这条正确性保证本身
 * 依赖的东西——真正兜底的是数据库唯一约束 + 「建订单必须先于冻结」这个顺序。
 *
 * 【cost_price 建单时机的占位值】`orders.cost_price` 是 `decimal(10,2)` 非空列，
 * 但订单刚创建时还没有任何供应商响应，真实成本未知。这里选择建单时先填 `'0.00'`
 * 占位，等真的调用了某个供应商（不管这次调用最终成功/失败/处理中/未知）才把
 * `cost_price` 更新成那次尝试对应映射行的 `supplier_products.cost_price`
 * （成功时优先用 `DriverResult::$actualCost`，供应商没给就退回映射行成本价）。
 * 如果全程没有一个供应商映射行满足路由条件（一次都没试），`cost_price` 就保持
 * 占位的 `'0.00'`——这正好符合语义：没有产生任何真实成本。
 *
 * 【余额不足是否持久化订单行】选择：持久化，标记为失败。原因是上面那条冻结顺序
 * 的约束是硬性的——订单行必须先于 `freeze()` 存在，所以"从不落一个没机会的订单"
 * 这个选项在设计上不成立（落了订单才能去调用 freeze，freeze 失败已经是后验结果）。
 * 既然订单行必然已经存在，让它保留下来、状态标成 `failed`、`fail_reason` 写清楚
 * "余额不足"，商户拿商户订单号或平台单号去查这笔订单能看到明确原因，比让它凭空
 * 消失（商户单号查不到、以为请求没送达）对商户更友好。`frozen_amount` 在这条路径
 * 上从建单时的占位值（等于 `sale_price`，见 createOrderRow()）改写为 `'0.00'`——
 * `freeze()` 返回 false 时事务里没有任何写操作（见其类注释），真实一分钱都没冻结，
 * `frozen_amount` 如实反映"实际冻结了多少"而不是"原本打算冻结多少"。
 *
 * 【order_no 生成 + 冲突重试】格式 `R` + 14 位时间戳（`YmdHis`，到秒）+ 6 位随机
 * 数字，共 21 位，落在 `orders.order_no varchar(32)` 范围内，人可读、大致按时间
 * 排序，方便日志/数据库里肉眼核对。`orders_order_no_unique` 理论上仍可能撞（哪怕
 * 概率极低），createOrderRow() 撞了就重新生成重试，有限次数（`MAX_ORDER_NO_RETRIES`）
 * 后放弃并抛异常，不假设"一个随机串肯定不会撞"。
 *
 * 【供应商驱动派发】派发逻辑本身在 App\Supplier\SupplierDriverFactory 里（含
 * 目前只有卡速售一个驱动实现、暂不建 `DriverInterface` 的理由，见该类类注释）。
 * 本类只通过 `#[Inject]` 持有一个 SupplierDriverFactory 实例并调用其 `build()`。
 * `suppliers.config` 对 `kasushou` 驱动的 JSON 形状约定同样记录在
 * SupplierDriverFactory 类注释里。
 *
 * 【测试方式】SupplierDriverFactory 是 Hyperf DI 用 `#[Inject]` 属性注入的普通依赖，
 * 测试替身直接用 Hyperf\Testing\TestCase 自带的容器 swap——
 * `$this->instance(SupplierDriverFactory::class, Mockery::mock(...))`，在解析
 * 本类之前调用即可，跟 test/Cases/Job/NotifyMerchantJobTest.php 把
 * Hyperf\AsyncQueue\Driver\DriverFactory 换成 Mockery 双重是同一个模式，不需要
 * 任何生产代码专用的测试 setter/hook。
 *
 * 【范围外，见任务说明】商户业务线开通校验（4.2，跟"话费商品列表"任务同样的限制）、
 * `Processing`/`Unknown` 结果的异步推进（等回调路由或定时查询任务，两者都还没建）、
 * 供应商侧回调地址的真实实现（`buildSupplierNotifyUrl()` 是占位，见该方法注释）、
 * 熔断（6.6）、供应商余额预警（-1 状态）——一律不在本类职责内。
 *
 * 【返佣，5.4】本类只把已经查过的 `Product` 原样透传给
 * `OrderResultApplier::apply()`，真正"订单成功时生成待到账返佣记录"的逻辑在
 * `OrderResultApplier` 里（跟"状态转换 + 余额 + 通知"共用同一个成功分支，见该类
 * 类注释），不是本类职责——`order_recharges.rebate_amount` 仍然只是下单那一刻的
 * 返佣金额快照（供商户对账参考），不代表真的返佣，真正生效的 `merchant_rebates`
 * 记录由 `OrderResultApplier` 在订单真正成功时另外生成。
 */
class RechargeOrderPlacementService extends AbstractService
{
    private const BUSINESS_LINE = 'recharge';

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
        // 幂等重放快速路径：明显的重复请求（比如商户网络库自己重试）直接返回已有
        // 订单状态，不再往下走。真正兜底防止"同一次下单触发两次 freeze()"的是
        // createOrderRow() 里数据库唯一约束那道防线，见类注释。
        $existing = $this->orderDao->findByMerchantOrderNoForMerchant($merchant->id, $merchantOrderNo);
        if ($existing !== null) {
            return $this->toResponseArray($existing);
        }

        // requirements.md 4.5「负余额」：可用余额 < 0 时暂停该商户所有下单，直到
        // 充值补足到 ≥ 0（`BalanceService::persistBalance()` 清空 `debt_since`）
        // 才自动恢复。放在这个位置很重要：必须在上面的幂等重放快速路径*之后*——
        // 一个商户在欠款之前已经成功的订单，重新提交同一个 merchant_order_no
        // 必须原样拿回那笔旧订单的状态，不能因为商户现在恰好欠款就被这里拦下来
        // （那会把一个纯粹的幂等重放，误判成一次新的、该拒绝的下单请求）；但必须
        // 在校验商品、生成 order_no、创建 Order 行、调用 freeze() 之前——这是一次
        // 真正的新下单尝试，商户欠款状态下应该被干净、快速地拒绝，不留下任何
        // Order 行或余额变动，不应该走到后面任何一步才发现拒单。
        //
        // 直接读 `debt_since !== null` 而不是重新比较 `available_balance` 跟 0：
        // 前者是 BalanceService 那边刚刚建好的权威信号（进入/退出欠款状态的唯一
        // 写入点），没必要在这里重新推导一遍同样的判断。
        //
        // 用 HttpException 而不是"落一个 failed 状态的 Order 行"（余额不足走的
        // 是那条路径）：欠款拒单和余额不足拒单是两个商户需要能分清楚的不同原因——
        // 前者是"账户被暂停，先充值消除欠款"，后者是"这一笔订单太大，可用余额
        // 不够"，用同一种"Order 行 + fail_reason"机制表达会让商户以为可以直接
        // 换个更小金额的商品重试，而实际上账户整体被暂停，任何金额都会被拒绝。
        // 跟 validateProduct() 校验不通过时的既有约定一致：不创建任何 Order 行，
        // 直接抛 HttpException(422)，跟"这次请求参数/账户状态本身就不该继续"
        // 是同一类错误。
        if ($merchant->debt_since !== null) {
            throw new HttpException(422, '商户当前存在欠款，已暂停下单，请充值补足欠款后再试');
        }

        $product = $this->validateProduct($productId);

        $order = $this->createOrderRow($merchant, $merchantOrderNo, $product, $callbackUrl);
        if ($order === null) {
            // 建单时撞上了 (merchant_id, merchant_order_no) 唯一约束：说明就在这次
            // 查重之后、建单之前，另一个并发的重复请求已经抢先建好了订单——这次请求
            // 输掉了竞态，必须原样返回那笔订单的状态，绝不能再往下走去调用 freeze()。
            $raced = $this->orderDao->findByMerchantOrderNoForMerchant($merchant->id, $merchantOrderNo);
            if ($raced === null) {
                // 理论上不可能发生（刚刚才撞过这个唯一约束，说明那一行必然存在），
                // 防御性地当成系统异常抛出，不静默返回空结果。
                throw new RuntimeException('RechargeOrderPlacementService: lost create race but replay order not found.');
            }

            return $this->toResponseArray($raced);
        }

        $frozen = $this->balanceService->freeze($merchant->id, $order->id, $order->sale_price);
        if (! $frozen) {
            $order->fill([
                'status' => 'failed',
                'frozen_amount' => '0.00',
                'fail_reason' => '商户可用余额不足，下单前冻结失败',
                'finished_at' => date('Y-m-d H:i:s'),
            ])->save();

            $this->merchantNotifyService->notify($order->id);

            return $this->toResponseArray($order);
        }

        $this->orderRechargeDao->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'recharge_account' => $rechargeAccount,
            'rebate_amount' => $this->rebateCalculator->calculate($product, $merchant->level_id),
        ]);

        $this->routeAndFinalize($order, $product, $rechargeAccount, $callbackUrl);

        return $this->toResponseArray($order);
    }

    private function validateProduct(int $productId): Product
    {
        $product = $this->productDao->find($productId);
        if ($product === null) {
            throw new HttpException(404, '商品不存在');
        }

        if ($product->business_line !== self::BUSINESS_LINE) {
            throw new HttpException(422, '商品不是话费业务线');
        }

        if ($product->status !== 'on_shelf') {
            throw new HttpException(422, '商品未上架');
        }

        return $product;
    }

    /**
     * 建订单行，`cost_price` 用 '0.00' 占位（见类注释）。返回 null 表示这次
     * `Order::create()` 撞上了 `(merchant_id, merchant_order_no)` 唯一约束——
     * 调用方（place()）据此判断"输掉了并发建单竞态"，必须去重新查那笔已存在的
     * 订单并原样返回，绝不能继续往下调用 freeze()。
     */
    private function createOrderRow(Merchant $merchant, string $merchantOrderNo, Product $product, string $callbackUrl): ?Order
    {
        for ($attempt = 0; $attempt < self::MAX_ORDER_NO_RETRIES; ++$attempt) {
            try {
                return $this->orderDao->create([
                    'order_no' => $this->generateOrderNo(),
                    'merchant_id' => $merchant->id,
                    'merchant_order_no' => $merchantOrderNo,
                    'business_line' => self::BUSINESS_LINE,
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
            'RechargeOrderPlacementService: failed to generate a unique order_no after %d attempts.',
            self::MAX_ORDER_NO_RETRIES
        ));
    }

    private function generateOrderNo(): string
    {
        return 'R' . date('YmdHis') . random_int(100000, 999999);
    }

    /**
     * 按优先级迭代 supplier_products 映射行，调用驱动下单，落 OrderAttempt，
     * 按 requirements.md 6.2「只有明确失败才换下一个供应商」决定继续还是停止，
     * 最后把结果落到 Order 行上。
     */
    private function routeAndFinalize(Order $order, Product $product, string $rechargeAccount, string $callbackUrl): void
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

    private function attemptSupplier(Order $order, SupplierProduct $mapping, Supplier $supplier, string $rechargeAccount, int $attemptNo): DriverResult
    {
        $externalOrderNo = $order->order_no . '-' . $attemptNo;

        try {
            $driver = $this->supplierDriverFactory->build($supplier);

            $driverResult = $driver->placeOrder(
                externalOrderNo: $externalOrderNo,
                supplierGoodsId: $mapping->supplier_product_code,
                safePrice: $order->sale_price,
                notifyUrl: $this->buildSupplierNotifyUrl($supplier),
                attach: [$this->resolveAttachField($mapping) => $rechargeAccount],
                quantity: 1,
                isCardProduct: false,
            );
        } catch (Throwable $e) {
            // 驱动构造/解密失败（比如供应商配置损坏）：跟"拿不准一律 Unknown，
            // 不猜明确失败"是同一个原则的延伸——一个平台侧的配置问题不该被误判成
            // "供应商明确拒单"进而永远跳过这个供应商,也不该让整个下单请求 500。
            $driverResult = new DriverResult(
                result: UnifiedResult::Unknown,
                failReason: 'RechargeOrderPlacementService: failed to dispatch to supplier driver: ' . $e->getMessage(),
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
     * （本次任务定下的事实约定，见类注释）。缺失/为空一律退回直接用 'recharge_account'
     * 作为字段名——这是本方法的 fallback 行为，不是猜供应商真实需要的字段名。
     */
    private function resolveAttachField(SupplierProduct $mapping): string
    {
        $mapped = $mapping->param_mapping['recharge_account'] ?? null;

        return is_string($mapped) && $mapped !== '' ? $mapped : 'recharge_account';
    }

    /**
     * 只有明确失败才换下一个供应商"这条失败换供应商的循环逻辑本身在
     * `routeAndFinalize()` 里（跟这个方法是同一件事的两半，特意不合并），这里只做
     * "路由循环选出的最终结果该怎么落到订单上"这一步单次判断，状态转换+余额+通知
     * 那部分共享逻辑已经抽到 `App\Service\Order\OrderResultApplier`（docs/modules.md
     * 第 1 节"供应商回调入口与验签框架"任务抽出来的，另一个调用方是
     * `App\Service\Order\SupplierCallbackService`，见该类类注释），这里只负责
     * "一次都没试成"这个 `OrderResultApplier` 管不到的特殊分支（连
     * `DriverResult`/供应商都不存在，没法调用 `apply()`），以及把这次尝试对应
     * 映射行的估算成本价预置到订单上（`OrderResultApplier` 类注释里"cost_price
     * 的预置值约定"一节）。`$product` 是下单时已经查过的商品行，直接透传给
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

    /**
     * 占位实现：见类注释「范围外」——供应商回调接收路由（`/notify/{code}`，
     * requirements.md 6.8/8.1）本身还没建，这里只需要给 KasushouDriver::placeOrder()
     * 的 url 参数一个语法合法的值（卡速售下单请求体的 `url` 字段不能省），不影响
     * 本任务的同步下单结果判定。回调路由建成后改这一个方法产出真实的、带随机令牌的
     * 地址即可，不影响调用方。
     */
    private function buildSupplierNotifyUrl(Supplier $supplier): string
    {
        return sprintf('https://platform.example.com/notify/%s', $supplier->code);
    }

    /**
     * @return array{order_no: string, merchant_order_no: string, business_line: string,
     *     status: string, sale_price: string, frozen_amount: string, deducted_amount: null|string,
     *     refunded_amount: string, supplier_order_no: null|string, completed_at: null|string,
     *     fail_reason: null|string}
     */
    private function toResponseArray(Order $order): array
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
}
