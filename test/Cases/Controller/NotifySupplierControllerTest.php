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

namespace HyperfTest\Cases\Controller;

use App\Job\NotifyMerchantJob;
use App\Model\Alert;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\Order;
use App\Model\Supplier;
use App\Model\SupplierProduct;
use App\Model\SupplierProductPriceHistory;
use App\OpenApi\ErrorCode;
use App\Supplier\DriverResult;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\HttpTestCase;
use Mockery;

use function Hyperf\Support\make;

/**
 * `App\Controller\NotifySupplierController` + `App\Service\Order\
 * SupplierCallbackService` 的真实 HTTP 端到端测试（`/notify/{code}` 未挂任何鉴权
 * 中间件，验签本身就是唯一的身份校验，见 Controller 类注释）。
 *
 * 供应商侧的出站 HTTP 调用（`KasushouDriver::parseCallback()` 内部的
 * `queryOrder()`）通过 `App\Supplier\SupplierDriverFactory` 容器 swap 掉，
 * 直接返回预先配置好的 `KasushouDriver` Mockery 双重，不发真实网络请求——
 * 跟 `test/Cases/Service/Order/RechargeOrderPlacementServiceTest.php` 同一个手法。
 *
 * 请求体的具体字段内容对这些测试无关紧要——`KasushouDriver::parseCallback()`
 * 本身被整个 mock 掉了，不会真的去验签，所以这里发的都是占位 payload。
 *
 * 【为什么 `setUp()` 要手动重建整个容器 + 重新 boot，而不是像
 * `RechargeOrderPlacementServiceTest.php` 那样简单 `$this->instance()`
 * 换绑定】`HyperfTest\HttpTestCase`（`test/HttpTestCase.php`）不是
 * `Hyperf\Testing\TestCase`，没有后者每个测试方法都自动
 * `refreshContainer()`（新建一个容器整个替换 `ApplicationContext` 的全局单例、
 * 重新触发一次应用 boot）这件事。踩过的坑，记录清楚以免下次重蹈覆辙：
 *   1. 天真的做法是只做 `ApplicationContext::getContainer()->set(SupplierDriverFactory::
 *      class, $mock)`/`unbind()` 换绑定——这在跟其它同样不刷新容器的
 *      `HttpTestCase` 测试文件放在一起跑时确实没问题；但只要这次进程里跑过任何一个
 *      `Hyperf\Testing\TestCase`（比如 `RechargeOrderPlacementServiceTest`，
 *      它的 `setUp()` 会 `refreshContainer()`）之后再跑本文件，`Hyperf\HttpServer\
 *      CoreMiddleware`（负责按路由把请求分发到 Controller 的核心中间件）就会
 *      变成一个"孤儿"状态：它是在某次 `refreshContainer()` 建出的、之后又被下一次
 *      `refreshContainer()` 替换掉的**旧**容器上构造出来的，但它自己被固定缓存在了
 *      某个进程级单例里（具体是哪个 Hyperf 内部对象在缓存，没有继续深挖，
 *      不影响这里的结论），从此以后处理请求时用的都是那个旧容器，而不是
 *      `ApplicationContext::getContainer()` 当下返回的最新容器——这样一来，
 *      不管在"当下"这个全局容器上怎么 `set()`/`unbind()`，`Controller`
 *      /`SupplierCallbackService`/`SupplierDriverFactory` 这条依赖链在实际处理
 *      请求时用的都还是旧容器里、构造时就已经固化的属性值，表现为不管怎么换
 *      supplier/mock，驱动 mock 的 `Mockery::on()` 参数匹配器永远还是第一次
 *      构造时那个 supplier，后续请求全部报 `NoMatchingExpectationException`
 *      被 `AppExceptionHandler` 兜底成 500——用 `docker exec pf composer test`
 *      跑全量测试时才会现形，只跑本文件（`--filter
 *      NotifySupplierControllerTest`）反而看不出来，因为那样进程里从没发生过
 *      任何一次 `refreshContainer()`。
 *   2. 真正的修复是让本类的 `setUp()` 完整复刻 `Hyperf\Testing\Concerns\
 *      InteractsWithContainer::refreshContainer()` 的做法——新建一个全新的
 *      `Hyperf\Di\Container`、`ApplicationContext::setContainer()` 整个替换掉、
 *      重新 `get(ApplicationInterface::class)` 触发一次完整 boot（含路由注册），
 *      再用这个全新容器重新 `make(Client::class)` 替换 `$this->client`——这样
 *      每个测试方法都拿到一套完全独立、内部一致的容器+路由+中间件图，不会有任何
 *      "构造时绑定的是这个容器、运行时又是另一个容器"的错位，跟
 *      `Hyperf\Testing\TestCase` 系测试的隔离性完全对齐。代价是每个测试方法都要
 *      重新触发一次应用 boot（含注解扫描），比纯换绑定慢，但
 *      `RechargeOrderPlacementServiceTest.php` 已经证明这个代价在这个项目的
 *      测试规模下完全可以接受。
 *
 * @internal
 * @coversNothing
 */
