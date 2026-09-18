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

use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;

/**
 * 还没接短信/邮件服务商时的占位实现：开发/测试环境把验证码写进日志
 * （runtime/logs，渠道 verification_code），联调时从日志里取；
 * 生产环境不写验证码本身，只记一条告警，提醒还没有配置真正的发送渠道。
 */
class LogVerificationCodeSender implements VerificationCodeSender
{
    #[Inject]
    protected LoggerFactory $loggerFactory;

    #[Inject]
    protected ConfigInterface $config;

    public function send(string $channel, string $to, string $code): void
    {
        $logger = $this->loggerFactory->get('verification_code');

        if ($this->config->get('app_env') === 'prod') {
            $logger->warning("verification code for {$channel} not sent: no sender configured");

            return;
        }

        $logger->info("[{$channel}] {$to} verification code: {$code}");
    }
}
