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

namespace HyperfTest\Cases\Service\Reconciliation;

use App\Model\Merchant;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\ReconciliationDiff;
use App\Model\Supplier;
use App\Service\Reconciliation\ReconciliationService;
use App\Supplier\DriverResult;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\Database\Model\Collection;
use Hyperf\Testing\TestCase;
use Mockery;
use RuntimeException;

use function Hyperf\Support\make;

/**
 * 订单对账（App\Service\Reconciliation\ReconciliationService，requirements.md 8.3、
 * database-design.md 4.15）。供应商驱动全部 mock，不发真实请求。
 *
 * 测试库是共享的，别的用例也会留下订单：所有断言都按本用例建的商户/订单过滤，
 * 批次日期也用一个远离今天的固定日子，避免跟并行跑的其它用例抢同一个批次。
 *
 * @internal
 * @coversNothing
 */
class ReconciliationServiceTest extends TestCase
{
    /** 批次日期；对的是它前一天（2019-06-01）完成的订单 */
    private const BATCH_DATE = '2019-06-02';

    private const ORDER_DAY = '2019-06-01';

    private array $merchantIds = [];

    private array $supplierIds = [];

    private array $orderIds = [];

    protected function tearDown(): void
    {
        ReconciliationDiff::whereIn('order_id', $this->orderIds ?: [0])->delete();
        OrderAttempt::whereIn('order_id', $this->orderIds ?: [0])->delete();
        Order::destroy($this->orderIds);
        Supplier::destroy($this->supplierIds);
        Merchant::destroy($this->merchantIds);
        $this->orderIds = $this->supplierIds = $this->merchantIds = [];

        parent::tearDown();
    }

    /**
     * 两边一致的订单不产生任何差异行。
     */
    public function testMatchingOrdersProduceNoDiff()
    {
        $supplier = $this->createSupplier();
        $success = $this->createOrder($supplier, 'success', '8.00');
        $failed = $this->createOrder($supplier, 'failed', '8.00');

        $this->bindDriver($supplier, [
            $success->order_no . '-1' => $this->driverResult(UnifiedResult::Success, '8.00'),
            $failed->order_no . '-1' => $this->driverResult(UnifiedResult::DefiniteFailure),
        ]);

        $summary = $this->service()->runOrderReconciliation(self::BATCH_DATE);

        $this->assertSame(2, $summary['checked']);
        $this->assertSame(0, $summary['diff_count']);
        $this->assertSame(self::ORDER_DAY, $summary['order_date'], '批次日期对的是前一天完成的订单');
        $this->assertSame(0, $this->diffs()->count());
    }

    /**
     * 平台成功、供应商却是失败（成功后被全额退款是典型场景）：报一条 status 差异。
     */
    public function testStatusMismatchIsRecorded()
    {
        $supplier = $this->createSupplier();
        $order = $this->createOrder($supplier, 'success', '8.00');
        $this->bindDriver($supplier, [$order->order_no . '-1' => $this->driverResult(UnifiedResult::DefiniteFailure)]);

        $this->service()->runOrderReconciliation(self::BATCH_DATE);

        $diffs = $this->diffs();
        $this->assertCount(1, $diffs);
        $this->assertSame(ReconciliationDiff::TYPE_ORDER, $diffs[0]->type);
        $this->assertSame(ReconciliationDiff::FIELD_STATUS, $diffs[0]->field);
        $this->assertSame('success', $diffs[0]->platform_value);
        $this->assertSame('failed', $diffs[0]->supplier_value);
        $this->assertNull($diffs[0]->diff_amount, '状态差异没有金额');
        $this->assertSame(ReconciliationDiff::STATUS_OPEN, $diffs[0]->status);
        $this->assertSame(self::BATCH_DATE, $diffs[0]->reconciliation_date->toDateString());
        $this->assertSame((int) $supplier->id, $diffs[0]->supplier_id);
    }

    /**
     * 供应商侧还在处理中，而平台已经给了终态：也是差异，要人工去看。
     */
    public function testSupplierStillProcessingIsADiff()
    {
        $supplier = $this->createSupplier();
        $order = $this->createOrder($supplier, 'failed', '8.00');
        $this->bindDriver($supplier, [$order->order_no . '-1' => $this->driverResult(UnifiedResult::Processing)]);

        $this->service()->runOrderReconciliation(self::BATCH_DATE);

        $diffs = $this->diffs();
        $this->assertCount(1, $diffs);
        $this->assertSame('failed', $diffs[0]->platform_value);
        $this->assertSame('processing', $diffs[0]->supplier_value);
    }

