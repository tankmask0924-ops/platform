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

use App\Crypto\Encryptor;
use App\Job\NotifyMerchantJob;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantLevel;
use App\Model\MerchantLevelBusinessRate;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderRecharge;
use App\Model\Product;
use App\Model\Supplier;
use App\Model\SupplierProduct;
use App\Service\Order\CardOrderPlacementService;
use App\Supplier\DriverResult;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Testing\TestCase;
use Mockery;

/**
 * `App\Service\Order\CardOrderPlacementService` 编排逻辑单测，跟
 * `RechargeOrderPlacementServiceTest`（docs/modules.md 6 节"卡券下单（二期）"
 * 任务的直接参照对象）同一套 Mockery 容器 swap 手法——不重复验证已经在那份测试
 * 里覆盖过的每一条路由/失败切换细节，只确认共享的
 * `App\Service\Order\AbstractOrderPlacementService` 编排真的能通过这个新入口
 * 走通（幂等、路由失败切换、余额不足、欠款暂停各留一条代表性用例），把测试重点
 * 放在卡券专属的部分：`card_type` 决定 `recharge_account` 必填/禁止、卡密通过
 * `App\Service\Order\OrderResultApplier` 落库并能解密还原、卡券/话费两个端点
 * 互不接受对方的商品。
 *
 * @internal
 * @coversNothing
 */
class CardOrderPlacementServiceTest extends TestCase
{
    private array $merchantIds = [];

    private array $productIds = [];

    private array $supplierIds = [];

    private array $supplierProductIds = [];

    private array $orderIds = [];

    private array $merchantLevelIds = [];

