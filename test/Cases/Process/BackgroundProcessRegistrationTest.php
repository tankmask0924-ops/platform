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

namespace HyperfTest\Cases\Process;

use App\Crontab\AbnormalOrderCrontab;
use App\Crontab\RebateSettlementCrontab;
use App\Crontab\SupplierBalanceCrontab;
use App\Crontab\SupplierProductSyncCrontab;
use App\Crontab\SupplierResultQueryCrontab;
use App\Process\CrontabDispatcherProcess;
use App\Process\QueueConsumerProcess;
use Hyperf\AsyncQueue\Process\ConsumerProcess;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Crontab\Process\CrontabDispatcherProcess as BaseCrontabDispatcherProcess;
use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\Process\Annotation\Process;
use Hyperf\Testing\TestCase;

/**
 * 队列消费进程和定时任务调度进程（docs/modules.md 第 1 节）：这两个进程少注册一个，
 * 框架不会报任何错，只是队列任务和定时任务静默地永远不执行，所以用测试把注册关系钉住。
 *
 * @internal
 * @coversNothing
 */
class BackgroundProcessRegistrationTest extends TestCase
{
    public function testQueueConsumerProcessIsRegistered()
    {
        $this->assertTrue(is_subclass_of(QueueConsumerProcess::class, ConsumerProcess::class));
        $this->assertSame('async-queue', $this->processAnnotation(QueueConsumerProcess::class)->name);
    }

    public function testCrontabDispatcherProcessIsRegisteredAndCrontabEnabled()
    {
        $this->assertTrue(is_subclass_of(CrontabDispatcherProcess::class, BaseCrontabDispatcherProcess::class));
        $this->assertSame('crontab-dispatcher', $this->processAnnotation(CrontabDispatcherProcess::class)->name);
        $this->assertTrue($this->config()->get('crontab.enable'));
    }

    /**
     * 多实例部署时返佣入账只能有一台机器执行，靠 onOneServer 的 Redis 互斥。
     */
    public function testRebateSettlementRunsOnOneServerOnly()
    {
        $annotation = AnnotationCollector::getClassesByAnnotation(Crontab::class)[RebateSettlementCrontab::class] ?? null;

        $this->assertInstanceOf(Crontab::class, $annotation);
        $this->assertSame('RebateSettlement', $annotation->name);
        $this->assertTrue($annotation->onOneServer);
    }

    /**
     * 供应商结果查询每分钟一次，只在一台机器上跑，上一批没跑完不叠加。
     */
    public function testSupplierResultQueryIsScheduledEveryMinuteOnOneServer()
    {
        $annotation = AnnotationCollector::getClassesByAnnotation(Crontab::class)[SupplierResultQueryCrontab::class] ?? null;

        $this->assertInstanceOf(Crontab::class, $annotation);
        $this->assertSame('* * * * *', $annotation->rule);
        $this->assertTrue($annotation->onOneServer);
        $this->assertTrue($annotation->singleton);
        $this->assertGreaterThanOrEqual(300, $annotation->mutexExpires, '锁不能比一批查询的最坏耗时先过期');
    }

    public function testSupplierProductFullSyncRunsDailyOnOneServer()
    {
        $annotation = AnnotationCollector::getClassesByAnnotation(Crontab::class)[SupplierProductSyncCrontab::class] ?? null;

        $this->assertInstanceOf(Crontab::class, $annotation);
        $this->assertSame('0 4 * * *', $annotation->rule);
        $this->assertTrue($annotation->onOneServer);
        $this->assertTrue($annotation->singleton);
        $this->assertGreaterThanOrEqual(3600, $annotation->mutexExpires);
    }

    public function testAbnormalOrderMarkingRunsOnOneServer()
    {
        $annotation = AnnotationCollector::getClassesByAnnotation(Crontab::class)[AbnormalOrderCrontab::class] ?? null;

        $this->assertInstanceOf(Crontab::class, $annotation);
        $this->assertSame('*/5 * * * *', $annotation->rule);
        $this->assertTrue($annotation->onOneServer);
    }

    public function testSupplierBalanceRefreshRunsOnOneServer()
    {
        $annotation = AnnotationCollector::getClassesByAnnotation(Crontab::class)[SupplierBalanceCrontab::class] ?? null;

        $this->assertInstanceOf(Crontab::class, $annotation);
        $this->assertSame('*/5 * * * *', $annotation->rule);
        $this->assertTrue($annotation->onOneServer);
    }

    /**
     * Redis 读超时为 0（框架默认）时，一次网络中断会让调度进程和消费进程卡到内核
     * TCP keepalive 超时（7200 秒）。读超时又必须大于队列 brPop 的等待时间，
     * 否则空队列的正常等待会被当成读超时。
     */
    public function testRedisTimeoutsAreBoundedAndLongerThanQueueBlockingPop()
    {
        $config = $this->config();
        $readTimeout = (float) $config->get('redis.default.read_timeout', 0);
        $connectTimeout = (float) $config->get('redis.default.timeout', 0);
        $queuePopTimeout = (float) $config->get('async_queue.default.timeout', 5);

        $this->assertGreaterThan(0, $connectTimeout);
        $this->assertGreaterThan($queuePopTimeout, $readTimeout);
    }

    private function processAnnotation(string $class): Process
    {
        $annotation = AnnotationCollector::getClassesByAnnotation(Process::class)[$class] ?? null;
        $this->assertInstanceOf(Process::class, $annotation, $class . ' is missing #[Process]');

        return $annotation;
    }

    private function config(): ConfigInterface
    {
        return $this->getContainer()->get(ConfigInterface::class);
    }
}