    /**
     * 两边都成功但金额对不上：报 cost_price 差异，差额是平台减供应商。
     */
    public function testCostMismatchIsRecordedWithSignedAmount()
    {
        $supplier = $this->createSupplier();
        $cheaper = $this->createOrder($supplier, 'success', '8.00');
        $dearer = $this->createOrder($supplier, 'success', '8.00');

        $this->bindDriver($supplier, [
            $cheaper->order_no . '-1' => $this->driverResult(UnifiedResult::Success, '7.50'),
            $dearer->order_no . '-1' => $this->driverResult(UnifiedResult::Success, '8.30'),
        ]);

        $this->service()->runOrderReconciliation(self::BATCH_DATE);

        $byOrder = $this->diffs()->keyBy('order_id');
        $this->assertSame('0.50', $byOrder[$cheaper->id]->diff_amount);
        $this->assertSame('-0.30', $byOrder[$dearer->id]->diff_amount);
        $this->assertSame(ReconciliationDiff::FIELD_COST_PRICE, $byOrder[$cheaper->id]->field);
        $this->assertSame('8.00', $byOrder[$cheaper->id]->platform_value);
        $this->assertSame('7.50', $byOrder[$cheaper->id]->supplier_value);
    }

    /**
     * 一边失败时不比金额：供应商返回的金额要么是 0、要么是退款前的原值，
     * 报出来只是把 status 那条差异重复说一遍。
     */
    public function testCostIsNotComparedWhenEitherSideFailed()
    {
        $supplier = $this->createSupplier();
        $order = $this->createOrder($supplier, 'failed', '8.00');
        $this->bindDriver($supplier, [$order->order_no . '-1' => $this->driverResult(UnifiedResult::DefiniteFailure, '0.00')]);

        $this->service()->runOrderReconciliation(self::BATCH_DATE);

        $this->assertSame(0, $this->diffs()->count());
    }

    /**
     * 供应商查询超时/网络错误（驱动返回 Unknown 且没解析出任何订单信息）：
     * 这是"我们没查到"，不是"对不上"，不写差异，只计进 unreachable。
     */
    public function testUnreachableSupplierIsNotADiff()
    {
        $supplier = $this->createSupplier();
        $order = $this->createOrder($supplier, 'success', '8.00');
        $this->bindDriver($supplier, [
            $order->order_no . '-1' => new DriverResult(result: UnifiedResult::Unknown, failReason: 'kasushou: network error or timeout'),
        ]);

        $summary = $this->service()->runOrderReconciliation(self::BATCH_DATE);

        $this->assertSame(0, $summary['checked']);
        $this->assertSame(1, $summary['unreachable']);
        $this->assertSame(0, $this->diffs()->count());
    }

    /**
     * 供应商确实答了、但结果本身不确定（卡速售部分退款：状态 4/5 且退款金额小于
     * 订单金额）：这恰恰是最该被人看见的，要报差异。
     */
    public function testAnsweredButUncertainResultIsADiff()
    {
        $supplier = $this->createSupplier();
        $order = $this->createOrder($supplier, 'success', '8.00');
        $this->bindDriver($supplier, [
            $order->order_no . '-1' => new DriverResult(
                result: UnifiedResult::Unknown,
                supplierOrderNo: 'KS123456',
                actualCost: '8.00',
                refundAmount: '3.00',
            ),
        ]);

        $summary = $this->service()->runOrderReconciliation(self::BATCH_DATE);

        $diffs = $this->diffs();
        $this->assertSame(1, $summary['checked']);
        $this->assertCount(1, $diffs);
        $this->assertSame('unknown', $diffs[0]->supplier_value);
    }

    /**
     * 驱动建不起来（供应商配置坏了）不会让整批对账失败，只是这家的订单对不了。
     */
    public function testBrokenSupplierConfigOnlyAffectsItsOwnOrders()
    {
        $healthy = $this->createSupplier();
        $broken = $this->createSupplier();
        $good = $this->createOrder($healthy, 'success', '8.00');
        $this->createOrder($broken, 'success', '8.00');

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('queryOrder')->andReturn($this->driverResult(UnifiedResult::DefiniteFailure));
        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('build')->andReturnUsing(static fn (Supplier $s) => (int) $s->id === (int) $healthy->id
            ? $driver
            : throw new RuntimeException('config is broken'));
        $this->instance(SupplierDriverFactory::class, $factory);

        $summary = $this->service()->runOrderReconciliation(self::BATCH_DATE);

        $this->assertSame(1, $summary['checked']);
        $this->assertSame(1, $summary['unreachable']);
        $this->assertSame([(int) $good->id], $this->diffs()->pluck('order_id')->all());
    }

