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

namespace HyperfTest;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\DbConnection\Pool\PoolFactory as DbPoolFactory;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Redis\Pool\PoolFactory as RedisPoolFactory;

/**
 * 需要往容器里换 mock 的测试在 setUp() 里重建容器（hyperf-conventions 第 7 节），用这个 trait 的
 * rebuildContainer()，不要直接 `new Container(...)`。
 *
 * 【为什么】每个新容器都有自己的数据库、Redis 连接池（每个池最多 10 个连接），旧容器被 PHPUnit 一直
 * 留着的测试对象引用着，池里的连接永远不关。重建容器的用例一多，全量测试跑到后半段 MySQL 就报
 * `1040 Too many connections`（默认上限 151），后面的用例随机失败、单独跑又都能过。这里换容器之前先
 * 把旧容器池里空闲的连接全部关掉；池本身还在，旧容器以后要用会按需重新连。
 */
trait RebuildsContainer
{
    protected function rebuildContainer(): void
    {
        self::releaseContainerConnections();

        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
    }

    /**
     * 关掉当前容器里数据库、Redis 连接池的空闲连接。
     */
    protected static function releaseContainerConnections(): void
    {
        if (! ApplicationContext::hasContainer()) {
            return;
        }

        $container = ApplicationContext::getContainer();
        foreach ([DbPoolFactory::class, RedisPoolFactory::class] as $factory) {
            if ($container->has($factory)) {
                $container->get($factory)->flushAll();
            }
        }
    }
}
