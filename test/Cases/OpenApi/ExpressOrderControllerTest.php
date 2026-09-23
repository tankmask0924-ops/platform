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

namespace HyperfTest\Cases\OpenApi;

use App\Crypto\Encryptor;
use App\Express\ExpressChannelCodec;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantBusinessSubscription;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderExpress;
use App\Model\OrderExpressFeeAdjustment;
use App\Model\PricingRule;
use App\Model\Supplier;
use App\OpenApi\ErrorCode;
use App\Signature\SignatureSigner;
use App\Supplier\DriverResult;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use App\Supplier\Yunyang\YunyangDriver;
use App\Supplier\Yunyang\YunyangStatusMapper;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\HttpTestCase;
use Mockery;
use Mockery\MockInterface;

use function Hyperf\Support\make;

/**
 * 开放 API「快递下单 / 取消 / 轨迹」（requirements.md 7.2、8.1）端到端：走完整中间件栈，
 * 云洋驱动 mock。资金推进的细节（结算、费用调整）在 ExpressOrderSettlementServiceTest，
 * 这里验下单编排：重新查价、按售价冻结、渠道编号换回、同步结果怎么落、幂等、取消。
 *
 * `pricing_rules` 备份/还原同 ExpressQuoteControllerTest；加价规则"运费成本 + 2 元"。
 *
 * @internal
 * @coversNothing
 */
class ExpressOrderControllerTest extends HttpTestCase
{
    private const SECRET = 'express-order-secret';

    private array $merchantIds = [];

    private array $supplierIds = [];

    /** @var list<array<string, mixed>> */
    private array $existingRules = [];

    private Supplier $supplier;

    private MockInterface|YunyangDriver $driver;

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
        $this->client = make(Client::class);

        $queue = Mockery::mock(DriverInterface::class);
        $queue->shouldReceive('push')->andReturnTrue();
        $queueFactory = Mockery::mock(DriverFactory::class);
        $queueFactory->shouldReceive('get')->andReturn($queue);
        ApplicationContext::getContainer()->set(DriverFactory::class, $queueFactory);

        $unique = uniqid('express_order_supplier_', true);
        $this->supplier = Supplier::create([
            'name' => $unique,
            'code' => substr(md5($unique), 0, 24),
            'business_line' => 'express',
            'driver' => 'yunyang',
            'config' => 'unused',
            'status' => 'active',
        ]);
        $this->supplierIds[] = $this->supplier->id;

