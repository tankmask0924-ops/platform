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
use App\Dao\SystemSettingDao;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\Product;
use App\Model\Supplier;
use App\Model\SupplierProduct;
use App\OpenApi\ErrorCode;
use App\Service\AbstractService;
use App\Service\Merchant\BalanceService;
use App\Service\MerchantNotifyService;
use App\Supplier\DriverResult;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Carbon\Carbon;
use Hyperf\Di\Annotation\Inject;
use Throwable;

/**
 * 固定优先级路由与失败切换（requirements.md 6.5），话费、卡券共用；同步下单
 * （App\Service\Order\AbstractOrderPlacementService）和供应商异步回调
 * （App\Service\Order\SupplierCallbackService）都走这里，不各写一份循环。
 *
 * 每笔订单：
 * 1. 筛选：去掉映射行非 active（暂停/禁售）、库存为 0、供应商停用、供应商余额已知且
 *    低于这次的成本价的；熔断是二期，没有数据可读，暂不筛。
 * 2. 按 supplier_products.priority 升序。
 * 3. 依次尝试，每家最多一次（已经在 order_attempts 里出现过的供应商不再尝试）；
 *    只有明确失败才换下一家，处理中/结果未知停在当前供应商等结果。
 * 4. 切换时长：从下单（orders.created_at）起超过 system_settings.switch_duration_minutes
 *    （默认 30 分钟）后，明确失败不再换下一家，订单失败、解冻。
 * 5. 所有供应商都明确失败 → 订单失败、解冻。
 *
 * 【受理后拿到的结果】供应商回调（SupplierCallbackService）和定时查询
 * （SupplierResultPollingService）确认了某次尝试的结果后都交给 applyAttemptResult()：
 * 明确失败按切换规则决定换不换，其余直接落到订单上。
 *
 * 【受理后异步回调失败也要换下一家】平台订单号对商户不变，换的只是背后的供应商，
 * 每次尝试用 "{order_no}-{attempt_no}" 作为供应商侧单号区分。
 *
 * 【并发与过期回调】调用供应商之前先用 OrderAttemptDao::claim() 占住下一个
 * attempt_no（唯一索引），同一笔订单并发到达的重复失败回调只有一个能真正去下单，
 * 另一个拿不到序号直接放弃。旧尝试的迟到回调由 SupplierCallbackService 按
 * attempt_no 识别后忽略，不会到这里。
 */
class SupplierRouter extends AbstractService
{
    public const SWITCH_DURATION_SETTING_KEY = 'switch_duration_minutes';

    public const DEFAULT_SWITCH_DURATION_MINUTES = 30;

    /**
     * App\Supplier\UnifiedResult 到 order_attempts.result 的映射，跟
     * App\Model\OrderAttempt 类注释里记录的是同一份约定。
     */
    public const RESULT_MAP = [
        'Success' => 'success',
        'DefiniteFailure' => 'failed',
        'Processing' => 'processing',
        'Unknown' => 'unknown',
    ];

    private const ROUTABLE_BUSINESS_LINES = ['recharge', 'card'];

    #[Inject]
    protected SupplierProductDao $supplierProductDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected OrderAttemptDao $orderAttemptDao;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderRechargeDao $orderRechargeDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected SystemSettingDao $systemSettingDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected OrderResultApplier $orderResultApplier;

    #[Inject]
    protected BalanceService $balanceService;

    #[Inject]
    protected MerchantNotifyService $merchantNotifyService;

    /**
     * 按优先级排好序的可用供应商。
     *
     * @param int[] $excludeSupplierIds 这笔订单已经尝试过的供应商
     * @return list<array{0: SupplierProduct, 1: Supplier}>
     */
    public function eligibleCandidates(Product $product, array $excludeSupplierIds = []): array
    {
        $candidates = [];
        foreach ($this->supplierProductDao->listForProduct($product->id) as $mapping) {
            if (in_array((int) $mapping->supplier_id, $excludeSupplierIds, true)) {
                continue;
            }
            if ($mapping->status !== 'active') {
                continue;
            }
            if ($mapping->stock !== null && $mapping->stock <= 0) {
                continue;
            }

            $supplier = $this->supplierDao->find($mapping->supplier_id);
            if ($supplier === null || $supplier->status !== 'active') {
                continue;
            }
            // 余额只是余额监控任务写入的缓存，没查过（null）时不据此排除
            if ($supplier->balance !== null && bccomp((string) $supplier->balance, (string) $mapping->cost_price, 2) < 0) {
                continue;
            }

            $candidates[] = [$mapping, $supplier];
        }

        return $candidates;
    }

