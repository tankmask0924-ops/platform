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
use Hyperf\ModelCache\Handler\RedisStringHandler;

use function Hyperf\Support\env;

return [
    'default' => [
        'driver' => env('DB_DRIVER', 'mysql'),
        'host' => env('DB_HOST', 'localhost'),
        'database' => env('DB_DATABASE', 'hyperf'),
        'port' => env('DB_PORT', 3306),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8'),
        'collation' => env('DB_COLLATION', 'utf8_unicode_ci'),
        'prefix' => env('DB_PREFIX', ''),
        'options' => [
            // 框架默认用原生预处理：每条 SQL 要走 prepare/execute/close 三个包，在 Swoole 协程下
            // close 之后紧跟的下一条 SQL 会卡约 50ms（实测 Db::select 约 59ms，改成模拟预处理后约 7ms）。
            // 模拟预处理只走一个包；PHP 8.1+ 的 mysqlnd 在模拟模式下 int/float 仍返回原生类型，参数照样转义。
            PDO::ATTR_EMULATE_PREPARES => true,
        ],
        'pool' => [
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => (float) env('DB_MAX_IDLE_TIME', 60),
        ],
        'commands' => [
            'gen:model' => [
                'path' => 'app/Model',
                'force_casts' => true,
                'inheritance' => 'Model',
            ],
        ],
        'cache' => [
            // 不用默认的 RedisHandler：它按 Redis hash 存模型，NULL 字段读回来会变成 ''，
            // 例如 merchants.app_key 为 NULL 时被当成"已生成"。RedisStringHandler 整行序列化，能保留 NULL。
            // 代价是不支持缓存层的 increment（项目里没用到）。
            'handler' => RedisStringHandler::class,
            // 换 handler 时顺带换前缀：旧的 mc:default:* 是 hash，用 GET 读会报 WRONGTYPE，换前缀后旧 key 自然过期
            'prefix' => 'str',
            'ttl' => 3600 * 24,
            'empty_model_ttl' => 600,
            'load_script' => true,
            'use_default_value' => false,
        ],
    ],
];