        // 顺丰：运费成本 10、保价费 1、耗材费 0.5 → 售价 12 + 1 + 0.5 = 13.50
        $this->driver = Mockery::mock(YunyangDriver::class);
        $this->driver->shouldReceive('checkChannels')->andReturn([
            $this->channel('CH-SF', '顺丰', '10.00', insured: '1.00', haocai: '0.50', times: ['09:00-12:00']),
            $this->channel('CH-ZTO', '中通', '8.00', allowInsured: false),
        ])->byDefault();
        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('buildYunyang')->andReturn($this->driver);
        ApplicationContext::getContainer()->set(SupplierDriverFactory::class, $factory);
    }

    protected function tearDown(): void
    {
        $orderIds = Order::whereIn('merchant_id', $this->merchantIds ?: [0])->pluck('id')->all();
        foreach ($orderIds as $id) {
            OrderExpressFeeAdjustment::where('order_id', $id)->delete();
            OrderExpress::where('order_id', $id)->delete();
            OrderAttempt::where('order_id', $id)->delete();
            MerchantBalanceLog::where('order_id', $id)->delete();
            Order::destroy($id);
        }
        MerchantBusinessSubscription::whereIn('merchant_id', $this->merchantIds ?: [0])->delete();
        Merchant::destroy($this->merchantIds);
        Supplier::destroy($this->supplierIds);
        $this->merchantIds = $this->supplierIds = [];

        PricingRule::query()->delete();
        foreach ($this->existingRules as $rule) {
            PricingRule::query()->insert($rule);
        }
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 按重新查到的价格冻结（不信商户传的金额），把平台渠道编号换回云洋渠道 ID 下单，
     * 平台订单号放 extendField1；云洋冻结的运费比查价高 1 元 → 冻结金额跟着调到 14.50。
     */
    public function testPlaceOrderFreezesRequotedPriceAndAdjustsToSupplierFreight()
    {
        $merchant = $this->createMerchant();
        $this->driver->shouldReceive('placeOrder')->once()
            ->withArgs(function (string $orderNo, string $channelId, array $content) {
                return str_starts_with($orderNo, 'E') && $channelId === 'CH-SF'
                    && $content['senderName'] === '张三' && $content['receiverMobile'] === '13800000000'
                    && $content['insured'] === '500.00' && $content['itemName'] === '文件'
                    && $content['appointmentTime'] === '09:00-12:00';
            })
            ->andReturn($this->placed(freight: '11.00'));

        $body = $this->post('/open-api/express/order', $merchant, $this->orderParams(['insured_amount' => '500', 'sale_price' => '0.01', 'appointment_time' => '09:00-12:00']));

        $this->assertSame(0, $body['code'], json_encode($body, JSON_UNESCAPED_UNICODE));
        $data = $body['data'];
        $this->assertSame('processing', $data['status']);
        $this->assertSame('14.50', $data['sale_price'], '11 + 2 + 1 + 0.5，商户传的 sale_price 不采用');
        $this->assertSame('14.50', $data['frozen_amount']);
        $this->assertSame('WB-1', $data['express']['waybill_no']);
        $this->assertSame('pending_pickup', $data['express']['logistics_status']);
        $this->assertNull($data['express']['fees'], '还没扣费，没有实际费用');
        $this->assertStringNotContainsString('CH-SF', json_encode($body), '供应商渠道 ID 不能外泄');
        $this->assertStringNotContainsString('SB-1', json_encode($body), '云洋单号不能外泄');

        $fresh = Merchant::find($merchant->id);
        $this->assertSame(['85.50', '14.50'], [$fresh->available_balance, $fresh->frozen_balance]);

        $order = Order::where('order_no', $data['order_no'])->first();
        $this->assertSame('SB-1', $order->supplier_order_no);
        $this->assertSame('12.50', $order->cost_price, '冻结运费 11 + 保价 1 + 耗材 0.5');
        $express = OrderExpress::find($order->id);
        $this->assertSame('10.00', $express->estimated_freight);
        $this->assertSame('11.00', $express->frozen_freight);
        $this->assertSame('500.00', $express->insured_amount);
        $this->assertSame('13800000000', $express->receiver_info['mobile']);
    }

    /**
     * 云洋明确拒单：订单失败、全额解冻。
     */
    public function testSupplierRejectionFailsTheOrderAndUnfreezes()
    {
        $merchant = $this->createMerchant();
        $this->driver->shouldReceive('placeOrder')->once()
            ->andReturn(new DriverResult(result: UnifiedResult::DefiniteFailure, failReason: 'yunyang: 地址不支持'));

        $data = $this->post('/open-api/express/order', $merchant, $this->orderParams())['data'];

        $this->assertSame('failed', $data['status']);
        $this->assertSame(ErrorCode::OrderFailed->value, $data['fail_code']);
        $this->assertStringNotContainsString('地址不支持', json_encode($data, JSON_UNESCAPED_UNICODE), '不透传供应商原始信息');
        $fresh = Merchant::find($merchant->id);
        $this->assertSame(['100.00', '0.00'], [$fresh->available_balance, $fresh->frozen_balance]);
    }

    /**
     * 下单超时：结果未知，保持处理中、冻结不动，**只调用一次**（云洋没有防重复单号，重试就是重复下单）。
     */
    public function testUnknownResultKeepsOrderProcessingWithoutRetry()
    {
        $merchant = $this->createMerchant();
        $this->driver->shouldReceive('placeOrder')->once()
            ->andReturn(new DriverResult(result: UnifiedResult::Unknown, failReason: 'timeout'));

        $data = $this->post('/open-api/express/order', $merchant, $this->orderParams())['data'];

        $this->assertSame('processing', $data['status']);
        $this->assertSame('13.50', $data['frozen_amount']);
        $order = Order::where('order_no', $data['order_no'])->first();
        $this->assertSame((int) $this->supplier->id, (int) $order->supplier_id, '先记下供应商，之后靠它认领回调');
        $this->assertNull($order->supplier_order_no);
        $this->assertSame('unknown', OrderAttempt::where('order_id', $order->id)->value('result'));
    }

    public function testIdempotentReplayDoesNotPlaceTwice()
    {
        $merchant = $this->createMerchant();
        $this->driver->shouldReceive('placeOrder')->once()->andReturn($this->placed(freight: '10.00'));

        $first = $this->post('/open-api/express/order', $merchant, $this->orderParams(['merchant_order_no' => 'MO-REPLAY']))['data'];
        $second = $this->post('/open-api/express/order', $merchant, $this->orderParams(['merchant_order_no' => 'MO-REPLAY']))['data'];

        $this->assertSame($first['order_no'], $second['order_no']);
        $this->assertSame('13.50', Merchant::find($merchant->id)->frozen_balance);
    }

    /**
     * 渠道编号对不上（乱填 / 渠道下线）或者要保价但渠道不支持保价：42009，不建单、不冻结。
     */
    public function testUnknownOrUnsuitableChannelIsRejectedBeforeFreezing()
    {
        $merchant = $this->createMerchant();
        $this->driver->shouldNotReceive('placeOrder');

        $body = $this->post('/open-api/express/order', $merchant, $this->orderParams(['channel_code' => 'EX0000000000000000']));
        $this->assertSame(ErrorCode::ExpressChannelNotFound->value, $body['code']);

        $zto = make(ExpressChannelCodec::class)->encode((int) $this->supplier->id, 'CH-ZTO');
        $body = $this->post('/open-api/express/order', $merchant, $this->orderParams(['channel_code' => $zto, 'insured_amount' => '100']));
        $this->assertSame(ErrorCode::ExpressChannelNotFound->value, $body['code']);

        $this->assertSame(0, Order::where('merchant_id', $merchant->id)->count());
        $this->assertSame('0.00', Merchant::find($merchant->id)->frozen_balance);
    }

    public function testInvalidParamsAreRejected()
    {
        $merchant = $this->createMerchant();
        $this->driver->shouldNotReceive('placeOrder');

        $cases = [
            ['merchant_order_no' => ''],
            ['channel_code' => ''],
            ['callback_url' => 'not-a-url'],
            ['sender_name' => ''],
            ['receiver_mobile' => 'abc'],
            ['receiver_address' => ''],
            ['item_name' => ''],
            ['weight' => '1.5'],
            // 不是查价返回的时间段
            ['appointment_time' => '20:00-22:00'],
        ];
        foreach ($cases as $override) {
            $body = $this->post('/open-api/express/order', $merchant, $this->orderParams($override));
            $this->assertSame(ErrorCode::InvalidParams->value, $body['code'], json_encode($override));
        }
    }

    /**
     * 取消成功后以云洋查询结果为准解冻；已揽收的单取消不了。
     */
    public function testCancelPendingPickupOrderUnfreezes()
    {
        $merchant = $this->createMerchant();
        $this->driver->shouldReceive('placeOrder')->andReturn($this->placed(freight: '10.00'));
        $orderNo = $this->post('/open-api/express/order', $merchant, $this->orderParams())['data']['order_no'];

        $this->driver->shouldReceive('cancelOrder')->once()->with('SB-1')->andReturn(['cancelled' => true, 'message' => '']);
        $this->driver->shouldReceive('queryOrder')->once()->with('SB-1')->andReturn($this->placed(
            freight: '10.00',
            typeCode: YunyangStatusMapper::TYPE_CANCELLED
        ));

        $data = $this->post('/open-api/express/cancel', $merchant, ['order_no' => $orderNo])['data'];

        $this->assertSame('cancelled', $data['status']);
        $this->assertSame('cancelled', $data['express']['logistics_status']);
        $fresh = Merchant::find($merchant->id);
        $this->assertSame(['100.00', '0.00'], [$fresh->available_balance, $fresh->frozen_balance]);
    }

    public function testCancelIsRejectedOnceShipped()
    {
        $merchant = $this->createMerchant();
        $this->driver->shouldReceive('placeOrder')->andReturn($this->placed(freight: '10.00', typeCode: YunyangStatusMapper::TYPE_IN_TRANSIT));
        $orderNo = $this->post('/open-api/express/order', $merchant, $this->orderParams())['data']['order_no'];
        $this->driver->shouldNotReceive('cancelOrder');

        $body = $this->post('/open-api/express/cancel', $merchant, ['order_no' => $orderNo]);

        $this->assertSame(ErrorCode::OrderNotCancellable->value, $body['code']);
    }

    /**
     * 云洋拒绝取消（部分快递公司不支持接口取消）：统一 42010，不透传云洋的原因文案。
     */
    public function testCancelRejectedBySupplierDoesNotLeakSupplierMessage()
    {
        $merchant = $this->createMerchant();
        $this->driver->shouldReceive('placeOrder')->andReturn($this->placed(freight: '10.00'));
        $orderNo = $this->post('/open-api/express/order', $merchant, $this->orderParams())['data']['order_no'];
        $this->driver->shouldReceive('cancelOrder')->andReturn(['cancelled' => false, 'message' => '云洋：德邦重货不支持取消']);

        $body = $this->post('/open-api/express/cancel', $merchant, ['order_no' => $orderNo]);

        $this->assertSame(ErrorCode::OrderNotCancellable->value, $body['code']);
        $this->assertStringNotContainsString('云洋', json_encode($body, JSON_UNESCAPED_UNICODE));
        $this->assertSame('13.50', Merchant::find($merchant->id)->frozen_balance);
    }

    public function testTraceAndCrossMerchantIsolation()
    {
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        $this->driver->shouldReceive('placeOrder')->andReturn($this->placed(freight: '10.00'));
        $orderNo = $this->post('/open-api/express/order', $merchant, $this->orderParams())['data']['order_no'];
        $this->driver->shouldReceive('queryTrace')->with('SB-1')->andReturn([['time' => '2026-09-23 10:00:00', 'description' => '已揽收']]);

        $data = $this->get('/open-api/express/trace', $merchant, ['order_no' => $orderNo])['data'];
        $this->assertSame('WB-1', $data['waybill_no']);
        $this->assertSame('已揽收', $data['traces'][0]['description']);

        $this->assertSame(ErrorCode::OrderNotFound->value, $this->get('/open-api/express/trace', $other, ['order_no' => $orderNo])['code']);
        $this->assertSame(ErrorCode::OrderNotFound->value, $this->post('/open-api/express/cancel', $other, ['order_no' => $orderNo])['code']);
    }

    /**
     * 订单查询接口对快递订单带上 express 明细。
     */
    public function testOrderQueryIncludesExpressDetails()
    {
        $merchant = $this->createMerchant();
        $this->driver->shouldReceive('placeOrder')->andReturn($this->placed(freight: '10.00'));
        $orderNo = $this->post('/open-api/express/order', $merchant, $this->orderParams())['data']['order_no'];

        $data = $this->get('/open-api/order', $merchant, ['order_no' => $orderNo])['data'];

        $this->assertSame('顺丰', $data['express']['company_name']);
        $this->assertSame('WB-1', $data['express']['waybill_no']);
        $this->assertSame([], $data['express']['fee_adjustments']);
    }

    private function placed(string $freight, int $typeCode = YunyangStatusMapper::TYPE_PENDING_PICKUP): DriverResult
    {
        $feeOver = YunyangStatusMapper::FEE_FROZEN;

        return new DriverResult(
            result: (new YunyangStatusMapper())->map($typeCode, $feeOver),
            supplierOrderNo: 'SB-1',
            rawRequest: ['channelId' => 'CH-SF'],
            rawResponse: ['http_status' => 200],
            expressFees: [
                'fee_over' => $feeOver,
                'type_code' => $typeCode,
                'waybill' => 'WB-1',
                'weight' => null,
                'total_freight' => null,
                'freight' => $freight,
                'freight_insured' => null,
                'freight_haocai' => null,
                'change_bill_freight' => null,
                'platform_order_no' => null,
            ],
        );
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function orderParams(array $override = []): array
    {
        $params = [
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'channel_code' => make(ExpressChannelCodec::class)->encode((int) $this->supplier->id, 'CH-SF'),
            'callback_url' => 'https://merchant.example.com/notify',
            'sender_name' => '张三',
            'sender_mobile' => '13900000000',
            'sender_province' => '广东省',
            'sender_city' => '深圳市',
            'sender_district' => '南山区',
            'sender_address' => '科技园 1 号',
            'receiver_name' => '李四',
            'receiver_mobile' => '13800000000',
            'receiver_province' => '北京市',
            'receiver_city' => '北京市',
            'receiver_district' => '朝阳区',
            'receiver_address' => '建国路 2 号',
            'item_name' => '文件',
            'weight' => '3',
        ];
        foreach ($override as $key => $value) {
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function post(string $uri, Merchant $merchant, array $params): array
    {
        $response = $this->client->request('POST', $uri, ['form_params' => $this->signed($merchant, $params)]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function get(string $uri, Merchant $merchant, array $params): array
    {
        $response = $this->client->request('GET', $uri, ['query' => $this->signed($merchant, $params)]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function signed(Merchant $merchant, array $params): array
    {
        $params = [
            'app_key' => $merchant->app_key,
            'timestamp' => (string) time(),
            'nonce' => uniqid('nonce_', true),
        ] + $params;
        $params['sign'] = (new SignatureSigner())->sign($params, self::SECRET);

        return $params;
    }

    /**
     * @param list<string> $times
     * @return array<string, mixed>
     */
    private function channel(string $channelId, string $name, string $freight, string $insured = '0.00', string $haocai = '0.00', bool $allowInsured = true, array $times = []): array
    {
        return [
            'channel_id' => $channelId,
            'channel_name' => $name,
            'freight' => $freight,
            'freight_insured' => $insured,
            'freight_haocai' => $haocai,
            'total_freight' => $freight,
            'official_price' => null,
            'billing_rule' => null,
            'allow_insured' => $allowInsured,
            'appointment_times' => $times,
        ];
    }

    private function createMerchant(): Merchant
    {
        $unique = uniqid('express_order_', true);
        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => (new Encryptor())->encrypt(self::SECRET),
            'available_balance' => '100.00',
            'frozen_balance' => '0.00',
        ]);
        $this->merchantIds[] = $merchant->id;

        MerchantBusinessSubscription::create([
            'merchant_id' => $merchant->id,
            'business_line' => 'express',
            'status' => 'approved',
            'applied_at' => date('Y-m-d H:i:s'),
        ]);

        return $merchant;
    }
}
