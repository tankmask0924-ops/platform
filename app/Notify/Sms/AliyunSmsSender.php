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

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\Guzzle\ClientFactory;
use Hyperf\Logger\LoggerFactory;

/**
 * 阿里云短信服务（Dysmsapi）SendSms，直接调 RPC 接口，没有引入阿里云 SDK。
 *
 * 签名是阿里云 RPC 风格的签名 V1（HMAC-SHA1），见 sign()；
 * 验证码模板里的变量名固定为 code（在阿里云控制台申请模板时写成"您的验证码为 ${code}…"）。
 * 日志只记手机号后四位和阿里云返回的错误码，不记验证码。
 */
class AliyunSmsSender implements SmsSender
{
    private const ENDPOINT = 'https://dysmsapi.aliyuncs.com/';

    public function __construct(
        private readonly string $accessKeyId,
        private readonly string $accessKeySecret,
        private readonly string $signName,
        private readonly string $templateCode,
        private readonly ClientFactory $clientFactory,
        private readonly LoggerFactory $loggerFactory,
    ) {
    }

    public function sendVerificationCode(string $phone, string $code): bool
    {
        $params = $this->sign([
            'AccessKeyId' => $this->accessKeyId,
            'Action' => 'SendSms',
            'Format' => 'JSON',
            'RegionId' => 'cn-hangzhou',
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => bin2hex(random_bytes(16)),
            'SignatureVersion' => '1.0',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'Version' => '2017-05-25',
            'PhoneNumbers' => $phone,
            'SignName' => $this->signName,
            'TemplateCode' => $this->templateCode,
            'TemplateParam' => json_encode(['code' => $code]),
        ], $this->accessKeySecret);

        $logger = $this->loggerFactory->get('sms');
        $masked = '***' . substr($phone, -4);
        try {
            $response = $this->httpClient()->request('GET', self::ENDPOINT, [
                'query' => $params,
                'timeout' => 5,
                'connect_timeout' => 3,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            $logger->error("aliyun sms to {$masked} failed: {$e->getMessage()}");

            return false;
        }

        $body = json_decode((string) $response->getBody(), true);
        if (($body['Code'] ?? null) !== 'OK') {
            $logger->error(sprintf(
                'aliyun sms to %s failed: http %d, %s %s (RequestId %s)',
                $masked,
                $response->getStatusCode(),
                $body['Code'] ?? '-',
                $body['Message'] ?? '-',
                $body['RequestId'] ?? '-'
            ));

            return false;
        }

        return true;
    }

    /**
     * 阿里云 RPC 签名 V1：参数按名字排序、按 RFC3986 编码后拼成 StringToSign，
     * 用 "AccessKeySecret&" 做 HMAC-SHA1，结果 base64 后作为 Signature 参数。
     *
     * @param array<string, string> $params
     * @return array<string, string> 加上 Signature 之后的全部参数
     */
    public static function sign(array $params, string $accessKeySecret): array
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = self::percentEncode($key) . '=' . self::percentEncode($value);
        }
        $stringToSign = 'GET&%2F&' . self::percentEncode(implode('&', $pairs));
        $params['Signature'] = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));

        return $params;
    }

    protected function httpClient(): ClientInterface
    {
        return $this->clientFactory->create();
    }

    private static function percentEncode(string $value): string
    {
        return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], rawurlencode($value));
    }
}
