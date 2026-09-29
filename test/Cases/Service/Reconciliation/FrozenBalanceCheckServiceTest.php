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

use App\Dao\OrderDao;
use App\Model\Alert;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\Order;
use App\Service\Reconciliation\FrozenBalanceCheckService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\TestCase;
use Mockery;

use function Hyperf\Support\make;

/**
 * App\Service\Reconciliation\FrozenBalanceCheckService 冻结余额定时核对（requirements.md 9）。
 * 测试库共享，一律用 checkMerchants() 限定在本用例建的商户上，复查间隔传 0。
 *
 * @internal
 * @coversNothing
 */
class FrozenBalanceCheckServiceTest extends TestCase
{
    private array $merchantIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        // 有个用例要替换 OrderDao，Service 在容器里是单例，每个用例重建容器
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
    }

    protected function tearDown(): void
    {
        $ids = $this->merchantIds ?: [0];
        MerchantBalanceLog::whereIn('merchant_id', $ids)->delete();
        Order::whereIn('merchant_id', $ids)->delete();
        Alert::where('related_type', 'merchant')->whereIn('related_id', $ids)->delete();
        Merchant::destroy($ids);
        $this->merchantIds = [];
        Mockery::close();

        parent::tearDown();
    }

    public function testConsistentMerchantRaisesNothing()
    {
        $merchant = $this->createMerchant('50.00');
        $processing = $this->createOrder($merchant, 'processing', '30.00');
        $this->log($merchant, $processing, 'freeze', '30.00');
        $abnormal = $this->createOrder($merchant, 'abnormal', '20.00');
        $this->log($merchant, $abnormal, 'freeze', '20.00');
        // 已结束的订单：冻结已经扣掉，不再计入
        $done = $this->createOrder($merchant, 'success', '15.00');
        $this->log($merchant, $done, 'freeze', '15.00');
        $this->log($merchant, $done, 'deduct', '15.00');

        $summary = $this->service()->checkMerchants([$merchant->id], 0);

        $this->assertSame(['checked' => 1, 'suspected' => 0, 'mismatched' => 0], $summary);
        $this->assertNull($this->alertFor($merchant));
    }

    public function testMerchantWithNoFrozenAndNoOpenOrdersIsConsistent()
    {
        $merchant = $this->createMerchant('0.00');

        // 指定了商户就一定查它，两边都是 0 算对得上
        $this->assertSame(['checked' => 1, 'suspected' => 0, 'mismatched' => 0], $this->service()->checkMerchants([$merchant->id], 0));
        $this->assertNull($this->alertFor($merchant));
        $this->assertSame(['checked' => 0, 'suspected' => 0, 'mismatched' => 0], $this->service()->checkMerchants([], 0));
    }

    /**
     * 订单标成功后 deduct() 没执行（进程在两步之间挂了）：冻结余额多出来，定位到这笔订单。
     */
    public function testSuccessOrderStillFrozenIsReported()
    {
        $merchant = $this->createMerchant('30.00');
        $order = $this->createOrder($merchant, 'success', '30.00');
        $this->log($merchant, $order, 'freeze', '30.00');

        $summary = $this->service()->checkMerchants([$merchant->id], 0);

        $this->assertSame(1, $summary['mismatched']);
        $alert = $this->alertFor($merchant);
        $this->assertNotNull($alert);
        $this->assertSame(Alert::LEVEL_CRITICAL, $alert->level);
        $this->assertStringContainsString('冻结余额 30.00，处理中订单冻结合计 0.00，差 30.00', $alert->message);
        $this->assertStringContainsString('流水对不上的订单 1 笔：' . $order->order_no, $alert->message);
    }

    /**
     * 建了订单行但 freeze() 没执行：处理中订单记着冻结金额，商户冻结余额里没有这笔钱。
     */
    public function testProcessingOrderNeverFrozenIsReported()
    {
        $merchant = $this->createMerchant('0.00');
        $order = $this->createOrder($merchant, 'processing', '12.50');

        $this->assertSame(1, $this->service()->checkMerchants([$merchant->id], 0)['mismatched']);
        $alert = $this->alertFor($merchant);
        $this->assertStringContainsString('差 -12.50', $alert->message);
        $this->assertStringContainsString($order->order_no, $alert->message);
    }

    /**
     * 快递冻结调整（freeze_adjust 带符号）和部分扣款 + 部分解冻都要按流水算对，不误报。
     */
    public function testExpressAdjustAndSplitSettlementAreConsistent()
    {
        $merchant = $this->createMerchant('8.00');
        $open = $this->createOrder($merchant, 'processing', '8.00');
        $this->log($merchant, $open, 'freeze', '10.00');
        $this->log($merchant, $open, 'freeze_adjust', '-2.00');
        $settled = $this->createOrder($merchant, 'success', '10.00');
        $this->log($merchant, $settled, 'freeze', '10.00');
        $this->log($merchant, $settled, 'deduct', '7.00');
        $this->log($merchant, $settled, 'unfreeze', '3.00');

        $this->assertSame(0, $this->service()->checkMerchants([$merchant->id], 0)['suspected']);
    }

    /**
     * 订单流水都对得上，但商户冻结余额被直接改过：提示余额可能被直接改过。
     */
    public function testBalanceChangedOutsideLedgerIsHinted()
    {
        $merchant = $this->createMerchant('20.00');
        $order = $this->createOrder($merchant, 'processing', '20.00');
        $this->log($merchant, $order, 'freeze', '20.00');
        Merchant::where('id', $merchant->id)->update(['frozen_balance' => '25.00']);

        $this->service()->checkMerchants([$merchant->id], 0);

        $message = $this->alertFor($merchant)->message;
        $this->assertStringContainsString('差 5.00', $message);
        $this->assertStringContainsString('最近一条流水记的冻结余额是 20.00', $message);
    }

    /**
     * 第一次看到差额、复查时已经对上（撞上了"改状态 → 扣款"之间的窗口）：不报。
     */
    public function testTransientDifferenceGoneOnRecheckIsNotReported()
    {
        $merchant = $this->createMerchant('30.00');
        $order = $this->createOrder($merchant, 'processing', '30.00');
        $this->log($merchant, $order, 'freeze', '30.00');

        $orderDao = Mockery::mock(OrderDao::class)->makePartial();
        $orderDao->shouldReceive('sumOpenFrozenByMerchant')->once()->andReturn([$merchant->id => '0.00']);
        $orderDao->shouldReceive('sumOpenFrozenByMerchant')->once()->andReturn([$merchant->id => '30.00']);
        ApplicationContext::getContainer()->set(OrderDao::class, $orderDao);

        $summary = $this->service()->checkMerchants([$merchant->id], 0);

        $this->assertSame(['checked' => 1, 'suspected' => 1, 'mismatched' => 0], $summary);
        $this->assertNull($this->alertFor($merchant));
    }

    public function testRepeatedRunsAccumulateOnOneOpenAlert()
    {
        $merchant = $this->createMerchant('30.00');
        $order = $this->createOrder($merchant, 'failed', '30.00');
        $this->log($merchant, $order, 'freeze', '30.00');

        $this->service()->checkMerchants([$merchant->id], 0);
        $this->service()->checkMerchants([$merchant->id], 0);

        $alerts = Alert::where('type', Alert::TYPE_FROZEN_BALANCE_MISMATCH)->where('related_id', $merchant->id)->get();
        $this->assertCount(1, $alerts);
        $this->assertSame(2, (int) $alerts[0]->occurrence_count);
    }

    private function service(): FrozenBalanceCheckService
    {
        return make(FrozenBalanceCheckService::class);
    }

    private function alertFor(Merchant $merchant): ?Alert
    {
        return Alert::where('type', Alert::TYPE_FROZEN_BALANCE_MISMATCH)
            ->where('related_type', 'merchant')
            ->where('related_id', $merchant->id)
            ->first();
    }

    private function createMerchant(string $frozenBalance): Merchant
    {
        $unique = uniqid('frozen_check_test_', true);
        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'available_balance' => '100.00',
            'frozen_balance' => $frozenBalance,
        ]);
        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createOrder(Merchant $merchant, string $status, string $frozenAmount): Order
    {
        return Order::create([
            'order_no' => 'FBC' . str_replace('.', '', uniqid('', true)),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => uniqid('m', true),
            'business_line' => 'recharge',
            'status' => $status,
            'sale_price' => $frozenAmount,
            'cost_price' => '0.00',
            'frozen_amount' => $frozenAmount,
            'refunded_amount' => '0.00',
            'callback_url' => 'https://example.com/callback',
        ]);
    }

    /**
     * 只为定位订单写流水，可用余额快照不参与核对填 0，冻结余额快照填商户建出来时的值。
     */
    private function log(Merchant $merchant, Order $order, string $type, string $amount): void
    {
        MerchantBalanceLog::create([
            'merchant_id' => $merchant->id,
            'type' => $type,
            'amount' => $amount,
            'available_before' => '0.00',
            'available_after' => '0.00',
            'frozen_before' => '0.00',
            'frozen_after' => $merchant->frozen_balance,
            'order_id' => $order->id,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
