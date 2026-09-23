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

namespace HyperfTest\Cases\Admin;

use App\Model\AdminOperationLog;
use App\Model\ExpressWorkorder;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\Order;
use App\Model\Supplier;
use App\Service\Order\ExpressCallbackService;
use App\Service\Order\ExpressOrderSettlementService;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\Yunyang\YunyangDriver;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\HttpTestCase;
use Mockery;

use function Hyperf\Support\make;

/**
 * 快递工单（客服代提交，requirements.md 7.2、8.3）：提交、重复提交、云洋拒绝/结果未知、
 * 理赔结单调账、驳回、工单回调只记录不动钱。云洋驱动全部 mock。
 *
 * @internal
 * @coversNothing
 */
class ExpressWorkorderControllerTest extends HttpTestCase
{
    use CreatesAdmins;

    private const PASSWORD = 'correct-password';

    private array $merchantIds = [];

    private array $orderIds = [];

    private array $supplierIds = [];

    /**
     * 每个用例一个新容器：后台服务是单例，上一个用例注入的 mock 驱动会被带进下一个用例（同 OrderControllerTest）。
     */
    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->client = make(Client::class);
    }

    protected function tearDown(): void
    {
        ExpressWorkorder::whereIn('order_id', $this->orderIds ?: [0])->delete();
        AdminOperationLog::where('target_type', 'order')->whereIn('target_id', $this->orderIds ?: [0])->delete();
        MerchantBalanceLog::whereIn('merchant_id', $this->merchantIds ?: [0])->delete();
        Order::destroy($this->orderIds);
        Supplier::destroy($this->supplierIds);
        Merchant::destroy($this->merchantIds);
        $this->cleanUpAdmins();

        parent::tearDown();
    }

    public function testSubmitClaimThenCompleteCreditsTheVerifiedAmount()
    {
        [$merchant, $supplier] = $this->merchantAndSupplier();
        $order = $this->createExpressOrder($merchant, $supplier, 'success');
        $driver = Mockery::mock(YunyangDriver::class);
        $driver->shouldReceive('submitWorkOrder')->once()->with('YY-' . $order->id, 'claim', '外箱破损，物品损坏')
            ->andReturn(['accepted' => true, 'unknown' => false, 'workorder_no' => 'WO-' . $order->id, 'message' => '']);
        $this->bindDriver($driver);
        $token = $this->loginAs($this->createAdminWithPermissions(['aftersale.view', 'aftersale.handle', 'order.view']));

        $submitted = $this->body($this->jsonRequest('POST', '/admin/orders/' . $order->id . '/workorders', $token, ['type' => 'claim', 'content' => '外箱破损，物品损坏']));
        $this->assertSame('processing', $submitted['status']);
        $this->assertSame('WO-' . $order->id, $submitted['supplier_workorder_no']);

        $this->assertSame(409, $this->jsonRequest('POST', '/admin/orders/' . $order->id . '/workorders', $token, ['type' => 'claim', 'content' => '再提一次'])->getStatusCode(), '同类型处理中不能重复提');

        $detail = $this->body($this->jsonRequest('GET', '/admin/orders/' . $order->id, $token));
        $this->assertSame([$submitted['id']], array_column($detail['workorders'], 'id'));

        $list = $this->body($this->jsonRequest('GET', '/admin/express-workorders?order_no=' . $order->order_no, $token));
        $this->assertSame(1, $list['total']);

        $this->assertSame(422, $this->jsonRequest('POST', '/admin/express-workorders/' . $submitted['id'] . '/complete', $token, ['claim_amount' => '25.50'])->getStatusCode(), '必须写处理结果');

        $done = $this->body($this->jsonRequest('POST', '/admin/express-workorders/' . $submitted['id'] . '/complete', $token, [
            'result_remark' => '云洋核实赔付 25.50',
            'claim_amount' => '25.50',
        ]));
        $this->assertSame('completed', $done['status']);
        $this->assertSame('25.50', $done['claim_amount']);
        $this->assertSame('125.50', $merchant->refresh()->available_balance);
        $log = MerchantBalanceLog::where('merchant_id', $merchant->id)->where('type', 'adjustment')->first();
        $this->assertSame('25.50', (string) $log->amount);
        $this->assertStringContainsString($order->order_no, $log->reason);

        $this->assertSame(409, $this->jsonRequest('POST', '/admin/express-workorders/' . $submitted['id'] . '/complete', $token, [
            'result_remark' => '再结一次', 'claim_amount' => '25.50',
        ])->getStatusCode(), '不会调两次账');
        $this->assertSame('125.50', $merchant->refresh()->available_balance);
    }

    public function testSupplierRefusalAndUnknownResultDoNotCreateWorkorders()
    {
        [$merchant, $supplier] = $this->merchantAndSupplier();
        $order = $this->createExpressOrder($merchant, $supplier, 'processing');
        $driver = Mockery::mock(YunyangDriver::class);
        $driver->shouldReceive('submitWorkOrder')->twice()->andReturn(
            ['accepted' => false, 'unknown' => false, 'workorder_no' => null, 'message' => '待揽收订单不能催派送'],
            ['accepted' => false, 'unknown' => true, 'workorder_no' => null, 'message' => 'yunyang: network error or timeout'],
        );
        $this->bindDriver($driver);
        $token = $this->loginAs($this->createAdminWithPermissions(['aftersale.handle']));

        $refused = $this->jsonRequest('POST', '/admin/orders/' . $order->id . '/workorders', $token, ['type' => 'urge_delivery', 'content' => '催派送']);
        $this->assertSame(422, $refused->getStatusCode());
        $this->assertStringContainsString('待揽收订单不能催派送', (string) $refused->getBody());

        $this->assertSame(502, $this->jsonRequest('POST', '/admin/orders/' . $order->id . '/workorders', $token, ['type' => 'urge_pickup', 'content' => '催取件'])->getStatusCode());
        $this->assertSame(0, ExpressWorkorder::where('order_id', $order->id)->count());

        $this->assertSame(422, $this->jsonRequest('POST', '/admin/orders/' . $order->id . '/workorders', $token, ['type' => 'refund', 'content' => 'x'])->getStatusCode());
        $failed = $this->createExpressOrder($merchant, $supplier, 'failed');
        $this->assertSame(409, $this->jsonRequest('POST', '/admin/orders/' . $failed->id . '/workorders', $token, ['type' => 'urge_pickup', 'content' => 'x'])->getStatusCode());
        $this->assertSame(403, $this->jsonRequest('POST', '/admin/orders/' . $order->id . '/workorders', $this->loginAs($this->createAdminWithPermissions(['aftersale.view'])), ['type' => 'urge_pickup', 'content' => 'x'])->getStatusCode());
    }

    public function testRejectAndClaimAmountOnlyForClaims()
    {
        [$merchant, $supplier] = $this->merchantAndSupplier();
        $order = $this->createExpressOrder($merchant, $supplier, 'success');
        $workorder = $this->createWorkorder($order, $supplier, 'weight_verify');
        $token = $this->loginAs($this->createAdminWithPermissions(['aftersale.handle']));

        $this->assertSame(422, $this->jsonRequest('POST', '/admin/express-workorders/' . $workorder->id . '/complete', $token, [
            'result_remark' => '已退回', 'claim_amount' => '3.00',
        ])->getStatusCode(), '重量核实退回的运费走费用调整，不能在这里调账');

        $rejected = $this->body($this->jsonRequest('POST', '/admin/express-workorders/' . $workorder->id . '/reject', $token, ['result_remark' => '云洋核实重量无误']));
        $this->assertSame('rejected', $rejected['status']);
        $this->assertSame('100.00', $merchant->refresh()->available_balance);
    }

    /**
     * 工单回调没有签名：只记下供应商说了什么，不改状态、不动钱，再做一次带签名的订单查询。
     */
    public function testWorkorderCallbackOnlyRecordsTheReplyAndRefreshesTheOrder()
    {
        [$merchant, $supplier] = $this->merchantAndSupplier();
        $order = $this->createExpressOrder($merchant, $supplier, 'success');
        $workorder = $this->createWorkorder($order, $supplier, 'claim');
        $payload = ['workOrderId' => $workorder->supplier_workorder_no, 'status' => '已赔付', 'claimAmount' => '500.00'];

        $driver = Mockery::mock(YunyangDriver::class);
        $driver->shouldReceive('parseWorkOrderCallback')->with($payload)->andReturn([
            'workorder_no' => $workorder->supplier_workorder_no, 'shopbill' => null, 'status' => '已赔付', 'reply' => null, 'amount' => '500.00',
        ]);
        $this->bindDriver($driver);
        $settlement = Mockery::mock(ExpressOrderSettlementService::class);
        $settlement->shouldReceive('refreshFromSupplier')->once()->with(Mockery::on(static fn (Order $o) => $o->id === $order->id))->andReturnNull();
        ApplicationContext::getContainer()->set(ExpressOrderSettlementService::class, $settlement);

        $reply = make(ExpressCallbackService::class)->handle($supplier, $payload);

        $this->assertSame(ExpressCallbackService::SUCCESS_REPLY, $reply);
        $workorder->refresh();
        $this->assertSame('processing', $workorder->status);
        $this->assertSame('500.00', (string) $workorder->supplier_amount);
        $this->assertSame('[已赔付]', $workorder->supplier_reply);
        $this->assertNotNull($workorder->supplier_replied_at);
        $this->assertSame('100.00', $merchant->refresh()->available_balance, '回调金额不能直接加钱');
    }

    private function bindDriver(YunyangDriver $driver): void
    {
        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('buildYunyang')->andReturn($driver);
        ApplicationContext::getContainer()->set(SupplierDriverFactory::class, $factory);
    }

    private function createWorkorder(Order $order, Supplier $supplier, string $type): ExpressWorkorder
    {
        return ExpressWorkorder::create([
            'order_id' => $order->id,
            'supplier_id' => $supplier->id,
            'type' => $type,
            'status' => 'processing',
            'content' => '测试工单',
            'supplier_workorder_no' => 'WO-' . uniqid(),
            'submitted_by' => 1,
        ]);
    }

    private function createExpressOrder(Merchant $merchant, Supplier $supplier, string $status): Order
    {
        $order = Order::create([
            'order_no' => 'E' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'express',
            'status' => $status,
            'sale_price' => '12.00',
            'cost_price' => '10.00',
            'supplier_id' => $supplier->id,
            'frozen_amount' => '12.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);
        $order->fill(['supplier_order_no' => 'YY-' . $order->id])->save();
        $this->orderIds[] = $order->id;

        return $order;
    }

    private function merchantAndSupplier(): array
    {
        $unique = uniqid('workorder_test_', true);
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

        $supplier = Supplier::create([
            'name' => $unique,
            'code' => substr(md5($unique), 0, 24),
            'business_line' => 'express',
            'driver' => 'yunyang',
            'config' => 'unused-in-test-driver-factory-is-overridden',
            'status' => 'active',
        ]);
        $this->supplierIds[] = $supplier->id;

        return [$merchant, $supplier];
    }
}
