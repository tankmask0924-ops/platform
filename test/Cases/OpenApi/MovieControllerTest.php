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
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantBusinessSubscription;
use App\Model\MovieCinema;
use App\Model\MovieCity;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderMovie;
use App\Model\PricingRule;
use App\Model\Supplier;
use App\OpenApi\ErrorCode;
use App\Signature\SignatureSigner;
use App\Supplier\DriverResult;
use App\Supplier\Mango\MangoDriver;
use App\Supplier\Mango\MangoStatusMapper;
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
use Mockery\MockInterface;
use RuntimeException;

use function Hyperf\Support\make;

/**
 * 开放 API 电影票（requirements.md 7.3、8.1）端到端：走完整中间件栈，芒果驱动 mock。
 * 结算细节在 MovieOrderSettlementServiceTest，这里验查询加价、锁座编排、确认出票、释放座位。
 *
 * 加价规则：电影票每张成本 + 2 元。场次 S1 不分区（成本 38 → 售价 40），场次 S2 分区（A 区成本 50 → 52）。
 * `pricing_rules` 备份/还原同 ExpressQuoteControllerTest。
 *
 * @internal
 * @coversNothing
 */
class MovieControllerTest extends HttpTestCase
{
    private const SECRET = 'movie-secret';

    private array $merchantIds = [];

    private Supplier $supplier;

    /** @var list<array<string, mixed>> */
    private array $existingRules = [];

    private MangoDriver|MockInterface $driver;

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
        PricingRule::create(['business_line' => 'movie', 'rule_type' => 'fixed', 'value' => '2']);

        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->client = make(Client::class);

        $queue = Mockery::mock(DriverInterface::class);
        $queue->shouldReceive('push')->andReturnTrue();
        $queueFactory = Mockery::mock(DriverFactory::class);
        $queueFactory->shouldReceive('get')->andReturn($queue);
        ApplicationContext::getContainer()->set(DriverFactory::class, $queueFactory);

        // 已有的其它电影票供应商会被优先选中，测试期间先停用
        Supplier::where('business_line', 'movie')->where('status', 'active')->update(['status' => 'disabled', 'remark' => 'movie-test-paused']);
        $unique = uniqid('movie_api_', true);
        $this->supplier = Supplier::create([
            'name' => $unique,
            'code' => substr(md5($unique), 0, 24),
            'business_line' => 'movie',
            'driver' => 'mango',
            'config' => 'unused',
            'status' => 'active',
        ]);

