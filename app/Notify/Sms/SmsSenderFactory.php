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
use Hyperf\Guzzle\ClientFactory;
use Hyperf\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;

use function Hyperf\Support\env;

/**
 * .env 里 ALIYUN_SMS_* 四项都配了就用阿里云，否则用只写日志的 LogSmsSender。
 */
class SmsSenderFactory
{
    public function __invoke(ContainerInterface $container): SmsSender
    {
        $config = [
            'access_key_id' => (string) env('ALIYUN_SMS_ACCESS_KEY_ID', ''),
            'access_key_secret' => (string) env('ALIYUN_SMS_ACCESS_KEY_SECRET', ''),
            'sign_name' => (string) env('ALIYUN_SMS_SIGN_NAME', ''),
            'template_code' => (string) env('ALIYUN_SMS_VERIFY_TEMPLATE_CODE', ''),
        ];

        if (in_array('', $config, true)) {
            return new LogSmsSender($container->get(ConfigInterface::class), $container->get(LoggerFactory::class));
        }

        return new AliyunSmsSender(
            $config['access_key_id'],
            $config['access_key_secret'],
            $config['sign_name'],
            $config['template_code'],
            $container->get(ClientFactory::class),
            $container->get(LoggerFactory::class),
        );
    }
}