    /**
     * 重跑同一个批次是整体替换而不是追加（database-design.md 4.15），
     * 上一轮的行（包括已标记处理的）会被这一轮的结果换掉。
     */
    public function testRerunReplacesTheWholeBatch()
    {
        $supplier = $this->createSupplier();
        $order = $this->createOrder($supplier, 'success', '8.00');
        $this->bindDriver($supplier, [$order->order_no . '-1' => $this->driverResult(UnifiedResult::DefiniteFailure)]);

        $this->service()->runOrderReconciliation(self::BATCH_DATE);
        $first = $this->diffs();
        $this->assertCount(1, $first);
        $first[0]->fill(['status' => ReconciliationDiff::STATUS_RESOLVED])->save();

        // 第二次供应商那边已经对上了
        $this->bindDriver($supplier, [$order->order_no . '-1' => $this->driverResult(UnifiedResult::Success, '8.00')]);
        $this->service()->runOrderReconciliation(self::BATCH_DATE);

        $this->assertSame(0, $this->diffs()->count(), '差异没了，旧记录连同处理标记一起被替换掉');
    }

    /**
     * 只对当天完成的订单：前一天完成的、以及还在处理中/异常单都不参与。
     */
    public function testOnlyTerminalOrdersFinishedOnTheCoveredDayAreChecked()
    {
        $supplier = $this->createSupplier();
        $inWindow = $this->createOrder($supplier, 'success', '8.00');
        $otherDay = $this->createOrder($supplier, 'success', '8.00', finishedAt: '2019-05-30 10:00:00');
        $processing = $this->createOrder($supplier, 'processing', '8.00', finishedAt: null);
        $abnormal = $this->createOrder($supplier, 'abnormal', '8.00', finishedAt: null);

        $this->bindDriver($supplier, [
            $inWindow->order_no . '-1' => $this->driverResult(UnifiedResult::DefiniteFailure),
            $otherDay->order_no . '-1' => $this->driverResult(UnifiedResult::DefiniteFailure),
            $processing->order_no . '-1' => $this->driverResult(UnifiedResult::DefiniteFailure),
            $abnormal->order_no . '-1' => $this->driverResult(UnifiedResult::DefiniteFailure),
        ]);

        $summary = $this->service()->runOrderReconciliation(self::BATCH_DATE);

        $this->assertSame(1, $summary['checked']);
        $this->assertSame([(int) $inWindow->id], $this->diffs()->pluck('order_id')->all());
    }

    private function service(): ReconciliationService
    {
        return make(ReconciliationService::class);
    }

    /**
     * @return Collection<int, ReconciliationDiff>
     */
    private function diffs()
    {
        return ReconciliationDiff::whereIn('order_id', $this->orderIds ?: [0])->orderBy('id')->get();
    }

    private function driverResult(UnifiedResult $result, ?string $actualCost = null): DriverResult
    {
        return new DriverResult(
            result: $result,
            supplierOrderNo: 'KS' . random_int(100000, 999999),
            actualCost: $actualCost,
        );
    }

    /**
     * @param array<string, DriverResult> $resultsByExternalOrderNo
     */
    private function bindDriver(Supplier $supplier, array $resultsByExternalOrderNo): void
    {
        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('queryOrder')->andReturnUsing(
            static fn (string $externalOrderNo) => $resultsByExternalOrderNo[$externalOrderNo]
                ?? throw new RuntimeException('unexpected external_orderno ' . $externalOrderNo)
        );

        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('build')->andReturnUsing(static fn (Supplier $s) => (int) $s->id === (int) $supplier->id
            ? $driver
            : throw new RuntimeException('not a supplier of this test'));
        $this->instance(SupplierDriverFactory::class, $factory);
    }

    private function createSupplier(): Supplier
    {
        $unique = uniqid('reconciliation_test_supplier_', true);

        $supplier = Supplier::create([
            'name' => $unique,
            'code' => substr(md5($unique), 0, 24),
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => 'unused-in-test-driver-factory-is-overridden',
            'status' => 'active',
        ]);
        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }

    private function createOrder(
        Supplier $supplier,
        string $status,
        string $costPrice,
        ?string $finishedAt = self::ORDER_DAY . ' 12:00:00'
    ): Order {
        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $this->merchant()->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => $status,
            'sale_price' => '10.00',
            'cost_price' => $costPrice,
            'supplier_id' => $supplier->id,
            'frozen_amount' => '10.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
            'finished_at' => $finishedAt,
        ]);
        $this->orderIds[] = $order->id;

        OrderAttempt::create([
            'order_id' => $order->id,
            'supplier_id' => $supplier->id,
            'attempt_no' => 1,
            'result' => $status === 'success' ? 'success' : 'failed',
        ]);

        return $order;
    }

    private function merchant(): Merchant
    {
        if ($this->merchantIds !== []) {
            return Merchant::find($this->merchantIds[0]);
        }

        $unique = uniqid('reconciliation_test_', true);
        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => 'encrypted-secret-placeholder',
            'available_balance' => '100.00',
            'frozen_balance' => '0.00',
        ]);
        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }
}
