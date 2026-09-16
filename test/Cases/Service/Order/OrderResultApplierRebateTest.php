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

namespace HyperfTest\Cases\Service\Order;

use App\Job\NotifyMerchantJob;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantLevelBusinessRate;
use App\Model\MerchantRebate;
use App\Model\Order;
use App\Model\OrderRecharge;
use App\Model\Product;
use App\Model\SystemSetting;
use App\Service\Order\OrderResultApplier;
use App\Supplier\DriverResult;
use App\Supplier\UnifiedResult;
use Carbon\Carbon;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Testing\TestCase;
use Mockery;

/**
 * `App\Service\Order\OrderResultApplier` 的返佣待到账记录生成半（requirements.md
 * 5.4"生成时机：订单成功且拿到返佣基数时生成"），补在类注释里说的"两个调用方
 * 共用同一个 Success 分支"这条既有事实之上——这里直接调用 `apply()` 本身，
 * 不经过整条 `RechargeOrderPlacementService::place()` 编排，聚焦"返佣记录生成
 * 对不对"这一件事：等级快照、返佣基数/比例/来源快照、到账时间计算（默认 7 天 /
 * `SystemSetting` 覆盖）、零返佣不生成记录。
 *
 * 商户余额扣款走的是已经在 `BalanceServiceTest`/`RechargeOrderPlacementServiceTest`
 * 覆盖过的 `BalanceService::deduct()`，这里不重复断言余额数字，只挂上
 * `expectNotify()` 这套跟其它测试一致的手法把异步通知短路掉（不真的进队列）。
 *
 * @internal
 * @coversNothing
 */
class OrderResultApplierRebateTest extends TestCase
{
    private array $merchantIds = [];

    private array $productIds = [];

    private array $merchantLevelBusinessRateIds = [];

    private array $orderIds = [];

