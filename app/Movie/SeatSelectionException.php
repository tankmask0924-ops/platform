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

namespace App\Movie;

use RuntimeException;

/**
 * 选座不合法（App\Movie\SeatSelectionValidator）。消息是给商户看的平台文案，
 * 锁座接口接上时直接转成参数错误返回。
 */
class SeatSelectionException extends RuntimeException
{
}