    /**
     * 下单前的预检：没有任何可用供应商时下单接口直接拒绝，不建单、不冻结余额
     * （requirements.md 6.5）。
     */
    public function hasEligibleSupplier(Product $product): bool
    {
        return $this->eligibleCandidates($product) !== [];
    }

    /**
     * 同步下单：订单已建好、已冻结，按优先级尝试并把最终结果落到订单上。
     */
    public function routeNewOrder(Order $order, Product $product, ?string $rechargeAccount): void
    {
        $outcome = $this->attemptRemaining($order, $product, $rechargeAccount);

        if ($outcome === null) {
            // 预检和这里之间供应商状态变了，一家都没试成
            $this->failWithoutSupplier($order);
            return;
        }

        if ($outcome !== false) {
            $this->finalize($order, $product, ...$outcome);
        }
    }

    /**
     * 受理后某次尝试拿到了确认结果（回调或定时查询）：先记到这次尝试上，再决定
     * 订单怎么变。调用方负责确认 `$attempt` 是这笔订单最新的一次尝试；老订单可能
     * 没有尝试记录，此时 `$attempt` 为 null。
     */
    public function applyAttemptResult(Order $order, ?OrderAttempt $attempt, DriverResult $result, int $supplierId): void
    {
        if ($attempt !== null) {
            $attempt->fill([
                'result' => self::RESULT_MAP[$result->result->name],
                'fail_reason' => $result->failReason ?? $attempt->fail_reason,
            ]);
            // 结果没变也要刷新 updated_at，定时查询靠它控制查询间隔
            $attempt->isDirty() ? $attempt->save() : $attempt->touch();
        }

        if ($result->result === UnifiedResult::DefiniteFailure) {
            $this->continueAfterDefiniteFailure($order, $result, $supplierId);
            return;
        }

        $this->orderResultApplier->apply($order, $result, $supplierId);
    }

    /**
     * 某次尝试已经被供应商明确判失败（异步回调或查询得到）：在切换时长内换下一家，
     * 否则订单失败、解冻。调用方负责先把那次尝试的 order_attempts 行标成 failed。
     */
    public function continueAfterDefiniteFailure(Order $order, DriverResult $failure, int $failedSupplierId): void
    {
        $context = $this->loadRoutingContext($order);

        if ($context !== null && $this->withinSwitchWindow($order)) {
            [$product, $rechargeAccount] = $context;
            $outcome = $this->attemptRemaining($order, $product, $rechargeAccount);

            if ($outcome === false) {
                return;
            }

            if ($outcome !== null) {
                $this->finalize($order, $product, ...$outcome);
                return;
            }
        }

        $this->orderResultApplier->apply($order, $failure, $failedSupplierId);
    }

    public function switchDurationMinutes(): int
    {
        $value = $this->systemSettingDao->getValue(self::SWITCH_DURATION_SETTING_KEY, self::DEFAULT_SWITCH_DURATION_MINUTES);

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : self::DEFAULT_SWITCH_DURATION_MINUTES;
    }

    public function withinSwitchWindow(Order $order): bool
    {
        $createdAt = $order->created_at instanceof Carbon ? $order->created_at : Carbon::parse((string) $order->created_at);

        return Carbon::now()->lessThanOrEqualTo($createdAt->copy()->addMinutes($this->switchDurationMinutes()));
    }

    /**
     * 按优先级尝试还没试过的供应商，直到拿到非明确失败的结果、供应商用完或超出切换时长。
     * 返回 null 表示一家都没尝试；false 表示序号被别的请求占走，本次放弃；
     * 数组是最后一次尝试的映射行和结果。
     *
     * @return null|array{0: SupplierProduct, 1: DriverResult}|false
     */
    private function attemptRemaining(Order $order, Product $product, ?string $rechargeAccount): array|false|null
    {
        $previous = $this->orderAttemptDao->listForOrder($order->id);
        $triedSupplierIds = $previous->pluck('supplier_id')->map(static fn ($id) => (int) $id)->all();
        $attemptNo = (int) $previous->max('attempt_no');

        $last = null;
        foreach ($this->eligibleCandidates($product, $triedSupplierIds) as [$mapping, $supplier]) {
            if ($last !== null && ! $this->withinSwitchWindow($order)) {
                break;
            }

            ++$attemptNo;
            $attempt = $this->orderAttemptDao->claim($order->id, $supplier->id, $attemptNo);
            if ($attempt === null) {
                return false;
            }

            $result = $this->callSupplier($order, $mapping, $supplier, $rechargeAccount, $attemptNo);
            $attempt->fill([
                'result' => self::RESULT_MAP[$result->result->name],
                'fail_reason' => $result->failReason,
                'request_snapshot' => $result->rawRequest,
                'response_snapshot' => $result->rawResponse,
            ])->save();

            $last = [$mapping, $result];
            if ($result->result !== UnifiedResult::DefiniteFailure) {
                break;
            }
        }

        return $last;
    }

