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

namespace App\Process;

use Hyperf\Crontab\Process\CrontabDispatcherProcess as BaseCrontabDispatcherProcess;
use Hyperf\Process\Annotation\Process;

#[Process(name: 'crontab-dispatcher')]
class CrontabDispatcherProcess extends BaseCrontabDispatcherProcess
{
}
