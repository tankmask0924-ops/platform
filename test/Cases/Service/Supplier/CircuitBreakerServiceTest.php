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

namespace HyperfTest\Cases\Service\Supplier;

use App\Crypto\Encryptor;
use App\Model\Merchant;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderRecharge;
use App\Model\Product;
use App\Model\Supplier;
use App\Model\SupplierCircuitBreaker;
use App\Model\SystemSetting;
use App\Service\Supplier\CircuitBreakerService;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * 供应商熔断判定与恢复（requirements.md 6.6），App\Service\Supplier\CircuitBreakerService。
 *
 * @internal
 * @coversNothing
 */
class CircuitBreakerServiceTest extends HttpTestCase
{
    private array $supplierIds = [];

    private array $merchantIds = [];

    private array $productIds = [];

    private array $orderIds = [];

    private CircuitBreakerService $service;

    /**
     * 熔断的四个阈值放在 system_settings 里，而这张表是**整个环境共享**的（测试库就是
     * 开发库）。用例里改阈值必须在 setUp 和 tearDown 两头都清干净：只在 setUp 清，
     * 最后一个用例写进去的值会留在库里，污染别的测试类，也会把开发环境后台的系统参数
     * 改掉——这里踩过一次（min_orders 被留成 5）。
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = make(CircuitBreakerService::class);
        $this->clearThresholdSettings();
    }

    protected function tearDown(): void
    {
        $this->clearThresholdSettings();
        SupplierCircuitBreaker::whereIn('supplier_id', $this->supplierIds)->delete();
        OrderAttempt::whereIn('order_id', $this->orderIds)->delete();
        OrderRecharge::whereIn('order_id', $this->orderIds)->delete();
        Order::destroy($this->orderIds);
        Product::destroy($this->productIds);
        Merchant::destroy($this->merchantIds);
        Supplier::destroy($this->supplierIds);
        $this->orderIds = [];

        parent::tearDown();
    }

    public function testDoesNotTripBelowTheMinimumOrderCount()
    {
        $supplier = $this->createSupplier();
        // 全失败，但只有 19 单，没到最小订单数 20：这正是"订单少时一两单失败别误暂停"
        $this->createAttempts($supplier, null, failed: 19, success: 0);

        $this->service->evaluate((int) $supplier->id, null);

        $this->assertFalse($this->service->isPaused((int) $supplier->id));
        $this->assertNull(SupplierCircuitBreaker::where('supplier_id', $supplier->id)->first());
    }

    public function testDoesNotTripWhenFailureRateIsExactlyAtThreshold()
    {
        $supplier = $this->createSupplier();
        // 20 单 10 失败 = 50%，阈值是"超过 50%"，刚好等于不熔断
        $this->createAttempts($supplier, null, failed: 10, success: 10);

        $this->service->evaluate((int) $supplier->id, null);

        $this->assertFalse($this->service->isPaused((int) $supplier->id));
    }

    public function testTripsWhenFailureRateExceedsThresholdAndRecordsSnapshot()
    {
        $supplier = $this->createSupplier();
        $this->createAttempts($supplier, null, failed: 11, success: 9);

        $this->service->evaluate((int) $supplier->id, null);

        $this->assertTrue($this->service->isPaused((int) $supplier->id));

        $row = SupplierCircuitBreaker::where('supplier_id', $supplier->id)
            ->where('product_id', SupplierCircuitBreaker::PRODUCT_ID_ALL)
            ->first();
        $this->assertSame(SupplierCircuitBreaker::STATUS_PAUSED, $row->status);
        // 默认暂停 5 分钟
        $this->assertEqualsWithDelta(time() + 300, $row->paused_until->getTimestamp(), 30);
        $this->assertStringContainsString('20 单中 11 单失败', $row->triggered_reason);
        $this->assertStringContainsString('55.00%', $row->triggered_reason);
    }

    public function testOldAttemptsOutsideTheWindowDoNotCount()
    {
        $supplier = $this->createSupplier();
        // 窗口是 10 分钟，这些失败发生在 30 分钟前
        $this->createAttempts($supplier, null, failed: 20, success: 0, minutesAgo: 30);

        $this->service->evaluate((int) $supplier->id, null);

        $this->assertFalse($this->service->isPaused((int) $supplier->id));
    }

    public function testProcessingAttemptsAreNotCountedInEitherSideOfTheRate()
    {
        $supplier = $this->createSupplier();
        // 11 失败 + 9 成功 = 20 单出结果 -> 55% 会熔断；再加一堆处理中的不能把它稀释掉
        $this->createAttempts($supplier, null, failed: 11, success: 9, processing: 50);

        $this->service->evaluate((int) $supplier->id, null);

        $this->assertTrue($this->service->isPaused((int) $supplier->id), '处理中的尝试不进分母');
    }

    /**
     * requirements.md 6.6「熔断可以精确到供应商 + 商品，避免一个商品出问题影响该供应商
     * 的其他商品」：只有问题商品达到阈值时，这家供应商的其他商品还能照常下单。
     */
    public function testProductScopedTripDoesNotBlockTheSuppliersOtherProducts()
    {
        $supplier = $this->createSupplier();
        $bad = $this->createProduct();
        $good = $this->createProduct();
        // 坏商品 20 单全失败；好商品 20 单全成功。整家 40 单 20 失败 = 50%，不超过阈值
        $this->createAttempts($supplier, $bad, failed: 20, success: 0);
        $this->createAttempts($supplier, $good, failed: 0, success: 20);

        $this->service->evaluate((int) $supplier->id, (int) $bad->id);

        $this->assertTrue($this->service->isPaused((int) $supplier->id, (int) $bad->id));
        $this->assertFalse($this->service->isPaused((int) $supplier->id, (int) $good->id));
        $this->assertFalse($this->service->isPaused((int) $supplier->id), '整家没有被熔断');
    }

