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
use App\Model\MerchantLevel;
use App\Model\MerchantLevelBusinessRate;
use App\Model\MerchantRebate;
use App\Model\MovieCinema;
use App\Model\MovieCity;
use App\Model\MovieRegion;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Model\OrderMovie;
use App\Model\Supplier;
use App\OpenApi\ErrorCode;
use App\Service\Merchant\BalanceService;
use App\Service\Movie\MovieBaseDataSyncService;
use App\Service\OpenApi\MovieOrderService;
use App\Service\Order\MovieCallbackService;
use App\Service\Order\MovieOrderSettlementService;
use App\Service\Order\SupplierCallbackService;
use App\Service\Order\SupplierResultPollingService;
use App\Supplier\DriverResult;
use App\Supplier\Mango\MangoDriver;
use App\Supplier\Mango\MangoStatusMapper;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Carbon\Carbon;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Hyperf\Support\make;

/**
 * 电影票订单资金推进（requirements.md 7.3、5.3、5.4）、锁座超时释放、芒果回调认领，以及城市/影院缓存同步。
 * 芒果驱动全部 mock。示例订单：2 张，每张成本 38、售价 40，冻结 80（需求 5.3 例 2 的数）。
 *
 * @internal
 * @coversNothing
 */
class MovieOrderSettlementServiceTest extends TestCase
{
    private array $merchantIds = [];

    private array $supplierIds = [];

    private array $orderIds = [];

    private array $levelIds = [];

    private int $notifications = 0;

    private MangoDriver|MockInterface $driver;

    protected function setUp(): void
    {
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);

        $this->notifications = 0;
        $queue = Mockery::mock(DriverInterface::class);
        $queue->shouldReceive('push')->andReturnUsing(function () {
            ++$this->notifications;

            return true;
        });
        $queueFactory = Mockery::mock(DriverFactory::class);
        $queueFactory->shouldReceive('get')->andReturn($queue);
        ApplicationContext::getContainer()->set(DriverFactory::class, $queueFactory);

