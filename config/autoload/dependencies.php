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
use App\Notify\LogVerificationCodeSender;
use App\Notify\VerificationCodeSender;

return [
    // 接入短信/邮件服务商后换成真正的实现
    VerificationCodeSender::class => LogVerificationCodeSender::class,
];
