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

use App\Dao\OrderAttemptDao;
use App\Exception\CallbackOrderNotFoundException;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderExpress;
use App\Model\OrderExpressFeeAdjustment;
use App\Model\PricingRule;
use App\Model\Supplier;
use App\Service\Merchant\BalanceService;
use App\Service\Order\ExpressCallbackService;
use App\Service\Order\ExpressOrderSettlementService;
use App\Service\Order\SupplierCallbackService;
use App\Service\Order\SupplierResultPollingService;
use App\Supplier\DriverResult;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use App\Supplier\Yunyang\YunyangDriver;
use App\Supplier\Yunyang\YunyangStatusMapper;
use Carbon\Carbon;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Mockery;
use PHPUnit\Framework\TestCase;

use function Hyperf\Support\make;

/**
 * 快递订单资金推进（requirements.md 7.2 的冻结调整、结算举例、费用调整、取消、完成时间），
 * 以及云洋回调只认带签名查询结果的认领规则。云洋驱动全部 mock。
 *
 * 金额场景直接照 7.2「结算举例」：加价规则"运费成本 + 2 元"，预估运费成本 10 元、售价 12 元。
 * `pricing_rules` 是全局配置表，备份/还原方式同 ExpressQuoteControllerTest。
 *
 * @internal
 * @coversNothing
 */
class ExpressOrderSettlementServiceTest extends TestCase
{
    private array $merchantIds = [];

    private array $supplierIds = [];

    private array $orderIds = [];

    /** @var list<array<string, mixed>> */
    private array $existingRules = [];

    private int $notifications = 0;

    protected function setUp(): void
    {
        $this->existingRules = PricingRule::query()->get()->map(static fn (PricingRule $rule) => [
            'business_line' => $rule->business_line,
            'rule_type' => $rule->rule_type,
            'value' => $rule->value,
            'updated_by' => $rule->updated_by,
            'created_at' => $rule->created_at?->toDateTimeString(),
            'updated_at' => $rule->updated_at?->toDateTimeString(),
        ])->all();
        PricingRule::query()->delete();
        PricingRule::create(['business_line' => 'express', 'rule_type' => 'fixed', 'value' => '2']);

        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);