        $this->driver = Mockery::mock(MangoDriver::class);
        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('buildMango')->andReturn($this->driver);
        ApplicationContext::getContainer()->set(SupplierDriverFactory::class, $factory);
    }

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $id) {
            MerchantRebate::where('order_id', $id)->delete();
            OrderMovie::where('order_id', $id)->delete();
            OrderAttempt::where('order_id', $id)->delete();
            MerchantBalanceLog::where('order_id', $id)->delete();
            Order::destroy($id);
        }
        foreach ($this->supplierIds as $id) {
            MovieCity::where('supplier_id', $id)->delete();
            MovieRegion::where('supplier_id', $id)->delete();
            MovieCinema::where('supplier_id', $id)->delete();
        }
        Merchant::destroy($this->merchantIds);
        Supplier::destroy($this->supplierIds);
        MerchantLevelBusinessRate::whereIn('level_id', $this->levelIds ?: [0])->delete();
        MerchantLevel::destroy($this->levelIds);
        $this->orderIds = $this->merchantIds = $this->supplierIds = $this->levelIds = [];
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 出票成功：冻结的 80 全额扣款，完成时间 = 出票时间，写入取票码；供应商返佣 3.00 × 金牌电影票比例 80% = 2.40，
     * 基数来源 supplier（5.3 例 2）。
     */
    public function testIssuedDeductsAndGeneratesSupplierBasedRebate()
    {
        Carbon::setTestNow('2026-09-23 20:00:00');
        [$merchant, $supplier, $order] = $this->lockedOrder(levelRate: '0.8000');

        $this->service()->apply($order, $this->issued(tickets: [['code' => 'T1']], rebate: '3.00'), (int) $supplier->id);

        $order->refresh();
        $this->assertSame('success', $order->status);
        $this->assertSame('80.00', $order->deducted_amount);
        $this->assertSame('2026-09-23 20:00:00', $order->completed_at->toDateTimeString());
        $this->assertBalance($merchant, '20.00', '0.00');
        $this->assertSame([['code' => 'T1']], OrderMovie::find($order->id)->ticket_codes);
        $this->assertSame('3.00', OrderMovie::find($order->id)->supplier_rebate);

        $rebate = MerchantRebate::where('order_id', $order->id)->first();
        $this->assertSame('2.40', $rebate->amount);
        $this->assertSame('supplier', $rebate->rebate_base_source);
        $this->assertSame('level', $rebate->rebate_rate_source);
        $this->assertSame('pending', $rebate->status);
        $this->assertSame('2026-09-30 20:00:00', (string) $rebate->due_at, '完成时间 + 默认 7 天');
        $this->assertSame(1, $this->notifications);
    }

    /**
     * 改票根：再来一次出票成功，取票码变了就更新并再回调，但不重复扣款、不重复生成返佣。
     * 返佣晚到：第一次成功没带返佣，之后的查询带来了再生成。
     */
    public function testReissueAndLateRebateAreIdempotent()
    {
        [$merchant, $supplier, $order] = $this->lockedOrder(levelRate: '1.0000');

        $this->service()->apply($order, $this->issued(tickets: [['code' => 'T1']], rebate: null), (int) $supplier->id);
        $this->assertSame(0, MerchantRebate::where('order_id', $order->id)->count(), '还没拿到返佣基数不生成');

        $this->service()->apply($order->refresh(), $this->issued(tickets: [['code' => 'T2']], rebate: '3.00'), (int) $supplier->id);
        $this->service()->apply($order->refresh(), $this->issued(tickets: [['code' => 'T2']], rebate: '3.00'), (int) $supplier->id);

        $this->assertSame([['code' => 'T2']], OrderMovie::find($order->id)->ticket_codes);
        $this->assertSame(1, MerchantRebate::where('order_id', $order->id)->count());
        $this->assertSame(1, MerchantBalanceLog::where('order_id', $order->id)->where('type', 'deduct')->count());
        $this->assertBalance($merchant, '20.00', '0.00');
        $this->assertSame(2, $this->notifications, '出票 1 次 + 改票根 1 次，重复推送不再通知');
    }

    public function testSupplierTimeoutFailsWithLockTimeoutReason()
    {
        [$merchant, $supplier, $order] = $this->lockedOrder();

        $this->service()->apply($order, $this->queried(MangoStatusMapper::STEP_PAY_TIMEOUT), (int) $supplier->id);

        $order->refresh();
        $this->assertSame('failed', $order->status);
        $this->assertSame(ErrorCode::MovieLockTimeout->message(), $order->fail_reason);
        $this->assertBalance($merchant, '100.00', '0.00');
    }

    /**
     * 锁座到期没确认：先结束订单（失败 + 锁座超时）、解冻，再通知芒果释放。已确认的、没到期的不碰。
     * 锁座结果未知（没有芒果单号）的也照样释放——没单号商户确认不了，芒果那边会自己超时。
     */
    public function testExpireLocksReleasesOnlyExpiredUnconfirmedOrders()
    {
        [$merchant, , $expired] = $this->lockedOrder(lockExpireAt: '-2 minutes');
        [, , $confirmed] = $this->lockedOrder(lockExpireAt: '-2 minutes', confirmed: true);
        [, , $fresh] = $this->lockedOrder(lockExpireAt: '+5 minutes');
        [, , $unknown] = $this->lockedOrder(lockExpireAt: '-2 minutes', supplierOrderNo: null);

        $this->driver->shouldReceive('releaseSeats')->once()->with('MG-' . $expired->id)->andReturn(['released' => true, 'message' => '']);

        make(MovieOrderService::class)->expireLocks();

        $this->assertSame('failed', $expired->refresh()->status);
        $this->assertSame(ErrorCode::MovieLockTimeout->message(), $expired->fail_reason);
        $this->assertSame('failed', $unknown->refresh()->status);
        $this->assertSame('processing', $confirmed->refresh()->status);
        $this->assertSame('processing', $fresh->refresh()->status);
        $this->assertBalance($merchant, '100.00', '0.00');
    }

    /**
     * 锁座结果未知的订单（没记下芒果单号）靠查询结果里带回的 attach 认领。
     */
    public function testCallbackClaimsByAuthoritativeAttach()
    {
        [$merchant, $supplier, $order] = $this->lockedOrder(supplierOrderNo: null);
        $this->driver->shouldReceive('parseCallback')->andReturn($this->queried(MangoStatusMapper::STEP_PENDING_PAY, 'MG-LATE', $order->order_no));

        $reply = make(SupplierCallbackService::class)->handle($supplier->code, ['order_number' => 'MG-LATE'], []);

        $this->assertSame(MangoDriver::CALLBACK_REPLY, $reply);
        $this->assertSame('MG-LATE', $order->refresh()->supplier_order_no);
    }

    /**
     * 伪造回调：payload 里写别人的平台单号没用，只认查询结果里的 attach。
     */
    public function testForgedCallbackCannotClaimAnotherOrder()
    {
        [$merchant, $supplier, $victim] = $this->lockedOrder(supplierOrderNo: null);
        $this->driver->shouldReceive('parseCallback')->andReturn($this->queried(MangoStatusMapper::STEP_REFUNDED, 'MG-ATTACKER', 'M-ATTACKER'));

        try {
            make(SupplierCallbackService::class)->handle($supplier->code, ['order_number' => 'MG-ATTACKER', 'attach' => $victim->order_no], []);
            $this->fail('forged callback must not be accepted');
        } catch (CallbackOrderNotFoundException) {
        }

        $this->assertSame('processing', $victim->refresh()->status);
        $this->assertBalance($merchant, '20.00', '80.00');
    }

    public function testCallbackQueryFailureIsNotAcknowledged()
    {
        [, $supplier] = $this->lockedOrder();
        $this->driver->shouldReceive('parseCallback')->andReturn(new DriverResult(result: UnifiedResult::Unknown, failReason: 'timeout'));

        $this->expectException(RuntimeException::class);
        make(SupplierCallbackService::class)->handle($supplier->code, ['order_number' => 'MG-1'], []);
    }

    /**
     * 定时查询：电影票按芒果单号查；没有芒果单号的不进待查列表。
     */
    public function testPollingQueriesByOrderNumber()
    {
        [$merchant, $supplier, $order] = $this->lockedOrder(confirmed: true);
        [, , $unknown] = $this->lockedOrder(supplierOrderNo: null);
        OrderAttempt::whereIn('order_id', [$order->id, $unknown->id])->update(['updated_at' => '2000-01-01 00:00:00']);

        $due = make(OrderAttemptDao::class)->listDueForQuery('2000-01-01 00:00:01', 1000)->pluck('order_id')->all();
        $this->assertContains($order->id, $due);
        $this->assertNotContains($unknown->id, $due);

        $this->driver->shouldReceive('queryOrder')->once()->with('MG-' . $order->id)->andReturn($this->issued([['code' => 'T1']], null));
        make(SupplierResultPollingService::class)->queryLatestAttempt($order);

        $this->assertSame('success', $order->refresh()->status);
    }

    /**
     * 全量同步：城市、区县、影院 upsert；这一轮没拉到的删掉；某个城市查失败不删它的旧数据。
     */
    public function testBaseDataSyncUpsertsAndKeepsFailedCities()
    {
        $supplier = $this->createSupplier();
        MovieCinema::create(['supplier_id' => $supplier->id, 'cinema_id' => 'GONE', 'cinema_name' => '已下线', 'city_id' => 'SZ', 'synced_at' => '2000-01-01 00:00:00']);
        MovieCinema::create(['supplier_id' => $supplier->id, 'cinema_id' => 'KEEP', 'cinema_name' => '北京老影院', 'city_id' => 'BJ', 'synced_at' => '2000-01-01 00:00:00']);

        $this->driver->shouldReceive('queryCities')->andReturn([
            ['city_id' => 'SZ', 'city_name' => '深圳', 'first_letter' => 'S', 'is_hot' => true],
            ['city_id' => 'BJ', 'city_name' => '北京', 'first_letter' => 'B', 'is_hot' => true],
        ]);
        $this->driver->shouldReceive('queryRegions')->with('SZ')->andReturn([['region_id' => 'NS', 'region_name' => '南山区']]);
        $this->driver->shouldReceive('queryRegions')->with('BJ')->andThrow(new RuntimeException('timeout'));
        $this->driver->shouldReceive('queryCinemas')->with('SZ', 1, MangoDriver::MAX_PAGE_SIZE)->andReturn([$this->cinema('C1', '万达'), $this->cinema('C2', '大地')]);

        $stats = make(MovieBaseDataSyncService::class)->syncSupplier($supplier);

        $this->assertSame(['BJ'], $stats['failed_cities']);
        $this->assertSame(2, MovieCity::where('supplier_id', $supplier->id)->count());
        $this->assertSame(['C1', 'C2', 'KEEP'], MovieCinema::where('supplier_id', $supplier->id)->orderBy('cinema_id')->pluck('cinema_id')->all(), 'GONE 删掉，BJ 查失败保留 KEEP');
        $this->assertSame('NS', MovieCinema::where('supplier_id', $supplier->id)->where('cinema_id', 'C1')->value('region_id'));

        // 再同步一次只更新不重复插入
        make(MovieBaseDataSyncService::class)->syncSupplier($supplier);
        $this->assertSame(3, MovieCinema::where('supplier_id', $supplier->id)->count());
    }

    /**
     * 影院更新回调：重拉这家影院所在城市，记下 callback_synced_at。
     */
    public function testCinemaUpdateResyncsItsCity()
    {
        $supplier = $this->createSupplier();
        MovieCinema::create(['supplier_id' => $supplier->id, 'cinema_id' => 'C1', 'cinema_name' => '旧名字', 'city_id' => 'SZ', 'synced_at' => '2026-09-01 00:00:00']);
        $this->driver->shouldReceive('parseCinemaUpdate')->andReturn(['cinema_id' => 'C1', 'cinema_name' => null, 'cinema_code' => null]);
        $this->driver->shouldReceive('queryCinemas')->with('SZ', 1, MangoDriver::MAX_PAGE_SIZE)->andReturn([$this->cinema('C1', '新名字')]);

        make(MovieCallbackService::class)->handleCinemaUpdate($supplier, ['cinemaId' => 'C1']);

        $cinema = MovieCinema::where('supplier_id', $supplier->id)->where('cinema_id', 'C1')->first();
        $this->assertSame('新名字', $cinema->cinema_name);
        $this->assertNotNull($cinema->callback_synced_at);
        $this->assertSame('2026-09-01 00:00:00', (string) $cinema->synced_at, '增量同步不改全量同步时间');
    }

    private function service(): MovieOrderSettlementService
    {
        return make(MovieOrderSettlementService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function cinema(string $id, string $name): array
    {
        return ['cinema_id' => $id, 'cinema_code' => null, 'cinema_name' => $name, 'city_id' => 'SZ', 'region_id' => 'NS',
            'address' => '某路 1 号', 'tel' => null, 'longitude' => '113.9', 'latitude' => '22.5', 'service_info' => null];
    }

    /**
     * @param list<array<string, mixed>> $tickets
     */
    private function issued(array $tickets, ?string $rebate): DriverResult
    {
        return new DriverResult(
            result: UnifiedResult::Success,
            supplierOrderNo: 'MG-x',
            supplierRebate: $rebate,
            movieDetails: ['handle_step' => MangoStatusMapper::STEP_ISSUED, 'tickets' => $tickets, 'lock_expired' => false, 'platform_order_no' => null],
        );
    }

    private function queried(int $handleStep, ?string $orderNumber = 'MG-x', ?string $platformOrderNo = null): DriverResult
    {
        $mapper = new MangoStatusMapper();

        return new DriverResult(
            result: $mapper->map($handleStep),
            supplierOrderNo: $orderNumber,
            movieDetails: ['handle_step' => $handleStep, 'tickets' => [], 'lock_expired' => $mapper->isLockExpired($handleStep), 'platform_order_no' => $platformOrderNo],
        );
    }

    /**
     * 一笔已锁座、已冻结 80 元（2 张 × 40）的电影票订单。
     *
     * @return array{0: Merchant, 1: Supplier, 2: Order}
     */
    private function lockedOrder(?string $levelRate = null, string $lockExpireAt = '+8 minutes', bool $confirmed = false, ?string $supplierOrderNo = 'auto'): array
    {
        $levelId = null;
        if ($levelRate !== null) {
            $level = MerchantLevel::create(['name' => uniqid('金牌', true)]);
            $this->levelIds[] = $levelId = $level->id;
            MerchantLevelBusinessRate::create(['level_id' => $level->id, 'business_line' => 'movie', 'rebate_rate' => $levelRate]);
        }
        $unique = uniqid('movie_settle_', true);
        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'available_balance' => '100.00',
            'frozen_balance' => '0.00',
            'level_id' => $levelId,
        ]);
        $this->merchantIds[] = $merchant->id;
        $supplier = $this->createSupplier();

        $order = Order::create([
            'order_no' => 'M' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . $unique,
            'business_line' => 'movie',
            'status' => 'processing',
            'sale_price' => '80.00',
            'cost_price' => '76.00',
            'frozen_amount' => '80.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
            'supplier_id' => $supplier->id,
        ]);
        $this->orderIds[] = $order->id;
        if ($supplierOrderNo !== null) {
            $order->fill(['supplier_order_no' => 'MG-' . $order->id])->save();
        }
        make(BalanceService::class)->freeze((int) $merchant->id, (int) $order->id, '80.00');

        OrderMovie::create([
            'order_id' => $order->id,
            'cinema_id' => 'C1',
            'film_id' => 'F1',
            'show_id' => 'S#1',
            'show_time' => '2026-09-30 19:30:00',
            'seats' => [['seat_code' => '1-3', 'row' => 1, 'col' => 3, 'area_id' => null, 'love_status' => 0, 'available' => true]],
            'seat_count' => 2,
            'unit_price' => '40.00',
            'unit_cost' => '38.00',
            'mobile' => '13800000000',
            'lock_expire_at' => Carbon::now()->modify($lockExpireAt)->toDateTimeString(),
            'confirmed_at' => $confirmed ? date('Y-m-d H:i:s') : null,
        ]);
        OrderAttempt::create(['order_id' => $order->id, 'supplier_id' => $supplier->id, 'attempt_no' => 1, 'result' => 'processing']);

        return [$merchant, $supplier, $order->refresh()];
    }

    private function createSupplier(): Supplier
    {
        $unique = uniqid('movie_supplier_', true);
        $supplier = Supplier::create([
            'name' => $unique,
            'code' => substr(md5($unique), 0, 24),
            'business_line' => 'movie',
            'driver' => 'mango',
            'config' => 'unused',
            'status' => 'active',
        ]);
        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }

    private function assertBalance(Merchant $merchant, string $available, string $frozen): void
    {
        $fresh = Merchant::find($merchant->id);
        $this->assertSame([$available, $frozen], [$fresh->available_balance, $fresh->frozen_balance]);
    }
}