    private array $systemSettingKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $id) {
            MerchantRebate::where('order_id', $id)->delete();
            OrderRecharge::where('order_id', $id)->delete();
            MerchantBalanceLog::where('order_id', $id)->delete();
            Order::destroy($id);
        }
        $this->orderIds = [];

        foreach ($this->systemSettingKeys as $key) {
            SystemSetting::where('key', $key)->delete();
        }
        $this->systemSettingKeys = [];

        foreach ($this->merchantLevelBusinessRateIds as $id) {
            MerchantLevelBusinessRate::destroy($id);
        }
        $this->merchantLevelBusinessRateIds = [];

        foreach ($this->productIds as $id) {
            Product::destroy($id);
        }
        $this->productIds = [];

        foreach ($this->merchantIds as $id) {
            Merchant::destroy($id);
        }
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testSuccessfulOrderWithRebateProductCreatesExactlyOnePendingRebateWithDefaultDuePeriod()
    {
        $levelId = random_int(1000000, 1099999);
        $merchant = $this->createMerchant($levelId);
        $product = $this->createProduct('0.50');
        $this->createLevelBusinessRate($levelId, '0.6000');
        $order = $this->createOrder($merchant->id);

        $this->expectNotify(1);

        $this->applier()->apply(
            $order,
            new DriverResult(result: UnifiedResult::Success, supplierOrderNo: 'SUP-1', actualCost: '8.00'),
            $this->uniqueSupplierId(),
            $product
        );

        $order->refresh();
        $this->assertSame('success', $order->status);
        $this->assertNotNull($order->completed_at);

        $rebate = MerchantRebate::where('order_id', $order->id)->first();
        $this->assertNotNull($rebate, '有返佣基数的成功订单必须生成一条 merchant_rebates 记录');
        $this->assertSame(1, MerchantRebate::where('order_id', $order->id)->count(), '一笔订单只能生成一条返佣记录');

        $this->assertSame($merchant->id, $rebate->merchant_id);
        $this->assertSame('recharge', $rebate->business_line);
        $this->assertSame($levelId, $rebate->level_id, '要记下单成功这一刻的商户等级快照');
        $this->assertSame('0.50', $rebate->rebate_base);
        $this->assertSame('product', $rebate->rebate_base_source);
        $this->assertSame('0.6000', $rebate->rebate_rate);
        $this->assertSame('level', $rebate->rebate_rate_source);
        $this->assertSame('0.30', $rebate->amount);
        $this->assertSame('pending', $rebate->status);
        $this->assertNull($rebate->settled_at);
        $this->assertNull($rebate->voided_at);
        $this->assertNull($rebate->clawed_back_at);

        $this->assertNotNull($rebate->order_completed_at);
        $this->assertSame(
            $order->completed_at->toDateTimeString(),
            $rebate->order_completed_at->toDateTimeString(),
            'order_completed_at 必须跟订单自己的 completed_at 是同一个时间戳'
        );

        $expectedDueAt = Carbon::parse($order->completed_at)->addDays(7)->toDateTimeString();
        $this->assertSame($expectedDueAt, $rebate->due_at->toDateTimeString(), '没有 SystemSetting 覆盖时默认期限是 7 天');
    }

    public function testSystemSettingOverridesDefaultDuePeriod()
    {
        $this->createSystemSetting('rebate_due_period_days', '3');

        $levelId = random_int(1100000, 1199999);
        $merchant = $this->createMerchant($levelId);
        $product = $this->createProduct('0.50');
        $this->createLevelBusinessRate($levelId, '0.6000');
        $order = $this->createOrder($merchant->id);

        $this->expectNotify(1);

        $this->applier()->apply(
            $order,
            new DriverResult(result: UnifiedResult::Success, supplierOrderNo: 'SUP-2', actualCost: '8.00'),
            $this->uniqueSupplierId(),
            $product
        );

        $order->refresh();
        $rebate = MerchantRebate::where('order_id', $order->id)->first();
        $this->assertNotNull($rebate);

        $expectedDueAt = Carbon::parse($order->completed_at)->addDays(3)->toDateTimeString();
        $this->assertSame($expectedDueAt, $rebate->due_at->toDateTimeString());
    }

    /**
     * 商品没配返佣金额（`rebate_amount = '0.00'`）：即使等级比例配置齐全，
     * 返佣基数本身是 0，最终金额还是 0，不应该生成任何 merchant_rebates 记录。
     */
    public function testZeroRebateBaseProductCreatesNoRebateRow()
    {
        $levelId = random_int(1200000, 1299999);
        $merchant = $this->createMerchant($levelId);
        $product = $this->createProduct('0.00');
        $this->createLevelBusinessRate($levelId, '0.6000');
        $order = $this->createOrder($merchant->id);

        $this->expectNotify(1);

        $this->applier()->apply(
            $order,
            new DriverResult(result: UnifiedResult::Success, supplierOrderNo: 'SUP-3', actualCost: '8.00'),
            $this->uniqueSupplierId(),
            $product
        );

        $order->refresh();
        $this->assertSame('success', $order->status, '零返佣不影响订单本身的成功状态');
        $this->assertNull(MerchantRebate::where('order_id', $order->id)->first());
    }

    /**
     * 商户当前等级在商品维度、业务线维度都没设置比例（`resolveRate()` 返回
     * null）：同样是"没有比例可用"，不生成记录，不是生成一条 amount='0.00' 的记录。
     */
    public function testMerchantLevelWithNoConfiguredRateCreatesNoRebateRow()
    {
        $levelId = random_int(1300000, 1399999);
        $merchant = $this->createMerchant($levelId);
        $product = $this->createProduct('0.50');
        // 故意不建 MerchantLevelBusinessRate。
        $order = $this->createOrder($merchant->id);

        $this->expectNotify(1);

        $this->applier()->apply(
            $order,
            new DriverResult(result: UnifiedResult::Success, supplierOrderNo: 'SUP-4', actualCost: '8.00'),
            $this->uniqueSupplierId(),
            $product
        );

        $this->assertNull(MerchantRebate::where('order_id', $order->id)->first());
    }

    /**
     * `RechargeOrderPlacementService::finalizeOrder()` 手上有现成的 `Product`
     * 直接传进来；`App\Service\Order\SupplierCallbackService::handle()` 处理异步
     * 回调时没有，传 `null`，`OrderResultApplier` 需要自己按 order_id 反查
     * `order_recharges.product_id` 再查一次 Product——这条测试覆盖这条 fallback
     * 路径，证明不需要调用方手上有 Product 也能正确生成返佣记录。
     */
    public function testFallsBackToResolvingProductFromOrderRechargeWhenNotProvided()
    {
        $levelId = random_int(1400000, 1499999);
        $merchant = $this->createMerchant($levelId);
        $product = $this->createProduct('0.50');
        $this->createLevelBusinessRate($levelId, '0.6000');
        $order = $this->createOrder($merchant->id);

        OrderRecharge::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'recharge_account' => '13800000000',
            'rebate_amount' => '0.30',
        ]);

        $this->expectNotify(1);

        $this->applier()->apply(
            $order,
            new DriverResult(result: UnifiedResult::Success, supplierOrderNo: 'SUP-5', actualCost: '8.00'),
            $this->uniqueSupplierId(),
            null
        );

        $rebate = MerchantRebate::where('order_id', $order->id)->first();
        $this->assertNotNull($rebate, '没有现成 Product 时也应该能反查出来并生成返佣记录');
        $this->assertSame('0.30', $rebate->amount);
    }

    private function applier(): OrderResultApplier
    {
        return $this->getContainer()->get(OrderResultApplier::class);
    }

    private function expectNotify(int $times): void
    {
        $asyncDriver = Mockery::mock(DriverInterface::class);
        $asyncDriver->shouldReceive('push')->times($times)->with(Mockery::type(NotifyMerchantJob::class))->andReturnTrue();

        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->shouldReceive('get')->times($times)->with('default')->andReturn($asyncDriver);
        $this->instance(DriverFactory::class, $driverFactory);
    }

    private function createMerchant(int $levelId): Merchant
    {
        $unique = uniqid('order_result_applier_rebate_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'level_id' => $levelId,
            'app_key' => 'app_key_' . $unique,
            'app_secret' => 'encrypted-secret-placeholder',
            'available_balance' => '1000.00',
            'frozen_balance' => '10.00',
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createProduct(string $rebateAmount): Product
    {
        $unique = uniqid('order_result_applier_rebate_test_product_', true);

        $product = Product::create([
            'business_line' => 'recharge',
            'name' => $unique,
            'operator' => 'mobile',
            'face_value' => '10.00',
            'sale_price' => '10.00',
            'rebate_amount' => $rebateAmount,
            'status' => 'on_shelf',
        ]);

        $this->productIds[] = $product->id;

        return $product;
    }

    private function createLevelBusinessRate(int $levelId, string $rate): MerchantLevelBusinessRate
    {
        $row = MerchantLevelBusinessRate::create([
            'level_id' => $levelId,
            'business_line' => 'recharge',
            'rebate_rate' => $rate,
        ]);

        $this->merchantLevelBusinessRateIds[] = $row->id;

        return $row;
    }

    private function createOrder(int $merchantId): Order
    {
        $order = Order::create([
            'order_no' => 'R' . uniqid('', true),
            'merchant_id' => $merchantId,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => 'processing',
            'sale_price' => '10.00',
            'cost_price' => '0.00',
            'frozen_amount' => '10.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);

        $this->orderIds[] = $order->id;

        return $order;
    }

    private function createSystemSetting(string $key, string $value): SystemSetting
    {
        $setting = SystemSetting::create([
            'key' => $key,
            'value' => $value,
        ]);

        $this->systemSettingKeys[] = $key;

        return $setting;
    }

    private function uniqueSupplierId(): int
    {
        return random_int(100000000, 999999999);
    }
}