    public function testSupplierWideTripBlocksEveryProduct()
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();
        $this->createAttempts($supplier, null, failed: 20, success: 0);

        $this->service->evaluate((int) $supplier->id, null);

        $this->assertTrue($this->service->isPaused((int) $supplier->id));
        $this->assertTrue($this->service->isPaused((int) $supplier->id, (int) $product->id), '整家熔断时每个商品都算熔断');
    }

    /**
     * 恢复按 paused_until 实时判断，不等定时任务。
     */
    public function testExpiredPauseIsAlreadyRecoveredBeforeTheCrontabRuns()
    {
        $supplier = $this->createSupplier();
        SupplierCircuitBreaker::create([
            'supplier_id' => $supplier->id,
            'product_id' => SupplierCircuitBreaker::PRODUCT_ID_ALL,
            'status' => SupplierCircuitBreaker::STATUS_PAUSED,
            'paused_until' => date('Y-m-d H:i:s', time() - 60),
            'triggered_reason' => '过期的熔断',
        ]);

        $this->assertFalse($this->service->isPaused((int) $supplier->id), '到期即恢复，不依赖定时任务');

        // 定时任务只是收尾，把库里的状态写回 normal
        $this->assertSame(1, $this->service->resumeExpired());
        $row = SupplierCircuitBreaker::where('supplier_id', $supplier->id)->first();
        $this->assertSame(SupplierCircuitBreaker::STATUS_NORMAL, $row->status);
    }

    public function testManualPauseWithoutDeadlineIsNotTouchedByTheRecoveryJob()
    {
        $supplier = $this->createSupplier();
        $this->service->pauseManually((int) $supplier->id, SupplierCircuitBreaker::PRODUCT_ID_ALL, null, '供应商在维护');

        $this->assertTrue($this->service->isPaused((int) $supplier->id));
        $this->assertSame(0, $this->service->resumeExpired(), '无限期暂停没有到期时间，定时任务不碰');
        $this->assertTrue($this->service->isPaused((int) $supplier->id));

        $this->service->resumeManually((int) $supplier->id, SupplierCircuitBreaker::PRODUCT_ID_ALL);
        $this->assertFalse($this->service->isPaused((int) $supplier->id));
    }

    public function testManualResumeClearsAnAutomaticPauseBeforeItExpires()
    {
        $supplier = $this->createSupplier();
        $this->createAttempts($supplier, null, failed: 20, success: 0);
        $this->service->evaluate((int) $supplier->id, null);
        $this->assertTrue($this->service->isPaused((int) $supplier->id));

        $this->service->resumeManually((int) $supplier->id, SupplierCircuitBreaker::PRODUCT_ID_ALL);

        $this->assertFalse($this->service->isPaused((int) $supplier->id), '运营确认恢复了就能立刻放行，不用等暂停期满');
    }

    public function testThresholdsComeFromSystemSettings()
    {
        $supplier = $this->createSupplier();
        SystemSetting::create(['key' => CircuitBreakerService::MIN_ORDERS_SETTING_KEY, 'value' => json_encode(5)]);
        SystemSetting::create(['key' => CircuitBreakerService::FAIL_RATE_PERCENT_SETTING_KEY, 'value' => json_encode('20.00')]);

        // 5 单 2 失败 = 40% > 20%，且达到了改小后的最小订单数
        $this->createAttempts($supplier, null, failed: 2, success: 3);
        $this->service->evaluate((int) $supplier->id, null);

        $this->assertTrue($this->service->isPaused((int) $supplier->id));
        $this->assertSame(5, $this->service->thresholds()['min_orders']);
        $this->assertSame('20.00', $this->service->thresholds()['fail_rate_percent']);
    }

    private function clearThresholdSettings(): void
    {
        SystemSetting::whereIn('key', [
            CircuitBreakerService::WINDOW_MINUTES_SETTING_KEY,
            CircuitBreakerService::MIN_ORDERS_SETTING_KEY,
            CircuitBreakerService::FAIL_RATE_PERCENT_SETTING_KEY,
            CircuitBreakerService::PAUSE_MINUTES_SETTING_KEY,
        ])->delete();
    }

    private function createAttempts(
        Supplier $supplier,
        ?Product $product,
        int $failed,
        int $success,
        int $processing = 0,
        int $minutesAgo = 1
    ): void {
        $at = date('Y-m-d H:i:s', time() - $minutesAgo * 60);
        $rows = array_merge(
            array_fill(0, $failed, 'failed'),
            array_fill(0, $success, 'success'),
            array_fill(0, $processing, 'processing'),
        );

        foreach ($rows as $result) {
            $order = $this->createOrder($supplier, $product);
            $attempt = OrderAttempt::create([
                'order_id' => $order->id,
                'supplier_id' => $supplier->id,
                'attempt_no' => 1,
                'result' => $result,
            ]);
            OrderAttempt::where('id', $attempt->id)->update(['created_at' => $at, 'updated_at' => $at]);
        }
    }

    private function createOrder(Supplier $supplier, ?Product $product): Order
    {
        $merchant = Merchant::create([
            'type' => 'company',
            'phone' => '185' . random_int(10000000, 99999999),
            'password' => 'hashed',
            'status' => 'active',
        ]);
        $this->merchantIds[] = $merchant->id;

        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => 'processing',
            'sale_price' => '10.00',
            'cost_price' => '8.00',
            'supplier_id' => $supplier->id,
            'frozen_amount' => '10.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);
        $this->orderIds[] = $order->id;

        if ($product !== null) {
            OrderRecharge::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'recharge_account' => '13800000000',
                'rebate_amount' => '0.00',
            ]);
        }

        return $order;
    }

    private function createProduct(): Product
    {
        $product = Product::create([
            'business_line' => 'recharge',
            'name' => '熔断测试商品 ' . uniqid('', true),
            'operator' => 'mobile',
            'face_value' => '100.00',
            'sale_price' => '98.00',
            'rebate_amount' => '0.00',
            'status' => 'on_shelf',
        ]);
        $this->productIds[] = $product->id;

        return $product;
    }

    private function createSupplier(): Supplier
    {
        $supplier = Supplier::create([
            'name' => '熔断测试供应商',
            'code' => 'cb_' . substr(md5(uniqid('', true)), 0, 20),
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => make(Encryptor::class)->encrypt(json_encode(['base_url' => 'https://api.example.com'])),
            'status' => 'active',
        ]);
        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }
}
