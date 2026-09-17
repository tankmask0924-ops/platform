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
use function Hyperf\Support\env;

return [
    'default' => [
        'host' => env('REDIS_HOST', 'localhost'),
        'auth' => env('REDIS_AUTH', null),
        'port' => (int) env('REDIS_PORT', 6379),
        'db' => (int) env('REDIS_DB', 0),
        // 框架默认两个超时都是 0（永不超时）：跟 Redis 之间的网络一断，读操作会一直阻塞到
        // 内核 TCP keepalive 判定连接死亡（容器里是 7200 秒），期间定时任务调度进程、
        // 队列消费进程全部卡住。读超时必须大于 async_queue 里 brPop 的等待时间（timeout 2 秒）。
        'timeout' => (float) env('REDIS_TIMEOUT', 3.0),
        'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 5.0),
        'pool' => [
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => (float) env('REDIS_MAX_IDLE_TIME', 60),
        ],
    ],
];