        // 数一下回调商户的次数，不真的往队列里推
        $this->notifications = 0;
        $queue = Mockery::mock(DriverInterface::class);
        $queue->shouldReceive('push')->andReturnUsing(function () {
            ++$this->notifications;

            return true;
        });
        $factory = Mockery::mock(DriverFactory::class);
        $factory->shouldReceive('get')->andReturn($queue);
        ApplicationContext::getContainer()->set(DriverFactory::class, $factory);
    }

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $id) {
            OrderExpressFeeAdjustment::where('order_id', $id)->delete();
            OrderExpress::where('order_id', $id)->delete();
            OrderAttempt::where('order_id', $id)->delete();
            MerchantBalanceLog::where('order_id', $id)->delete();
            Order::destroy($id);
        }
        Merchant::destroy($this->merchantIds);
        Supplier::destroy($this->supplierIds);
        $this->orderIds = $this->merchantIds = $this->supplierIds = [];

        PricingRule::query()->delete();
        foreach ($this->existingRules as $rule) {
            PricingRule::query()->insert($rule);
        }
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 云洋冻结 13 元运费（查价时 10 元）：预估售价重算成 15，多冻结 3 元；只调一次。
     */
    public function testFreezeIsAdjustedOnceToTheSupplierFrozenFreight()
    {
        [$merchant, $supplier, $order] = $this->placedOrder();

        $this->service()->apply($order, $this->frozen('13.00'), (int) $supplier->id);

        $order->refresh();
        $this->assertSame('15.00', $order->sale_price);
        $this->assertSame('15.00', $order->frozen_amount);
        $this->assertSame('13.00', $order->cost_price);
        $this->assertBalance($merchant, '85.00', '15.00');
        $this->assertSame('3.00', MerchantBalanceLog::where('order_id', $order->id)->where('type', 'freeze_adjust')->value('amount'));
        $this->assertSame('13.00', OrderExpress::find($order->id)->frozen_freight);

        // 之后云洋再报别的冻结运费（重量变化），不再调冻结——差额等结算时多退少补
        $this->service()->apply($order->refresh(), $this->frozen('14.00'), (int) $supplier->id);
        $this->assertBalance($merchant, '85.00', '15.00');
        $this->assertSame(0, $this->notifications, '冻结调整不回调商户');
    }

    public function testFreezeAdjustedDownReleasesTheDifference()
    {
        [$merchant, $supplier, $order] = $this->placedOrder();

        $this->service()->apply($order, $this->frozen('8.00'), (int) $supplier->id);

        $this->assertSame('10.00', $order->refresh()->frozen_amount);
        $this->assertBalance($merchant, '90.00', '10.00');
        $this->assertSame('-2.00', MerchantBalanceLog::where('order_id', $order->id)->where('type', 'freeze_adjust')->value('amount'));
    }

    /**
     * 可用余额不够多冻结时能冻多少冻多少，不拒单（供应商已经受理了），差额结算时补扣。
     */
    public function testFreezeAdjustUpIsCappedByAvailableBalance()
    {
        [$merchant, $supplier, $order] = $this->placedOrder(available: '13.00');

        $this->service()->apply($order, $this->frozen('15.00'), (int) $supplier->id);

        $order->refresh();
        $this->assertSame('17.00', $order->sale_price, '预估售价照实重算');
        $this->assertSame('13.00', $order->frozen_amount, '只多冻结了可用的 1 元');
        $this->assertBalance($merchant, '0.00', '13.00');
    }

    /**
     * 7.2 结算举例「实际比预估贵」：冻结 12，实际运费成本 13、售价 15 → 冻结的 12 全扣，再补扣 3。
     */
    public function testSettlementCostingMoreDeductsFrozenAndSupplementsTheRest()
    {
        [$merchant, $supplier, $order] = $this->placedOrder();

        $this->service()->apply($order, $this->settled('13.00'), (int) $supplier->id);

        $order->refresh();
        $this->assertSame('success', $order->status);
        $this->assertSame('15.00', $order->deducted_amount);
        $this->assertSame('15.00', $order->sale_price, '报表毛利用 sale_price，结算后必须是实际应收');
        $this->assertSame('13.00', $order->cost_price);
        $this->assertNull($order->completed_at, '还没签收，不算完成');
        $this->assertBalance($merchant, '85.00', '0.00');
        $this->assertSame(['deduct' => '12.00', 'supplement_deduct' => '3.00'], $this->balanceLogs($order, ['deduct', 'supplement_deduct', 'unfreeze']));

        $express = OrderExpress::find($order->id);
        $this->assertSame('15.00', $express->freight_sale_price);
        $this->assertNotNull($express->fee_over_at);
        $this->assertSame(1, $this->notifications);
        $this->assertSame('success', OrderAttempt::where('order_id', $order->id)->value('result'));
    }

    /**
     * 7.2 结算举例「实际比预估便宜」：冻结 12，实际运费成本 8、售价 10 → 扣 10，解冻 2。
     */
    public function testSettlementCostingLessUnfreezesTheDifference()
    {
        [$merchant, $supplier, $order] = $this->placedOrder();

        $this->service()->apply($order, $this->settled('8.00'), (int) $supplier->id);

        $this->assertSame('10.00', $order->refresh()->deducted_amount);
        $this->assertBalance($merchant, '90.00', '0.00');
        $this->assertSame(['deduct' => '10.00', 'unfreeze' => '2.00'], $this->balanceLogs($order, ['deduct', 'supplement_deduct', 'unfreeze']));
    }

    /**
     * 7.2「扣费后新增耗材费」按成本补扣；重量核实退回运费按加价后的售价差额退回；
     * 同一份数据再来一次（回调重推、定时查询）不重复处理。
     */
    public function testFeeChangesAfterSettlementAreAdjustedOnce()
    {
        [$merchant, $supplier, $order] = $this->placedOrder();
        $this->service()->apply($order, $this->settled('10.00'), (int) $supplier->id);
        $this->assertBalance($merchant, '88.00', '0.00');

        $this->service()->apply($order->refresh(), $this->settled('10.00', haocai: '3.00'), (int) $supplier->id);
        $this->service()->apply($order->refresh(), $this->settled('9.00', haocai: '3.00'), (int) $supplier->id);
        // 重复推送
        $this->service()->apply($order->refresh(), $this->settled('9.00', haocai: '3.00'), (int) $supplier->id);

        $adjustments = OrderExpressFeeAdjustment::where('order_id', $order->id)->orderBy('id')->get(['type', 'item', 'amount'])->toArray();
        $this->assertSame([
            ['type' => 'supplement', 'item' => 'material', 'amount' => '3.00'],
            ['type' => 'refund', 'item' => 'freight', 'amount' => '1.00'],
        ], $adjustments);

        $order->refresh();
        $this->assertSame('14.00', $order->deducted_amount, '12 + 3 - 1');
        $this->assertSame('12.00', $order->cost_price, '实际成本 9 + 3');
        $this->assertBalance($merchant, '86.00', '0.00');
        $this->assertSame(3, $this->notifications, '结算 1 次 + 每次费用调整各 1 次');
    }

    /**
     * 拒收退回的逆向费按成本补扣，可以把可用余额扣成负数（4.5 负余额）。
     */
    public function testReverseFeeCanPushBalanceNegative()
    {
        [$merchant, $supplier, $order] = $this->placedOrder(available: '12.00');
        $this->service()->apply($order, $this->settled('10.00'), (int) $supplier->id);

        $this->service()->apply($order->refresh(), $this->settled('10.00', reverse: '6.00', typeCode: YunyangStatusMapper::TYPE_REJECTED), (int) $supplier->id);

        $this->assertBalance($merchant, '-6.00', '0.00');
        $this->assertSame('rejected', OrderExpress::find($order->id)->logistics_status);
        $this->assertNotNull(Merchant::find($merchant->id)->debt_since);
    }

    public function testSupplierCancellationUnfreezesEverything()
    {
        [$merchant, $supplier, $order] = $this->placedOrder();
        $this->service()->apply($order, $this->frozen('13.00'), (int) $supplier->id);

        $cancelled = $this->supplierResult(YunyangStatusMapper::FEE_FROZEN, YunyangStatusMapper::TYPE_CANCELLED, '13.00');
        $this->service()->apply($order->refresh(), $cancelled, (int) $supplier->id);

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertNull($order->fail_reason);
        $this->assertBalance($merchant, '100.00', '0.00');
        $this->assertSame('15.00', MerchantBalanceLog::where('order_id', $order->id)->where('type', 'unfreeze')->value('amount'), '解冻的是调整后的冻结金额');
        $this->assertSame('cancelled', OrderExpress::find($order->id)->logistics_status);
        $this->assertSame(1, $this->notifications);
    }

    /**
     * 查询说"查无此单"不是取消：下单明明成功了，不能凭一次查询把钱退掉。
     */
    public function testOrderNotFoundOnQueryIsNotTreatedAsCancellation()
    {
        [$merchant, $supplier, $order] = $this->placedOrder();

        $this->service()->apply($order, new DriverResult(result: UnifiedResult::DefiniteFailure, failReason: 'not found'), (int) $supplier->id);

        $this->assertSame('processing', $order->refresh()->status);
        $this->assertBalance($merchant, '88.00', '12.00');
    }

    /**
     * 签收时间就是完成时间（7.5）；签收先到、扣费后到时，结算那一步补上。
     */
    public function testSigningSetsCompletedAt()
    {
        Carbon::setTestNow('2026-09-23 10:00:00');
        [, $supplier, $order] = $this->placedOrder();
        $this->service()->apply($order, $this->settled('10.00'), (int) $supplier->id);
        $this->assertNull($order->refresh()->completed_at);

        Carbon::setTestNow('2026-09-24 15:30:00');
        $this->service()->apply($order, $this->settled('10.00', typeCode: YunyangStatusMapper::TYPE_SIGNED), (int) $supplier->id);

        $this->assertSame('2026-09-24 15:30:00', $order->refresh()->completed_at->toDateTimeString());
        $this->assertSame('signed', OrderExpress::find($order->id)->logistics_status);
    }

    public function testCompleteOverdueFillsCompletedAtAfterFallbackDays()
    {
        [, $supplier, $order] = $this->placedOrder();
        $this->service()->apply($order, $this->settled('10.00', typeCode: YunyangStatusMapper::TYPE_REJECTED), (int) $supplier->id);
        [, $supplier2, $recent] = $this->placedOrder();
        $this->service()->apply($recent, $this->settled('10.00'), (int) $supplier2->id);

        OrderExpress::where('order_id', $order->id)->update(['fee_over_at' => Carbon::now()->subDays(16)->toDateTimeString()]);

        $this->service()->completeOverdue();

        $this->assertNotNull($order->refresh()->completed_at);
        $this->assertNull($recent->refresh()->completed_at, '没过兜底天数的不动');
    }

    /**
     * 下单结果未知的订单（没有云洋单号）靠带签名查询结果里的平台订单号认领，认领后记下云洋单号。
     */
    public function testCallbackClaimsUnknownOrderByAuthoritativePlatformOrderNo()
    {
        [$merchant, $supplier, $order] = $this->placedOrder(shopbill: null);
        $this->bindDriver($supplier, $this->frozen('10.00', shopbill: 'SB-LATE', platformOrderNo: $order->order_no));

        $reply = make(SupplierCallbackService::class)->handle($supplier->code, ['shopbill' => 'SB-LATE'], []);

        $this->assertSame(ExpressCallbackService::SUCCESS_REPLY, $reply);
        $this->assertSame('SB-LATE', $order->refresh()->supplier_order_no);
        $this->assertBalance($merchant, '88.00', '12.00');
    }

    /**
     * 伪造回调：payload 里写别人的平台订单号，配上自己那单真实的 shopbill。认领只看查询结果里
     * 原样带回的 extendField1（这里是攻击者自己的单号），对不上就不认。
     */
    public function testForgedCallbackCannotClaimSomeoneElsesOrder()
    {
        [$merchant, $supplier, $victim] = $this->placedOrder(shopbill: null);
        $this->bindDriver($supplier, $this->supplierResult(
            YunyangStatusMapper::FEE_FROZEN,
            YunyangStatusMapper::TYPE_CANCELLED,
            '10.00',
            shopbill: 'SB-ATTACKER',
            platformOrderNo: 'E-ATTACKER-ORDER'
        ));

        try {
            make(SupplierCallbackService::class)->handle($supplier->code, ['shopbill' => 'SB-ATTACKER', 'extendField1' => $victim->order_no], []);
            $this->fail('forged callback must not be accepted');
        } catch (CallbackOrderNotFoundException) {
        }

        $this->assertSame('processing', $victim->refresh()->status);
        $this->assertBalance($merchant, '88.00', '12.00');
    }

    /**
     * 定时查询：快递按云洋单号查、结果交给快递结算；还没拿到云洋单号的（下单结果未知）查不了，
     * 不能进待查列表，否则它们永远是"最久没查"的那批，占满每一轮的名额。
     */
    public function testPollingQueriesByShopbillAndSkipsOrdersWithoutOne()
    {
        [$merchant, $supplier, $order] = $this->placedOrder();
        [, , $unknown] = $this->placedOrder(shopbill: null);
        OrderAttempt::whereIn('order_id', [$order->id, $unknown->id])->update(['updated_at' => '2000-01-01 00:00:00']);

        $due = make(OrderAttemptDao::class)->listDueForQuery('2000-01-01 00:00:01', 1000)->pluck('order_id')->all();
        $this->assertContains($order->id, $due);
        $this->assertNotContains($unknown->id, $due);

        $driver = Mockery::mock(YunyangDriver::class);
        $driver->shouldReceive('queryOrder')->once()->with('SB-1')->andReturn($this->settled('10.00'));
        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('buildYunyang')->andReturn($driver);
        ApplicationContext::getContainer()->set(SupplierDriverFactory::class, $factory);

        make(SupplierResultPollingService::class)->queryLatestAttempt($order);

        $this->assertSame('success', $order->refresh()->status);
        $this->assertBalance($merchant, '88.00', '0.00');
    }

    private function service(): ExpressOrderSettlementService
    {
        return make(ExpressOrderSettlementService::class);
    }

    private function bindDriver(Supplier $supplier, DriverResult $callbackResult): void
    {
        $driver = Mockery::mock(YunyangDriver::class);
        $driver->shouldReceive('parseWorkOrderCallback')->andReturnNull();
        $driver->shouldReceive('parseCallback')->andReturn($callbackResult);
        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('buildYunyang')->andReturn($driver);
        ApplicationContext::getContainer()->set(SupplierDriverFactory::class, $factory);
    }

    /**
     * 一笔已下单、已冻结 12 元（运费成本 10 + 加价 2）的快递订单。
     *
     * @return array{0: Merchant, 1: Supplier, 2: Order}
     */
    private function placedOrder(string $available = '100.00', ?string $shopbill = 'SB-1'): array
    {
        $unique = uniqid('express_settle_', true);
        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'available_balance' => $available,
            'frozen_balance' => '0.00',
        ]);
        $this->merchantIds[] = $merchant->id;

        $supplier = Supplier::create([
            'name' => $unique,
            'code' => substr(md5($unique), 0, 24),
            'business_line' => 'express',
            'driver' => 'yunyang',
            'config' => 'unused',
            'status' => 'active',
        ]);
        $this->supplierIds[] = $supplier->id;

        $order = Order::create([
            'order_no' => 'E' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . $unique,
            'business_line' => 'express',
            'status' => 'processing',
            'sale_price' => '12.00',
            'cost_price' => '10.00',
            'frozen_amount' => '12.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
            'supplier_id' => $supplier->id,
            'supplier_order_no' => $shopbill,
        ]);
        $this->orderIds[] = $order->id;
        make(BalanceService::class)->freeze((int) $merchant->id, (int) $order->id, '12.00');

        OrderExpress::create([
            'order_id' => $order->id,
            'express_company_code' => 'EXtest',
            'express_company_name' => '顺丰',
            'sender_info' => ['name' => '张三'],
            'receiver_info' => ['name' => '李四'],
            'item_info' => ['name' => '文件'],
            'weight' => 3,
            'estimated_freight' => '10.00',
            'logistics_status' => 'pending_pickup',
        ]);
        OrderAttempt::create(['order_id' => $order->id, 'supplier_id' => $supplier->id, 'attempt_no' => 1, 'result' => 'processing']);

        return [$merchant, $supplier, $order->refresh()];
    }

    private function frozen(string $freight, ?string $shopbill = 'SB-1', ?string $platformOrderNo = null): DriverResult
    {
        return $this->supplierResult(YunyangStatusMapper::FEE_FROZEN, YunyangStatusMapper::TYPE_PENDING_PICKUP, $freight, shopbill: $shopbill, platformOrderNo: $platformOrderNo);
    }

    private function settled(string $freight, string $haocai = '0.00', ?string $reverse = null, int $typeCode = YunyangStatusMapper::TYPE_IN_TRANSIT): DriverResult
    {
        return $this->supplierResult(YunyangStatusMapper::FEE_SETTLED, $typeCode, $freight, haocai: $haocai, reverse: $reverse);
    }

    private function supplierResult(
        int $feeOver,
        int $typeCode,
        string $freight,
        string $haocai = '0.00',
        ?string $reverse = null,
        ?string $shopbill = 'SB-1',
        ?string $platformOrderNo = null
    ): DriverResult {
        return new DriverResult(
            result: (new YunyangStatusMapper())->map($typeCode, $feeOver),
            supplierOrderNo: $shopbill,
            expressFees: [
                'fee_over' => $feeOver,
                'type_code' => $typeCode,
                'waybill' => 'WB-1',
                'weight' => '3.00',
                'total_freight' => null,
                'freight' => $freight,
                'freight_insured' => '0.00',
                'freight_haocai' => $haocai,
                'change_bill_freight' => $reverse,
                'platform_order_no' => $platformOrderNo,
            ],
        );
    }

    private function assertBalance(Merchant $merchant, string $available, string $frozen): void
    {
        $fresh = Merchant::find($merchant->id);
        $this->assertSame([$available, $frozen], [$fresh->available_balance, $fresh->frozen_balance]);
    }

    /**
     * @param list<string> $types
     * @return array<string, string>
     */
    private function balanceLogs(Order $order, array $types): array
    {
        return MerchantBalanceLog::where('order_id', $order->id)->whereIn('type', $types)->orderBy('id')->pluck('amount', 'type')->all();
    }
}