    private array $merchantLevelBusinessRateIds = [];

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $id) {
            OrderAttempt::where('order_id', $id)->delete();
            OrderRecharge::where('order_id', $id)->delete();
            MerchantBalanceLog::where('order_id', $id)->delete();
            Order::destroy($id);
        }
        $this->orderIds = [];

        foreach ($this->supplierProductIds as $id) {
            SupplierProduct::destroy($id);
        }
        $this->supplierProductIds = [];

        foreach ($this->supplierIds as $id) {
            Supplier::destroy($id);
        }
        $this->supplierIds = [];

        foreach ($this->productIds as $id) {
            Product::destroy($id);
        }
        $this->productIds = [];

        foreach ($this->merchantLevelBusinessRateIds as $id) {
            MerchantLevelBusinessRate::destroy($id);
        }
        $this->merchantLevelBusinessRateIds = [];

        foreach ($this->merchantLevelIds as $id) {
            MerchantLevel::destroy($id);
        }
        $this->merchantLevelIds = [];

        foreach ($this->merchantIds as $id) {
            MerchantBalanceLog::where('merchant_id', $id)->delete();
            Merchant::destroy($id);
        }
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testDirectCardHappyPathWithRechargeAccountSucceeds()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00', 'direct');
        $supplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-DIRECT-1', '8.00', 1);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('placeOrder')->once()->andReturn(new DriverResult(
            result: UnifiedResult::Success,
            supplierOrderNo: 'SUP-CARD-DIRECT-1',
            actualCost: '8.00',
        ));

        $this->expectNotify(1);

        $service = $this->makeService($driver);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();
        $result = $service->place($merchant, $merchantOrderNo, $product->id, 'game-account-1', 'https://merchant.example.com/notify');

        $order = $this->findOrderOrFail($merchant->id, $merchantOrderNo);

        $this->assertSame('success', $result['status']);
        $this->assertSame('card', $order->business_line);
        $this->assertSame('success', $order->status);
        $this->assertSame($supplier->id, $order->supplier_id);

        $merchant->refresh();
        $this->assertSame('90.00', $merchant->available_balance);

        $recharge = OrderRecharge::where('order_id', $order->id)->first();
        $this->assertNotNull($recharge);
        $this->assertSame('game-account-1', $recharge->recharge_account);
        $this->assertNull($recharge->card_no, '直充类商品不应该有卡号');
    }

    public function testDirectCardMissingRechargeAccountThrowsHttpExceptionWithoutSideEffects()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00', 'direct');
        $supplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-DIRECT-2', '8.00', 1);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldNotReceive('placeOrder');

        $service = $this->makeService($driver);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();

        try {
            $service->place($merchant, $merchantOrderNo, $product->id, null, 'https://merchant.example.com/notify');
            $this->fail('expected HttpException for missing recharge_account on direct card product');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertNull(Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->first());
        $this->assertSame(0, MerchantBalanceLog::where('merchant_id', $merchant->id)->count());
    }

    /**
     * 卡密核心场景：卡密类商品下单不传 recharge_account，驱动结果里的 `cardList`
     * 经由共享的 `OrderResultApplier` 落到 `order_recharges.card_no`/`card_pwd`，
     * 用 `Encryptor` 解密验证真的能还原成 mock 时给的明文——跟这个代码库其它加密
     * 字段（比如 `merchants.app_secret`）证明"往返"的方式一致。
     */
    public function testCardSecretHappyPathWithoutRechargeAccountPersistsEncryptedCardSecrets()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00', 'card_secret');
        $supplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-SECRET-1', '8.00', 1);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('placeOrder')->once()->andReturn(new DriverResult(
            result: UnifiedResult::Success,
            supplierOrderNo: 'SUP-CARD-SECRET-1',
            actualCost: '8.00',
            cardList: [
                ['card_no' => '1234567890123456', 'card_password' => 'sekret-pwd-1'],
            ],
        ));

        $this->expectNotify(1);

        $service = $this->makeService($driver);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();
        $result = $service->place($merchant, $merchantOrderNo, $product->id, null, 'https://merchant.example.com/notify');

        $order = $this->findOrderOrFail($merchant->id, $merchantOrderNo);

        $this->assertSame('success', $result['status']);
        $this->assertSame('success', $order->status);

        $recharge = OrderRecharge::where('order_id', $order->id)->first();
        $this->assertNotNull($recharge);
        $this->assertNull($recharge->recharge_account, '卡密类商品不应该有 recharge_account');
        $this->assertNotNull($recharge->card_no);
        $this->assertNotNull($recharge->card_pwd);

        $encryptor = new Encryptor();
        $this->assertSame('1234567890123456', $encryptor->decrypt($recharge->card_no), '卡号密文必须能解密还原成 mock 时的明文');
        $this->assertSame('sekret-pwd-1', $encryptor->decrypt($recharge->card_pwd), '卡密密文必须能解密还原成 mock 时的明文');
    }

    public function testCardSecretWithRechargeAccountThrowsHttpExceptionAndCreatesNoOrder()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00', 'card_secret');
        $supplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-SECRET-2', '8.00', 1);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldNotReceive('placeOrder');

        $service = $this->makeService($driver);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();

        try {
            $service->place($merchant, $merchantOrderNo, $product->id, 'should-not-be-here', 'https://merchant.example.com/notify');
            $this->fail('expected HttpException for recharge_account supplied on card_secret product');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertNull(Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->first());
        $this->assertSame(0, MerchantBalanceLog::where('merchant_id', $merchant->id)->count());
    }

    /**
     * requirements.md 5.3 返佣比例取法在 `business_line = 'card'` 上确认真的
     * 生效——不是假设 `RebateCalculator`/等级默认比例这条链路对 card 也一样管用，
     * 而是真的建一条 `merchant_level_business_rates(level_id, business_line='card')`
     * 记录，断言 `order_recharges.rebate_amount` 用上了这个比例算出来的值。
     */
    public function testRebateUsesCardBusinessLineRate()
    {
        $level = MerchantLevel::create(['name' => 'crt-' . substr(md5(uniqid('', true)), 0, 12)]);
        $this->merchantLevelIds[] = $level->id;

        $rate = MerchantLevelBusinessRate::create([
            'level_id' => $level->id,
            'business_line' => 'card',
            'rebate_rate' => '0.5000',
        ]);
        $this->merchantLevelBusinessRateIds[] = $rate->id;

        $merchant = $this->createMerchant('100.00');
        $merchant->fill(['level_id' => $level->id])->save();

        $product = $this->createProduct('10.00', 'direct', 'on_shelf', '2.00');
        $supplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-REBATE-1', '8.00', 1);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('placeOrder')->once()->andReturn(new DriverResult(
            result: UnifiedResult::Success,
            supplierOrderNo: 'SUP-CARD-REBATE-1',
            actualCost: '8.00',
        ));

        $this->expectNotify(1);

        $service = $this->makeService($driver);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();
        $service->place($merchant, $merchantOrderNo, $product->id, 'game-account-rebate', 'https://merchant.example.com/notify');

        $order = $this->findOrderOrFail($merchant->id, $merchantOrderNo);

        $recharge = OrderRecharge::where('order_id', $order->id)->first();
        $this->assertNotNull($recharge);
        $this->assertSame('1.00', $recharge->rebate_amount, '返佣基数 2.00 × 卡券业务线比例 50% = 1.00');
    }

    public function testFailoverToSecondSupplierAfterDefiniteFailure()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00', 'direct');
        $supplierA = $this->createSupplier();
        $supplierB = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplierA->id, 'GOODS-A', '8.00', 1);
        $this->createSupplierProduct($product->id, $supplierB->id, 'GOODS-B', '8.50', 2);

        $driverA = Mockery::mock(KasushouDriver::class);
        $driverA->shouldReceive('placeOrder')->once()->andReturn(new DriverResult(
            result: UnifiedResult::DefiniteFailure,
            failReason: 'kasushou: order status 4',
        ));

        $driverB = Mockery::mock(KasushouDriver::class);
        $driverB->shouldReceive('placeOrder')->once()->andReturn(new DriverResult(
            result: UnifiedResult::Success,
            supplierOrderNo: 'SUP-B-1',
            actualCost: '8.50',
        ));

        $this->expectNotify(1);

        $driverFactory = Mockery::mock(SupplierDriverFactory::class);
        $driverFactory->shouldReceive('build')
            ->with(Mockery::on(static fn ($supplier) => $supplier->id === $supplierA->id))
            ->andReturn($driverA);
        $driverFactory->shouldReceive('build')
            ->with(Mockery::on(static fn ($supplier) => $supplier->id === $supplierB->id))
            ->andReturn($driverB);
        $this->instance(SupplierDriverFactory::class, $driverFactory);

        $service = $this->getContainer()->get(CardOrderPlacementService::class);

        $merchantOrderNo = $this->uniqueMerchantOrderNo();
        $service->place($merchant, $merchantOrderNo, $product->id, 'game-account-2', 'https://merchant.example.com/notify');

        $order = $this->findOrderOrFail($merchant->id, $merchantOrderNo);

        $this->assertSame('success', $order->status);
        $this->assertSame($supplierB->id, $order->supplier_id);

        $attempts = OrderAttempt::where('order_id', $order->id)->orderBy('attempt_no')->get();
        $this->assertCount(2, $attempts);
        $this->assertSame('failed', $attempts[0]->result);
        $this->assertSame('success', $attempts[1]->result);
    }

    public function testInsufficientBalanceFailsBeforeAnySupplierCall()
    {
        $merchant = $this->createMerchant('5.00');
        $product = $this->createProduct('10.00', 'direct');
        $supplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-1', '8.00', 1);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldNotReceive('placeOrder');

        $this->expectNotify(1);

        $service = $this->makeService($driver);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();
        $service->place($merchant, $merchantOrderNo, $product->id, 'game-account-3', 'https://merchant.example.com/notify');

        $order = $this->findOrderOrFail($merchant->id, $merchantOrderNo);

        $this->assertSame('failed', $order->status);
        $this->assertStringContainsString('余额不足', (string) $order->fail_reason);
        $this->assertSame(0, OrderAttempt::where('order_id', $order->id)->count());
    }

    public function testMerchantInDebtIsRejectedCleanlyWithoutAnySideEffects()
    {
        $merchant = $this->createMerchant('-50.00', '2026-01-01 08:00:00');
        $product = $this->createProduct('10.00', 'direct');
        $supplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-1', '8.00', 1);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldNotReceive('placeOrder');

        $service = $this->makeService($driver);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();

        try {
            $service->place($merchant, $merchantOrderNo, $product->id, 'game-account-4', 'https://merchant.example.com/notify');
            $this->fail('expected HttpException for merchant currently in debt');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('欠款', $e->getMessage());
        }

        $this->assertNull(Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->first());
        $this->assertSame(0, MerchantBalanceLog::where('merchant_id', $merchant->id)->count());
    }

    public function testIdempotentResubmissionDoesNotFreezeOrCallDriverTwice()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00', 'direct');
        $supplier = $this->createSupplier();
        $this->createSupplierProduct($product->id, $supplier->id, 'GOODS-1', '8.00', 1);

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('placeOrder')->once()->andReturn(new DriverResult(
            result: UnifiedResult::Success,
            supplierOrderNo: 'SUP-1',
            actualCost: '8.00',
        ));

        $this->expectNotify(1);

        $service = $this->makeService($driver);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();

        $first = $service->place($merchant, $merchantOrderNo, $product->id, 'game-account-5', 'https://merchant.example.com/notify');
        $second = $service->place($merchant, $merchantOrderNo, $product->id, 'game-account-5', 'https://merchant.example.com/notify');

        $this->assertSame($first, $second);

        $orders = Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->get();
        $this->assertCount(1, $orders);
        $this->orderIds[] = $orders->first()->id;

        $this->assertSame(
            1,
            MerchantBalanceLog::where('order_id', $orders->first()->id)->where('type', 'freeze')->count()
        );
    }

    public function testNonCardProductThrowsHttpExceptionWithoutSideEffects()
    {
        $merchant = $this->createMerchant('100.00');
        $product = $this->createProduct('10.00', null, 'on_shelf', '0.00', 'recharge');

        $service = $this->getContainer()->get(CardOrderPlacementService::class);
        $merchantOrderNo = $this->uniqueMerchantOrderNo();

        try {
            $service->place($merchant, $merchantOrderNo, $product->id, '13800000000', 'https://merchant.example.com/notify');
            $this->fail('expected HttpException for non-card product');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertNull(Order::where('merchant_id', $merchant->id)->where('merchant_order_no', $merchantOrderNo)->first());
    }

    private function makeService(KasushouDriver $driver): CardOrderPlacementService
    {
        $driverFactory = Mockery::mock(SupplierDriverFactory::class);
        $driverFactory->shouldReceive('build')->andReturn($driver);
        $this->instance(SupplierDriverFactory::class, $driverFactory);

        return $this->getContainer()->get(CardOrderPlacementService::class);
    }

    private function expectNotify(int $times): void
    {
        $asyncDriver = Mockery::mock(DriverInterface::class);
        $asyncDriver->shouldReceive('push')->times($times)->with(Mockery::type(NotifyMerchantJob::class))->andReturnTrue();

        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->shouldReceive('get')->times($times)->with('default')->andReturn($asyncDriver);
        $this->instance(DriverFactory::class, $driverFactory);
    }

    private function findOrderOrFail(int $merchantId, string $merchantOrderNo): Order
    {
        $order = Order::where('merchant_id', $merchantId)->where('merchant_order_no', $merchantOrderNo)->first();
        $this->assertNotNull($order);
        $this->orderIds[] = $order->id;

        return $order;
    }

    private function uniqueMerchantOrderNo(): string
    {
        return 'MO-CARD-' . uniqid('', true);
    }

    private function createMerchant(string $availableBalance, ?string $debtSince = null): Merchant
    {
        $unique = uniqid('card_order_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => 'encrypted-secret-placeholder',
            'available_balance' => $availableBalance,
            'frozen_balance' => '0.00',
            'debt_since' => $debtSince,
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createProduct(
        string $salePrice,
        ?string $cardType = 'direct',
        string $status = 'on_shelf',
        string $rebateAmount = '0.00',
        string $businessLine = 'card'
    ): Product {
        $unique = uniqid('card_order_test_product_', true);

        $product = Product::create([
            'business_line' => $businessLine,
            'name' => $unique,
            'card_type' => $cardType,
            'face_value' => $salePrice,
            'sale_price' => $salePrice,
            'rebate_amount' => $rebateAmount,
            'status' => $status,
        ]);

        $this->productIds[] = $product->id;

        return $product;
    }

    private function createSupplier(string $status = 'active'): Supplier
    {
        $unique = uniqid('card_order_test_supplier_', true);
        $code = substr(md5($unique), 0, 24);

        $supplier = Supplier::create([
            'name' => $unique,
            'code' => $code,
            'business_line' => 'card',
            'driver' => 'kasushou',
            'config' => 'unused-in-test-driver-factory-is-overridden',
            'status' => $status,
        ]);

        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }

    private function createSupplierProduct(
        int $productId,
        int $supplierId,
        string $code,
        string $costPrice,
        int $priority,
        string $status = 'active',
        ?int $stock = null
    ): SupplierProduct {
        $mapping = SupplierProduct::create([
            'product_id' => $productId,
            'supplier_id' => $supplierId,
            'supplier_product_code' => $code,
            'cost_price' => $costPrice,
            'priority' => $priority,
            'status' => $status,
            'stock' => $stock,
        ]);

        $this->supplierProductIds[] = $mapping->id;

        return $mapping;
    }
}