class NotifySupplierControllerTest extends HttpTestCase
{
    private array $merchantIds = [];

    private array $supplierIds = [];

    private array $orderIds = [];

    private array $supplierProductIds = [];

    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->client = make(Client::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $id) {
            MerchantBalanceLog::where('order_id', $id)->delete();
            Alert::where('related_type', 'order')->where('related_id', $id)->delete();
            Order::destroy($id);
        }
        $this->orderIds = [];

        foreach ($this->supplierProductIds as $id) {
            SupplierProductPriceHistory::where('supplier_product_id', $id)->delete();
            SupplierProduct::destroy($id);
        }
        $this->supplierProductIds = [];

        foreach ($this->supplierIds as $id) {
            Supplier::destroy($id);
        }
        $this->supplierIds = [];

        foreach ($this->merchantIds as $id) {
            Merchant::destroy($id);
        }
        $this->merchantIds = [];

        Mockery::close();
        parent::tearDown();
    }

    public function testValidSignedCallbackWithSuccessResultCompletesOrderDeductsBalanceAndNotifies()
    {
        $merchant = $this->createMerchant('90.00', '10.00');
        $supplier = $this->createSupplier();
        $order = $this->createProcessingOrder($merchant->id, $supplier->id, '10.00', '10.00');

        $this->mockDriverParseCallback($supplier, new DriverResult(
            result: UnifiedResult::Success,
            supplierOrderNo: 'SUP-CB-1',
            actualCost: '8.00',
            rawRequest: ['external_orderno' => $order->order_no . '-1', 'day' => 0],
        ));
        $this->expectNotify(1);

        $response = $this->client->request('POST', '/notify/' . $supplier->code . '/' . $supplier->notify_token, [
            'form_params' => ['sign' => 'irrelevant-mocked', 'time' => (string) time()],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string) $response->getBody());

        $order->refresh();
        $this->assertSame('success', $order->status);
        $this->assertSame('8.00', $order->cost_price);
        $this->assertSame('SUP-CB-1', $order->supplier_order_no);
        $this->assertSame('10.00', $order->deducted_amount);
        $this->assertNotNull($order->completed_at);

        $merchant->refresh();
        $this->assertSame('90.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);
        $this->assertSame(1, MerchantBalanceLog::where('order_id', $order->id)->where('type', 'deduct')->count());
    }

    public function testValidSignedCallbackWithDefiniteFailureResultFailsOrderAndUnfreezes()
    {
        $merchant = $this->createMerchant('90.00', '10.00');
        $supplier = $this->createSupplier();
        $order = $this->createProcessingOrder($merchant->id, $supplier->id, '10.00', '10.00');

        $this->mockDriverParseCallback($supplier, new DriverResult(
            result: UnifiedResult::DefiniteFailure,
            failReason: 'kasushou: order status 4',
            rawRequest: ['external_orderno' => $order->order_no . '-1', 'day' => 0],
        ));
        $this->expectNotify(1);

        $response = $this->client->request('POST', '/notify/' . $supplier->code . '/' . $supplier->notify_token, [
            'form_params' => ['sign' => 'irrelevant-mocked', 'time' => (string) time()],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string) $response->getBody());

        $order->refresh();
        $this->assertSame('failed', $order->status);
        // 供应商原始原因不落到订单上，订单只存平台统一文案
        $this->assertSame(ErrorCode::OrderFailed->message(), $order->fail_reason);
        $this->assertNotNull($order->finished_at);

        $merchant->refresh();
        $this->assertSame('100.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);
        $this->assertSame(1, MerchantBalanceLog::where('order_id', $order->id)->where('type', 'unfreeze')->count());
    }

    public function testValidSignedCallbackWithProcessingResultKeepsOrderProcessingAndDoesNotNotify()
    {
        $merchant = $this->createMerchant('90.00', '10.00');
        $supplier = $this->createSupplier();
        $order = $this->createProcessingOrder($merchant->id, $supplier->id, '10.00', '10.00');

        $this->mockDriverParseCallback($supplier, new DriverResult(
            result: UnifiedResult::Processing,
            supplierOrderNo: 'SUP-CB-2',
            rawRequest: ['external_orderno' => $order->order_no . '-1', 'day' => 0],
        ));

        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->shouldNotReceive('get');
        ApplicationContext::getContainer()->set(DriverFactory::class, $driverFactory);

        $response = $this->client->request('POST', '/notify/' . $supplier->code . '/' . $supplier->notify_token, [
            'form_params' => ['sign' => 'irrelevant-mocked', 'time' => (string) time()],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string) $response->getBody());

        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame('SUP-CB-2', $order->supplier_order_no);
        $this->assertNull($order->completed_at);
        $this->assertNull($order->finished_at);

        $merchant->refresh();
        $this->assertSame('90.00', $merchant->available_balance, '处理中不是终态，冻结余额必须原样保留');
        $this->assertSame('10.00', $merchant->frozen_balance);
    }

    /**
     * 全篇最重要的一条：模拟供应商按 kasushou.md 的重试策略（5/10/15/20/25 分钟，
     * 最多 5 次）对一笔已经处理完成的订单重复回调——订单直接以 `success` 终态落库
     * （模拟"上一次回调已经处理过"），且刻意不预先插入任何 `merchant_balance_logs`
     * 行（真实场景下上一次处理时会插入，这里简化成 0 条，只要这次重复回调之后仍然
     * 是 0 条，就证明这次调用没有真的走到 `OrderResultApplier::apply()`/
     * `BalanceService::deduct()`）。驱动 mock 刻意返回一个**不同**的
     * `supplierOrderNo`/`actualCost`（`SUP-CB-DIFFERENT`/`999.99`），如果幂等
     * 防护失效、订单被重新应用了这次结果，这两个字段会被覆盖，断言就会失败——
     * 这比单纯断言"两次都 200"更能证明幂等检查真的生效，不是碰巧看起来正常。
     */
    public function testDuplicateCallbackForAlreadyTerminalOrderDoesNotReapplyResult()
    {
        $merchant = $this->createMerchant('90.00', '0.00');
        $supplier = $this->createSupplier();
        $order = $this->createTerminalOrder($merchant->id, $supplier->id, 'success', [
            'cost_price' => '8.00',
            'supplier_order_no' => 'SUP-CB-1',
            'deducted_amount' => '10.00',
            'completed_at' => date('Y-m-d H:i:s'),
            'finished_at' => date('Y-m-d H:i:s'),
        ]);

        $this->mockDriverParseCallback($supplier, new DriverResult(
            result: UnifiedResult::Success,
            supplierOrderNo: 'SUP-CB-DIFFERENT',
            actualCost: '999.99',
            rawRequest: ['external_orderno' => $order->order_no . '-1', 'day' => 0],
        ));

        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->shouldNotReceive('get');
        ApplicationContext::getContainer()->set(DriverFactory::class, $driverFactory);

        $response = $this->client->request('POST', '/notify/' . $supplier->code . '/' . $supplier->notify_token, [
            'form_params' => ['sign' => 'irrelevant-mocked', 'time' => (string) time()],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string) $response->getBody());

        $order->refresh();
        $this->assertSame('success', $order->status);
        $this->assertSame('8.00', $order->cost_price, '已终态订单不应该被重复回调的新结果覆盖');
        $this->assertSame('SUP-CB-1', $order->supplier_order_no);

        $this->assertSame(
            0,
            MerchantBalanceLog::where('order_id', $order->id)->count(),
            '幂等防护应该在到达 BalanceService 之前短路，不应该产生任何新流水'
        );

        $merchant->refresh();
        $this->assertSame('90.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);
    }

    /**
     * 成功之后卡速售推来"已退款"（kasushou.md 第 2 节"3 之后变为 5"）：全额退款自动按售后核实未到账处理，
     * 退回商户、订单改已退款、回调商户并告警（requirements.md 7.1）。
     */
    public function testFullRefundCallbackAfterSuccessRefundsMerchantAndAlerts()
    {
        $merchant = $this->createMerchant('90.00', '0.00');
        $supplier = $this->createSupplier();
        $order = $this->createTerminalOrder($merchant->id, $supplier->id, 'success', [
            'deducted_amount' => '10.00',
            'completed_at' => date('Y-m-d H:i:s'),
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
        $this->mockDriverParseCallback($supplier, new DriverResult(
            result: UnifiedResult::DefiniteFailure,
            failReason: 'kasushou: order status 5',
            refundAmount: '9.00',
            rawRequest: ['external_orderno' => $order->order_no . '-1', 'day' => 0],
        ));
        $this->expectNotify(1);

        $response = $this->client->request('POST', '/notify/' . $supplier->code . '/' . $supplier->notify_token, [
            'form_params' => ['sign' => 'irrelevant-mocked', 'time' => (string) time()],
        ]);

        $this->assertSame('ok', (string) $response->getBody());
        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame('10.00', $order->refunded_amount);
        $this->assertSame('100.00', $merchant->refresh()->available_balance);
        $this->assertSame(1, Alert::where('type', Alert::TYPE_SUPPLIER_REFUND_AFTER_SUCCESS)->where('related_id', $order->id)->count());
    }

    public function testTamperedSignatureCallbackIsRejectedWithoutSideEffects()
    {
        $merchant = $this->createMerchant('90.00', '10.00');
        $supplier = $this->createSupplier();
        $order = $this->createProcessingOrder($merchant->id, $supplier->id, '10.00', '10.00');

        // 验签失败：KasushouDriver::parseCallback() 返回 null。
        $this->mockDriverParseCallback($supplier, null);

        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->shouldNotReceive('get');
        ApplicationContext::getContainer()->set(DriverFactory::class, $driverFactory);

        $response = $this->client->request('POST', '/notify/' . $supplier->code . '/' . $supplier->notify_token, [
            'form_params' => ['sign' => 'tampered', 'time' => (string) time()],
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotSame('ok', (string) $response->getBody());

        $order->refresh();
        $this->assertSame('processing', $order->status);

        $this->assertSame(0, MerchantBalanceLog::where('order_id', $order->id)->count());

        $merchant->refresh();
        $this->assertSame('90.00', $merchant->available_balance);
        $this->assertSame('10.00', $merchant->frozen_balance);
    }

    public function testUnknownSupplierCodeReturns404WithoutCrashing()
    {
        $response = $this->client->request('POST', '/notify/does-not-exist-' . uniqid('', true) . '/any-token', [
            'form_params' => ['sign' => 'irrelevant', 'time' => (string) time()],
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testWrongTokenReturns404LikeUnknownSupplier()
    {
        $supplier = $this->createSupplier();
        $this->assertSame(32, strlen($supplier->notify_token));

        foreach (['/notify/' . $supplier->code . '/wrong-token', '/notify/' . $supplier->code . '/wrong-token/goods'] as $path) {
            $response = $this->client->request('POST', $path, [
                'form_params' => ['sign' => 'irrelevant', 'time' => (string) time()],
            ]);
            $this->assertSame(404, $response->getStatusCode(), $path);
        }
        // 老的无令牌地址不再有路由
        $this->assertSame(404, $this->client->request('POST', '/notify/' . $supplier->code, ['form_params' => []])->getStatusCode());
    }

    public function testUnresolvableOrderNoIsRejectedCleanlyNotFiveHundred()
    {
        $supplier = $this->createSupplier();

        $this->mockDriverParseCallback($supplier, new DriverResult(
            result: UnifiedResult::Processing,
            rawRequest: ['external_orderno' => 'RNOSUCHORDER999999-1', 'day' => 0],
        ));

        $response = $this->client->request('POST', '/notify/' . $supplier->code . '/' . $supplier->notify_token, [
            'form_params' => ['sign' => 'irrelevant-mocked', 'time' => (string) time()],
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNotSame('ok', (string) $response->getBody());
    }

    public function testProductChangeNotificationUpdatesMappingAndRepliesOk()
    {
        $supplier = $this->createSupplier();
        $mapping = SupplierProduct::create([
            'product_id' => random_int(100000, 999999),
            'supplier_id' => $supplier->id,
            'supplier_product_code' => 'GOODS-NOTIFY-1',
            'cost_price' => '10.00',
            'priority' => 1,
            'status' => 'active',
        ]);
        $this->supplierProductIds[] = $mapping->id;

        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('parseProductChangeNotification')->once()->andReturn('GOODS-NOTIFY-1');
        $driver->shouldReceive('queryProductDetail')->once()->with('GOODS-NOTIFY-1')->andReturn([
            'supplier_product_code' => 'GOODS-NOTIFY-1', 'cost_price' => '10.80', 'status' => 'active', 'stock' => 3,
        ]);
        $this->bindDriver($supplier, $driver);

        $response = $this->client->request('POST', '/notify/' . $supplier->code . '/' . $supplier->notify_token . '/goods', [
            'form_params' => ['id' => 'GOODS-NOTIFY-1', 'time' => (string) time(), 'sign' => 'irrelevant-mocked'],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string) $response->getBody());
        $mapping->refresh();
        $this->assertSame('10.80', $mapping->cost_price);
        $this->assertSame(3, $mapping->stock);
    }

    public function testProductChangeNotificationWithBadSignatureIs403()
    {
        $supplier = $this->createSupplier();
        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('parseProductChangeNotification')->once()->andReturnNull();
        $driver->shouldNotReceive('queryProductDetail');
        $this->bindDriver($supplier, $driver);

        $response = $this->client->request('POST', '/notify/' . $supplier->code . '/' . $supplier->notify_token . '/goods', [
            'form_params' => ['id' => 'GOODS-X', 'time' => (string) time(), 'sign' => 'forged'],
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotSame('ok', (string) $response->getBody());
    }

    public function testProductChangeNotificationForUnknownSupplierIs404()
    {
        $response = $this->client->request('POST', '/notify/no-such-supplier-code/any-token/goods', [
            'form_params' => ['id' => 'GOODS-X', 'time' => (string) time(), 'sign' => 'x'],
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    private function bindDriver(Supplier $supplier, KasushouDriver $driver): void
    {
        $driverFactory = Mockery::mock(SupplierDriverFactory::class);
        $driverFactory->shouldReceive('build')
            ->with(Mockery::on(static fn (Supplier $s) => $s->id === $supplier->id))
            ->andReturn($driver);

        ApplicationContext::getContainer()->set(SupplierDriverFactory::class, $driverFactory);
    }

    private function mockDriverParseCallback(Supplier $supplier, ?DriverResult $result): void
    {
        $driver = Mockery::mock(KasushouDriver::class);
        $driver->shouldReceive('parseCallback')->once()->andReturn($result);

        $driverFactory = Mockery::mock(SupplierDriverFactory::class);
        $driverFactory->shouldReceive('build')
            ->with(Mockery::on(static fn (Supplier $s) => $s->id === $supplier->id))
            ->andReturn($driver);

        ApplicationContext::getContainer()->set(SupplierDriverFactory::class, $driverFactory);
    }

    private function expectNotify(int $times): void
    {
        $asyncDriver = Mockery::mock(DriverInterface::class);
        $asyncDriver->shouldReceive('push')->times($times)->with(Mockery::type(NotifyMerchantJob::class))->andReturnTrue();

        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->shouldReceive('get')->times($times)->with('default')->andReturn($asyncDriver);
        ApplicationContext::getContainer()->set(DriverFactory::class, $driverFactory);
    }

    private function createMerchant(string $availableBalance, string $frozenBalance): Merchant
    {
        $unique = uniqid('notify_ctrl_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => 'encrypted-secret-placeholder',
            'available_balance' => $availableBalance,
            'frozen_balance' => $frozenBalance,
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createSupplier(): Supplier
    {
        $unique = uniqid('notify_ctrl_test_supplier_', true);
        $code = substr(md5($unique), 0, 24);

        $supplier = Supplier::create([
            'name' => $unique,
            'code' => $code,
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => 'unused-in-test-driver-factory-is-overridden',
            'status' => 'active',
        ]);

        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }

    private function createProcessingOrder(int $merchantId, int $supplierId, string $salePrice, string $frozenAmount): Order
    {
        $order = Order::create([
            'order_no' => $this->uniqueOrderNo(),
            'merchant_id' => $merchantId,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => 'processing',
            'sale_price' => $salePrice,
            'cost_price' => '9.00',
            'supplier_id' => $supplierId,
            'supplier_order_no' => null,
            'frozen_amount' => $frozenAmount,
            'deducted_amount' => null,
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);

        $this->orderIds[] = $order->id;

        return $order;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function createTerminalOrder(int $merchantId, int $supplierId, string $status, array $extra): Order
    {
        $order = Order::create(array_merge([
            'order_no' => $this->uniqueOrderNo(),
            'merchant_id' => $merchantId,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => $status,
            'sale_price' => '10.00',
            'cost_price' => '9.00',
            'supplier_id' => $supplierId,
            'supplier_order_no' => null,
            'frozen_amount' => '10.00',
            'deducted_amount' => null,
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
        ], $extra));

        $this->orderIds[] = $order->id;

        return $order;
    }

    private function uniqueOrderNo(): string
    {
        // order_no 不能包含 '-'（见 SupplierCallbackService 类注释的截取规则），
        // 用固定前缀 + 时间戳 + 随机数拼出跟 RechargeOrderPlacementService::
        // generateOrderNo() 同形状但保证测试间互不相同的号。
        return 'R' . date('YmdHis') . random_int(100000, 999999);
    }
}
