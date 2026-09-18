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
use App\Notify\Sms\SmsSender;
use App\Notify\Sms\SmsSenderFactory;

return [
    // 配了 ALIYUN_SMS_* 走阿里云短信，否则只写日志
    SmsSender::class => SmsSenderFactory::class,
];
