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

namespace App\Supplier\Mango;

use RuntimeException;

/**
 * 芒果的限流响应（mango.md 第 1 节：批量拉取影院 300 次/分钟、批量拉取场次 1200 次/分钟，
 * 超限返回 `{"message": "Requests rate limited. stage:trafficcontrol"}`）。单独一个异常类型，
 * 让批量同步任务能区分"该歇一会儿再拉"和"接口真坏了"。
 */
class MangoRateLimitedException extends RuntimeException
{
}
