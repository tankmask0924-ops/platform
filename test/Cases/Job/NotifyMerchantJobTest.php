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

namespace HyperfTest\Cases\Job;

use App\Crypto\Encryptor;
use App\Dao\MerchantNotifyLogDao;
use App\Job\NotifyMerchantJob;
use App\Model\Merchant;
use App\Model\MerchantNotifyLog;
use App\Model\Order;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Testing\TestCase;
use Mockery;
use ReflectionProperty;

/**
 * NotifyMerchantJob 直接 new + ->handle() 跑（不走真实 async-queue 消费者，也不真的
 * 等重试延迟），覆盖 requirements.md 7.6 的关键分支：
 *   - 商户返回 success -> 记成功日志，不安排下一次重试
 *   - 商户返回非 success -> 记失败日志，按 attempt_no 对应的延迟安排下一次重试
 *   - 连接异常（超时/连不上）-> http_status/response_body 记 null，仍然安排重试
 *   - 已经是第 7 次尝试还失败 -> 记日志，不再安排任何重试
 *   - 回调 URL 被 CallbackUrlGuard 拒绝 -> 记失败日志，压根不发起 HTTP 请求，也不重试.
 *
 * HTTP 客户端通过重写 App\Job\NotifyMerchantJob::httpClient() 的匿名子类替换成
 * Mockery 的 GuzzleHttp\ClientInterface 双重，异步队列驱动通过
 * Hyperf\Testing\TestCase 自带的容器 swap（$this->instance()）把
 * Hyperf\AsyncQueue\Driver\DriverFactory 换成 Mockery 双重，两边都不会真的发起
 * 网络请求或真的推送到 Redis。
 *
 * @internal
 * @coversNothing
 */
class NotifyMerchantJobTest extends TestCase
{
    private array $merchantIds = [];

