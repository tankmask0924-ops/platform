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

namespace App\Notify;

/**
 * 发送验证码（找回密码用）。$channel 为 sms 时 $to 是手机号，为 email 时是邮箱。
 * 目前绑定的是 LogVerificationCodeSender（只写日志），接入短信/邮件服务商时换一个实现，
 * 在 config/autoload/dependencies.php 里改绑定即可。
 */
interface VerificationCodeSender
{
    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_EMAIL = 'email';

    public function send(string $channel, string $to, string $code): void;
}
