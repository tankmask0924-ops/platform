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

use App\Dao\MerchantRebateDao;
use App\Job\NotifyMerchantJob;
use App\Model\AftersaleDispute;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantRebate;
use App\Model\Order;
use App\Service\Merchant\BalanceService;
use App\Service\Order\OrderRefundService;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Testing\TestCase;
use Mockery;

/**
 * App\Service\Order\OrderRefundService（售后确认未到账的全额退款），以及它依赖的
 * BalanceService::refundOrder()/clawbackRebate() 和"争议期间返佣暂停到账"。
 *
 * @internal
 * @coversNothing
 */
class OrderRefundServiceTest extends TestCase
{
    private array $merchantIds = [];

    private array $orderIds = [];

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $id) {
            MerchantBalanceLog::where('order_id', $id)->delete();
            MerchantRebate::where('order_id', $id)->delete();
            AftersaleDispute::where('order_id', $id)->delete();
            Order::destroy($id);
        }
        foreach ($this->merchantIds as $id) {
            MerchantBalanceLog::where('merchant_id', $id)->delete();
            Merchant::destroy($id);
        }
        $this->orderIds = $this->merchantIds = [];

        parent::tearDown();
    }

    public function testRefundReturnsDeductionVoidsPendingRebateAndNotifiesOnce()
    {
        $merchant = $this->createMerchant('90.00');
        $order = $this->createSuccessOrder($merchant);
        $rebate = $this->createRebate($order, 'pending');
        $this->expectNotify(1);

        $this->assertTrue($this->service()->refundUndelivered($order, '核实未到账', 7));
        $this->assertFalse($this->service()->refundUndelivered(Order::find($order->id), '重复点击'), '只能退一次');

        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertSame('10.00', $order->refunded_amount);

        $merchant->refresh();
        $this->assertSame('100.00', $merchant->available_balance);

        $refundLogs = MerchantBalanceLog::where('order_id', $order->id)->where('type', 'refund')->get();
        $this->assertCount(1, $refundLogs);
        $this->assertSame(7, $refundLogs[0]->operator_id);
        $this->assertSame('核实未到账', $refundLogs[0]->reason);

        $rebate->refresh();
        $this->assertSame('voided', $rebate->status);
        $this->assertNotNull($rebate->voided_at);
        $this->assertSame(0, MerchantBalanceLog::where('order_id', $order->id)->where('type', 'rebate_clawback')->count());
    }

    public function testSettledRebateIsClawedBackAndMayMakeBalanceNegative()
    {
        // 余额 0，退 10 块、扣回 12 块返佣 → -2
        $merchant = $this->createMerchant('0.00');
        $order = $this->createSuccessOrder($merchant);
        $rebate = $this->createRebate($order, 'settled', '12.00');
        $this->expectNotify(1);

        $this->assertTrue($this->service()->refundUndelivered($order, '供应商全额退款'));

        $merchant->refresh();
        $this->assertSame('-2.00', $merchant->available_balance);
        $this->assertNotNull($merchant->debt_since, '扣成负数按负余额处理');

        $rebate->refresh();
        $this->assertSame('clawed_back', $rebate->status);
        $this->assertSame(1, MerchantBalanceLog::where('rebate_id', $rebate->id)->where('type', 'rebate_clawback')->count());

        $balanceService = $this->getContainer()->get(BalanceService::class);
        $this->assertFalse($balanceService->clawbackRebate($rebate->id), '不会重复扣回');
    }

    public function testOnlySuccessOrdersCanBeRefunded()
    {
        $merchant = $this->createMerchant('90.00');
        $order = $this->createSuccessOrder($merchant);
        Order::where('id', $order->id)->update(['status' => 'failed']);
        $this->expectNotify(0);

        $this->assertFalse($this->service()->refundUndelivered(Order::find($order->id), 'x'));
        $this->assertSame('90.00', $merchant->refresh()->available_balance);
    }

    public function testDueRebateIsPausedWhileDisputeIsProcessing()
    {
        $merchant = $this->createMerchant('90.00');
        $order = $this->createSuccessOrder($merchant);
        $rebate = $this->createRebate($order, 'pending', due: date('Y-m-d H:i:s', time() - 60));
        $dispute = AftersaleDispute::create([
            'order_id' => $order->id,
            'merchant_id' => $merchant->id,
            'status' => 'processing',
            'submitted_at' => date('Y-m-d H:i:s'),
        ]);
        $dao = $this->getContainer()->get(MerchantRebateDao::class);

        $this->assertNotContains($rebate->id, $dao->findDuePending()->pluck('id')->all());

        $dispute->fill(['status' => 'rejected'])->save();
        $this->assertContains($rebate->id, $dao->findDuePending()->pluck('id')->all(), '驳回后恢复到账');
    }

    /**
     * 结算任务手上的返佣是作废之前查出来的：锁住后重新确认，不能给已作废的返佣入账。
     */
    public function testSettlementSkipsRebateVoidedAfterItWasLoaded()
    {
        $merchant = $this->createMerchant('90.00');
        $order = $this->createSuccessOrder($merchant);
        $rebate = $this->createRebate($order, 'pending', due: date('Y-m-d H:i:s', time() - 60));
        $stale = MerchantRebate::find($rebate->id);
        $this->getContainer()->get(MerchantRebateDao::class)->voidIfPending($rebate->id);

        $this->assertFalse($this->getContainer()->get(BalanceService::class)->settleRebate($stale));

        $this->assertSame('voided', $rebate->refresh()->status);
        $this->assertSame('90.00', $merchant->refresh()->available_balance);
        $this->assertSame(0, MerchantBalanceLog::where('rebate_id', $rebate->id)->count());
    }

    private function service(): OrderRefundService
    {
        return $this->getContainer()->get(OrderRefundService::class);
    }

    private function expectNotify(int $times): void
    {
        $queue = Mockery::mock(DriverInterface::class);
        $queue->shouldReceive('push')->times($times)->with(Mockery::type(NotifyMerchantJob::class))->andReturnTrue();
        $factory = Mockery::mock(DriverFactory::class);
        $factory->shouldReceive('get')->times($times)->with('default')->andReturn($queue);
        $this->instance(DriverFactory::class, $factory);
    }

    private function createMerchant(string $available): Merchant
    {
        $unique = uniqid('refund_test_', true);
        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => 'encrypted-secret-placeholder',
            'available_balance' => $available,
            'frozen_balance' => '0.00',
            'level_id' => 1,
        ]);
        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createSuccessOrder(Merchant $merchant): Order
    {
        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => 'success',
            'sale_price' => '10.00',
            'cost_price' => '8.00',
            'frozen_amount' => '10.00',
            'deducted_amount' => '10.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
            'completed_at' => date('Y-m-d H:i:s'),
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
        $this->orderIds[] = $order->id;

        return Order::find($order->id);
    }

    private function createRebate(Order $order, string $status, string $amount = '1.00', ?string $due = null): MerchantRebate
    {
        return MerchantRebate::create([
            'order_id' => $order->id,
            'merchant_id' => $order->merchant_id,
            'business_line' => 'recharge',
            'level_id' => 1,
            'rebate_base' => $amount,
            'rebate_base_source' => 'product',
            'rebate_rate' => '1.0000',
            'rebate_rate_source' => 'level',
            'amount' => $amount,
            'status' => $status,
            'order_completed_at' => date('Y-m-d H:i:s'),
            'due_at' => $due ?? date('Y-m-d H:i:s', time() + 86400 * 7),
            'settled_at' => $status === 'settled' ? date('Y-m-d H:i:s') : null,
        ]);
    }
}