    private array $orderIds = [];

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $id) {
            MerchantNotifyLog::where('order_id', $id)->delete();
            Order::destroy($id);
        }
        $this->orderIds = [];

        foreach ($this->merchantIds as $id) {
            Merchant::destroy($id);
        }
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testSuccessResponseLogsSuccessAndSchedulesNoRetry()
    {
        $merchant = $this->createMerchant('plain-secret-' . uniqid('', true));
        $order = $this->createOrder($merchant->id, 'http://198.51.100.10/notify');

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->with('POST', $order->callback_url, Mockery::type('array'))
            ->andReturn(new Response(200, [], ' SUCCESS '));

        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->shouldNotReceive('get');
        $this->instance(DriverFactory::class, $driverFactory);

        $job = $this->makeJob($order->id, 1, $client);
        $job->handle();

        $log = $this->latestLog($order->id);
        $this->assertNotNull($log);
        $this->assertTrue($log->success);
        $this->assertSame(200, $log->http_status);
        $this->assertSame(' SUCCESS ', $log->response_body);
        $this->assertSame(1, $log->attempt_no);
    }

    public function testNonSuccessResponseLogsFailureAndSchedulesNextRetryWithCorrectDelay()
    {
        $merchant = $this->createMerchant('plain-secret-' . uniqid('', true));
        $order = $this->createOrder($merchant->id, 'http://198.51.100.11/notify');

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], 'fail'));

        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('push')
            ->once()
            ->with(
                Mockery::on(function (NotifyMerchantJob $job) use ($order) {
                    return $this->jobOrderId($job) === $order->id && $this->jobAttemptNo($job) === 3;
                }),
                300 // attempt_no 3 -> 5 分钟
            )
            ->andReturnTrue();

        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->shouldReceive('get')->once()->with('default')->andReturn($driver);
        $this->instance(DriverFactory::class, $driverFactory);

        $job = $this->makeJob($order->id, 2, $client);
        $job->handle();

        $log = $this->latestLog($order->id);
        $this->assertNotNull($log);
        $this->assertFalse($log->success);
        $this->assertSame(200, $log->http_status);
        $this->assertSame('fail', $log->response_body);
        $this->assertSame(2, $log->attempt_no);
    }

    public function testConnectionExceptionLogsNullStatusAndBodyButStillSchedulesRetry()
    {
        $merchant = $this->createMerchant('plain-secret-' . uniqid('', true));
        $order = $this->createOrder($merchant->id, 'http://198.51.100.12/notify');

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andThrow(new ConnectException('connection timed out', new Request('POST', $order->callback_url)));

        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('push')
            ->once()
            ->with(Mockery::type(NotifyMerchantJob::class), 60) // attempt_no 2 -> 1 分钟
            ->andReturnTrue();

        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->shouldReceive('get')->once()->with('default')->andReturn($driver);
        $this->instance(DriverFactory::class, $driverFactory);

        $job = $this->makeJob($order->id, 1, $client);
        $job->handle();

        $log = $this->latestLog($order->id);
        $this->assertNotNull($log);
        $this->assertFalse($log->success);
        $this->assertNull($log->http_status);
        $this->assertNull($log->response_body);
        $this->assertSame(1, $log->attempt_no);
    }

    public function testAttemptSevenFailureDoesNotScheduleFurtherRetry()
    {
        $merchant = $this->createMerchant('plain-secret-' . uniqid('', true));
        $order = $this->createOrder($merchant->id, 'http://198.51.100.13/notify');

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(new Response(500, [], 'still failing'));

        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->shouldNotReceive('get');
        $this->instance(DriverFactory::class, $driverFactory);

        $job = $this->makeJob($order->id, 7, $client);
        $job->handle();

        $log = $this->latestLog($order->id);
        $this->assertNotNull($log);
        $this->assertFalse($log->success);
        $this->assertSame(7, $log->attempt_no);
    }

    public function testRejectedCallbackUrlIsLoggedAsFailureWithoutHttpCallOrRetry()
    {
        $merchant = $this->createMerchant('plain-secret-' . uniqid('', true));
        // 私网地址，CallbackUrlGuard 必须拒绝。
        $order = $this->createOrder($merchant->id, 'http://10.0.0.5/notify');

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldNotReceive('request');

        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->shouldNotReceive('get');
        $this->instance(DriverFactory::class, $driverFactory);

        $job = $this->makeJob($order->id, 1, $client);
        $job->handle();

        $log = $this->latestLog($order->id);
        $this->assertNotNull($log);
        $this->assertFalse($log->success);
        $this->assertNull($log->http_status);
        $this->assertNull($log->response_body);
        $this->assertSame(1, $log->attempt_no);
    }

    private function makeJob(int $orderId, int $attemptNo, ClientInterface $client): NotifyMerchantJob
    {
        return new class($orderId, $attemptNo, $client) extends NotifyMerchantJob {
            private ClientInterface $stubClient;

            public function __construct(int $orderId, int $attemptNo, ClientInterface $client)
            {
                parent::__construct($orderId, $attemptNo);
                $this->stubClient = $client;
            }

            protected function httpClient(): ClientInterface
            {
                return $this->stubClient;
            }
        };
    }

    private function jobOrderId(NotifyMerchantJob $job): int
    {
        $property = new ReflectionProperty($job, 'orderId');
        $property->setAccessible(true);

        return $property->getValue($job);
    }

    private function jobAttemptNo(NotifyMerchantJob $job): int
    {
        $property = new ReflectionProperty($job, 'attemptNo');
        $property->setAccessible(true);

        return $property->getValue($job);
    }

    private function latestLog(int $orderId): ?MerchantNotifyLog
    {
        return $this->getContainer()->get(MerchantNotifyLogDao::class)
            ->findByOrderId($orderId)
            ->first();
    }

    private function createMerchant(string $plainSecret): Merchant
    {
        $unique = uniqid('notify_job_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => (new Encryptor())->encrypt($plainSecret),
            'available_balance' => '0.00',
            'frozen_balance' => '0.00',
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createOrder(int $merchantId, string $callbackUrl): Order
    {
        $unique = uniqid('', true);

        $order = Order::create([
            'order_no' => 'PF' . $unique,
            'merchant_id' => $merchantId,
            'merchant_order_no' => 'MO' . $unique,
            'business_line' => 'recharge',
            'status' => 'success',
            'sale_price' => '10.00',
            'cost_price' => '8.00',
            'frozen_amount' => '10.00',
            'deducted_amount' => '10.00',
            'refunded_amount' => '0.00',
            'callback_url' => $callbackUrl,
            'completed_at' => '2026-09-14 10:00:00',
        ]);

        $this->orderIds[] = $order->id;

        return $order;
    }
}