    private function callSupplier(
        Order $order,
        SupplierProduct $mapping,
        Supplier $supplier,
        ?string $rechargeAccount,
        int $attemptNo
    ): DriverResult {
        $externalOrderNo = $order->order_no . '-' . $attemptNo;

        try {
            $driver = $this->supplierDriverFactory->build($supplier);

            return $driver->placeOrder(
                externalOrderNo: $externalOrderNo,
                supplierGoodsId: $mapping->supplier_product_code,
                safePrice: $order->sale_price,
                notifyUrl: $this->buildSupplierNotifyUrl($supplier),
                attach: $rechargeAccount !== null ? [$this->resolveAttachField($mapping) => $rechargeAccount] : [],
                quantity: 1,
                isCardProduct: $order->business_line === 'card',
            );
        } catch (Throwable $e) {
            // 驱动构造/解密失败（比如供应商配置损坏）：拿不准一律按结果未知，
            // 不能误判成明确失败去换下一家，也不能让整个请求 500。
            return new DriverResult(
                result: UnifiedResult::Unknown,
                failReason: static::class . ': failed to dispatch to supplier driver: ' . $e->getMessage(),
                rawRequest: ['external_orderno' => $externalOrderNo],
            );
        }
    }

    /**
     * 把最后一次尝试的结果落到订单上。先预置这家映射行的成本价（只改内存，
     * OrderResultApplier::apply() 统一 save()；成功且驱动给了实际成本时会被覆盖）。
     */
    private function finalize(Order $order, Product $product, SupplierProduct $mapping, DriverResult $result): void
    {
        $order->fill(['cost_price' => $mapping->cost_price]);

        $this->orderResultApplier->apply($order, $result, $mapping->supplier_id, $product);
    }

    private function failWithoutSupplier(Order $order): void
    {
        $finished = $this->orderDao->finishIfProcessing($order, [
            'status' => 'failed',
            'cost_price' => '0.00',
            'fail_reason' => ErrorCode::NoSupplierAvailable->message(),
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
        if (! $finished) {
            return;
        }

        $this->balanceService->unfreeze($order->merchant_id, $order->id, $order->frozen_amount);
        $this->merchantNotifyService->notify($order->id);
    }

    /**
     * 异步切换需要的下单参数：商品和充值账号都在 order_recharges 里。
     *
     * @return null|array{0: Product, 1: null|string}
     */
    private function loadRoutingContext(Order $order): ?array
    {
        if (! in_array($order->business_line, self::ROUTABLE_BUSINESS_LINES, true)) {
            return null;
        }

        $detail = $this->orderRechargeDao->find($order->id);
        if ($detail === null) {
            return null;
        }

        $product = $this->productDao->find($detail->product_id);
        if ($product === null) {
            return null;
        }

        return [$product, $detail->recharge_account];
    }

    /**
     * supplier_products.param_mapping 形如 `{"recharge_account": "<供应商侧字段名>"}`，
     * 两条业务线的动态参数在请求层都叫 recharge_account；缺失时直接用这个名字。
     */
    private function resolveAttachField(SupplierProduct $mapping): string
    {
        $mapped = $mapping->param_mapping['recharge_account'] ?? null;

        return is_string($mapped) && $mapped !== '' ? $mapped : 'recharge_account';
    }

    /**
     * 占位：还没有接入「给每个供应商生成带随机令牌的回调地址」，这里只需要给
     * KasushouDriver::placeOrder() 的 url 参数一个语法合法的值，回调路由
     * /notify/{code} 本身已经存在（App\Controller\NotifySupplierController）。
     */
    private function buildSupplierNotifyUrl(Supplier $supplier): string
    {
        return sprintf('https://platform.example.com/notify/%s', $supplier->code);
    }
}
