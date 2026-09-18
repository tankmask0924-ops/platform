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

namespace App\Notify\Sms;

/**
 * 发短信。绑定哪个实现见 SmsSenderFactory：配置了阿里云短信就走阿里云，否则只写日志（开发环境）。
 */
interface SmsSender
{
    /**
     * 发验证码短信，返回是否发送成功。失败原因由实现自己记日志，不往外抛，
     * 调用方（找回密码）不能因为发送失败而暴露账号是否存在。
     */
    public function sendVerificationCode(string $phone, string $code): bool;
}
