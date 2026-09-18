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

use Hyperf\Contract\ConfigInterface;
use Hyperf\Logger\LoggerFactory;

/**
 * 没配置阿里云短信时的占位实现：开发/测试环境把验证码写进日志（runtime/logs，渠道 sms），
 * 联调时从日志里取；生产环境不写验证码，只记一条告警提醒去配置短信。
 */
class LogSmsSender implements SmsSender
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly LoggerFactory $loggerFactory,
    ) {
    }

    public function sendVerificationCode(string $phone, string $code): bool
    {
        $logger = $this->loggerFactory->get('sms');

        if ($this->config->get('app_env') === 'prod') {
            $logger->warning('sms not sent: ALIYUN_SMS_* is not configured');

            return false;
        }

        $logger->info("[dev] verification code for {$phone}: {$code}");

        return true;
    }
}