        $this->driver = Mockery::mock(MangoDriver::class);
        $this->driver->shouldReceive('queryShows')->andReturn([
            ['show_id' => 'S1', 'cinema_id' => 'C1', 'film_id' => 'F1', 'show_time' => '2026-09-30 19:30:00', 'cost' => '38.00', 'areas' => [], 'attributes' => ['hall_name' => '1 号厅', 'film_name' => '长安三万里']],
            ['show_id' => 'S2', 'cinema_id' => 'C1', 'film_id' => 'F1', 'show_time' => '2026-09-30 21:00:00', 'cost' => null, 'areas' => [
                ['area_id' => 'A', 'area_name' => '中心区', 'cost' => '50.00'],
                ['area_id' => 'B', 'area_name' => '边缘区', 'cost' => null],
            ], 'attributes' => []],
        ])->byDefault();
        $this->driver->shouldReceive('querySeats')->andReturn($this->seatMap())->byDefault();
        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('buildMango')->andReturn($this->driver);
        ApplicationContext::getContainer()->set(SupplierDriverFactory::class, $factory);
    }

    protected function tearDown(): void
    {
        foreach (Order::whereIn('merchant_id', $this->merchantIds ?: [0])->pluck('id') as $id) {
            OrderMovie::where('order_id', $id)->delete();
            OrderAttempt::where('order_id', $id)->delete();
            MerchantBalanceLog::where('order_id', $id)->delete();
            Order::destroy($id);
        }
        MerchantBusinessSubscription::whereIn('merchant_id', $this->merchantIds ?: [0])->delete();
        Merchant::destroy($this->merchantIds);
        MovieCity::where('supplier_id', $this->supplier->id)->delete();
        MovieCinema::where('supplier_id', $this->supplier->id)->delete();
        Supplier::destroy($this->supplier->id);
        Supplier::where('remark', 'movie-test-paused')->update(['status' => 'active', 'remark' => null]);
        $this->merchantIds = [];

        PricingRule::query()->delete();
        foreach ($this->existingRules as $rule) {
            PricingRule::query()->insert($rule);
        }
        Mockery::close();

        parent::tearDown();
    }

    public function testCitiesAndCinemasComeFromCache()
    {
        $merchant = $this->createMerchant();
        MovieCity::create(['supplier_id' => $this->supplier->id, 'city_id' => 'SZ', 'city_name' => '深圳', 'first_letter' => 'S', 'is_hot' => true, 'synced_at' => date('Y-m-d H:i:s')]);
        MovieCinema::create(['supplier_id' => $this->supplier->id, 'cinema_id' => 'C1', 'cinema_name' => '万达影城', 'city_id' => 'SZ', 'region_id' => 'NS', 'synced_at' => date('Y-m-d H:i:s')]);
        $this->driver->shouldNotReceive('queryCities');

        $cities = $this->get('/open-api/movie/cities', $merchant)['data']['cities'];
        $this->assertSame([['city_id' => 'SZ', 'city_name' => '深圳', 'first_letter' => 'S', 'is_hot' => true]], $cities);

        $cinemas = $this->get('/open-api/movie/cinemas', $merchant, ['city_id' => 'SZ', 'region_id' => 'NS'])['data'];
        $this->assertSame(1, $cinemas['total']);
        $this->assertSame('万达影城', $cinemas['data'][0]['cinema_name']);
    }

    /**
     * 场次价格换成售价，成本数字绝不出现；分区场次按区给价，缺成本的区不返回。
     */
    public function testShowsArePricedAndHideCost()
    {
        $merchant = $this->createMerchant();

        $body = $this->get('/open-api/movie/shows', $merchant, ['cinema_id' => 'C1', 'film_id' => 'F1']);

        $shows = $body['data']['shows'];
        $this->assertSame('40.00', $shows[0]['price']);
        $this->assertNull($shows[1]['price'], '分区场次看各区的价');
        $this->assertSame([['area_id' => 'A', 'area_name' => '中心区', 'price' => '52.00']], $shows[1]['areas']);
        $encoded = json_encode($body);
        $this->assertStringNotContainsString('38.00', $encoded);
        $this->assertStringNotContainsString('50.00', $encoded);
    }

    public function testQueryFailureIsReportedAsUnavailable()
    {
        $merchant = $this->createMerchant();
        $this->driver->shouldReceive('querySeats')->andThrow(new RuntimeException('场次不存在'));

        $this->assertSame(ErrorCode::MovieUnavailable->value, $this->get('/open-api/movie/seats', $merchant, ['show_id' => 'S1'])['code']);
    }

    /**
     * 锁座：重新核价（商户传的价格不采用），冻结 = 每张售价 × 张数，座位条目带分区交给驱动，平台单号放 attach。
     */
    public function testLockFreezesRequotedPriceTimesSeatCount()
    {
        $merchant = $this->createMerchant();
        $this->driver->shouldReceive('lockSeats')->once()
            ->withArgs(fn (string $showId, array $seats, string $mobile, string $orderNo) => $showId === 'S1'
                && array_column($seats, 'seat_code') === ['1-3', '1-4']
                && array_key_exists('area_id', $seats[0])
                && $mobile === '13800000000' && str_starts_with($orderNo, 'M'))
            ->andReturn(new DriverResult(result: UnifiedResult::Processing, supplierOrderNo: 'MG-1', actualCost: '76.00', movieDetails: ['lock_ttl_seconds' => 600]));

        $body = $this->post('/open-api/movie/lock', $merchant, $this->lockParams(['price' => '0.01']));

        $this->assertSame(0, $body['code'], json_encode($body, JSON_UNESCAPED_UNICODE));
        $data = $body['data'];
        $this->assertSame('processing', $data['status']);
        $this->assertSame('80.00', $data['sale_price']);
        $this->assertSame('40.00', $data['movie']['unit_price']);
        $this->assertSame('长安三万里', $data['movie']['film_name']);
        $this->assertSame(2, $data['movie']['seat_count']);
        $this->assertStringNotContainsString('38.00', json_encode($data), '成本不外泄');
        $this->assertStringNotContainsString('MG-1', json_encode($data), '芒果单号不外泄');
        $this->assertBalance($merchant, '20.00', '80.00');

        $order = Order::where('order_no', $data['order_no'])->first();
        $this->assertSame('MG-1', $order->supplier_order_no);
        $this->assertSame('76.00', $order->cost_price);
    }

    public function testLockOnPartitionedShowUsesAreaPrice()
    {
        $merchant = $this->createMerchant();
        $this->driver->shouldReceive('querySeats')->andReturn($this->seatMap('A'));
        $this->driver->shouldReceive('lockSeats')->once()
            ->andReturn(new DriverResult(result: UnifiedResult::Processing, supplierOrderNo: 'MG-2', movieDetails: ['lock_ttl_seconds' => 600]));

        $data = $this->post('/open-api/movie/lock', $merchant, $this->lockParams(['show_id' => 'S2', 'seat_codes' => '1-3']))['data'];

        $this->assertSame('52.00', $data['sale_price']);
        $this->assertSame('A', $data['movie']['area_id']);
    }

    /**
     * 选座不合法（隔空选座、超过 4 座、座位不存在）直接拒绝，不建单、不冻结、不调芒果。
     */
    public function testInvalidSeatSelectionIsRejectedBeforeLocking()
    {
        $merchant = $this->createMerchant();
        $this->driver->shouldNotReceive('lockSeats');

        foreach (['1-2', '1-1,1-2,1-3,1-4,1-5', '9-9'] as $seats) {
            $body = $this->post('/open-api/movie/lock', $merchant, $this->lockParams(['seat_codes' => $seats]));
            $this->assertSame(ErrorCode::InvalidParams->value, $body['code'], $seats);
        }
        $this->assertSame(ErrorCode::MovieShowNotFound->value, $this->post('/open-api/movie/lock', $merchant, $this->lockParams(['show_id' => 'NOPE']))['code']);
        $this->assertSame(0, Order::where('merchant_id', $merchant->id)->count());
    }

    /**
     * 订单溢价：失败、全额解冻、失败码 43004 让商户稍后重新查场次。
     */
    public function testPriceChangedLockFailsWithDedicatedCode()
    {
        $merchant = $this->createMerchant();
        $this->driver->shouldReceive('lockSeats')->once()
            ->andReturn(new DriverResult(result: UnifiedResult::DefiniteFailure, failReason: 'mango: code 10040', movieDetails: ['price_changed' => true]));

        $data = $this->post('/open-api/movie/lock', $merchant, $this->lockParams())['data'];

        $this->assertSame('failed', $data['status']);
        $this->assertSame(ErrorCode::MoviePriceChanged->value, $data['fail_code']);
        $this->assertBalance($merchant, '100.00', '0.00');
    }

    /**
     * 确认出票只调一次芒果；芒果受理后立刻查一次，出票成功就直接返回取票码。
     */
    public function testConfirmCallsSupplierOnceAndSettles()
    {
        $merchant = $this->createMerchant();
        $orderNo = $this->lockOne($merchant);
        $this->driver->shouldReceive('confirmOrder')->once()->with('MG-1')
            ->andReturn(new DriverResult(result: UnifiedResult::Processing, supplierOrderNo: 'MG-1'));
        $this->driver->shouldReceive('queryOrder')->once()->with('MG-1')->andReturn(new DriverResult(
            result: UnifiedResult::Success,
            supplierOrderNo: 'MG-1',
            movieDetails: ['handle_step' => MangoStatusMapper::STEP_ISSUED, 'tickets' => [['code' => 'T-888']], 'lock_expired' => false],
        ));

        $data = $this->post('/open-api/movie/confirm', $merchant, ['order_no' => $orderNo])['data'];
        $this->assertSame('success', $data['status']);
        $this->assertSame([['code' => 'T-888']], $data['movie']['ticket_codes']);
        $this->assertNotNull($data['movie']['confirmed_at']);
        $this->assertBalance($merchant, '20.00', '0.00');

        $again = $this->post('/open-api/movie/confirm', $merchant, ['order_no' => $orderNo]);
        $this->assertSame(0, $again['code'], '重复确认返回当前状态，不再调芒果');
    }

    public function testConfirmAfterLockExpiredIsRejected()
    {
        $merchant = $this->createMerchant();
        $orderNo = $this->lockOne($merchant);
        OrderMovie::where('order_id', Order::where('order_no', $orderNo)->value('id'))->update(['lock_expire_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->driver->shouldNotReceive('confirmOrder');

        $this->assertSame(ErrorCode::MovieLockExpired->value, $this->post('/open-api/movie/confirm', $merchant, ['order_no' => $orderNo])['code']);
    }

    /**
     * 释放座位：订单取消、全额解冻、通知芒果释放；已确认出票的不能释放。
     */
    public function testReleaseCancelsAndUnfreezes()
    {
        $merchant = $this->createMerchant();
        $orderNo = $this->lockOne($merchant);
        $this->driver->shouldReceive('releaseSeats')->once()->with('MG-1')->andReturn(['released' => true, 'message' => '']);

        $data = $this->post('/open-api/movie/release', $merchant, ['order_no' => $orderNo])['data'];

        $this->assertSame('cancelled', $data['status']);
        $this->assertBalance($merchant, '100.00', '0.00');
        $this->assertSame(ErrorCode::OrderNotCancellable->value, $this->post('/open-api/movie/release', $merchant, ['order_no' => $orderNo])['code']);
    }

    public function testOrderQueryIncludesMovieDetailsAndIsolation()
    {
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        $orderNo = $this->lockOne($merchant);

        $data = $this->get('/open-api/order', $merchant, ['order_no' => $orderNo])['data'];
        $this->assertSame('S1', $data['movie']['show_id']);
        $this->assertSame(ErrorCode::OrderNotFound->value, $this->post('/open-api/movie/confirm', $other, ['order_no' => $orderNo])['code']);
    }

    public function testNotSubscribedIsRejected()
    {
        $merchant = $this->createMerchant(subscribed: false);

        $this->assertSame(ErrorCode::BusinessNotSubscribed->value, $this->get('/open-api/movie/cities', $merchant)['code']);
    }

    private function lockOne(Merchant $merchant): string
    {
        $this->driver->shouldReceive('lockSeats')->once()
            ->andReturn(new DriverResult(result: UnifiedResult::Processing, supplierOrderNo: 'MG-1', movieDetails: ['lock_ttl_seconds' => 600]));

        return $this->post('/open-api/movie/lock', $merchant, $this->lockParams())['data']['order_no'];
    }

    /**
     * 一排 8 个座位，第 8 个已售。
     *
     * @return list<array<string, mixed>>
     */
    private function seatMap(?string $area = null): array
    {
        $seats = [];
        for ($col = 1; $col <= 8; ++$col) {
            $seats[] = ['seat_code' => '1-' . $col, 'row' => 1, 'col' => $col, 'row_label' => '1', 'col_label' => (string) $col,
                'area_id' => $area, 'love_status' => 0, 'available' => $col !== 8];
        }

        return $seats;
    }

    /**
     * @param array<string, string> $override
     * @return array<string, string>
     */
    private function lockParams(array $override = []): array
    {
        return array_merge([
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'callback_url' => 'https://merchant.example.com/notify',
            'cinema_id' => 'C1',
            'film_id' => 'F1',
            'show_id' => 'S1',
            'seat_codes' => '1-3,1-4',
            'mobile' => '13800000000',
        ], $override);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function post(string $uri, Merchant $merchant, array $params): array
    {
        return json_decode((string) $this->client->request('POST', $uri, ['form_params' => $this->signed($merchant, $params)])->getBody(), true);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function get(string $uri, Merchant $merchant, array $params = []): array
    {
        return json_decode((string) $this->client->request('GET', $uri, ['query' => $this->signed($merchant, $params)])->getBody(), true);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function signed(Merchant $merchant, array $params): array
    {
        $params = ['app_key' => $merchant->app_key, 'timestamp' => (string) time(), 'nonce' => uniqid('nonce_', true)] + $params;
        $params['sign'] = (new SignatureSigner())->sign($params, self::SECRET);

        return $params;
    }

    private function assertBalance(Merchant $merchant, string $available, string $frozen): void
    {
        $fresh = Merchant::find($merchant->id);
        $this->assertSame([$available, $frozen], [$fresh->available_balance, $fresh->frozen_balance]);
    }

    private function createMerchant(bool $subscribed = true): Merchant
    {
        $unique = uniqid('movie_api_merchant_', true);
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
        if ($subscribed) {
            MerchantBusinessSubscription::create([
                'merchant_id' => $merchant->id,
                'business_line' => 'movie',
                'status' => 'approved',
                'applied_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $merchant;
    }
}
