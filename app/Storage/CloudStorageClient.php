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

namespace App\Storage;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\Context\ApplicationContext;
use Hyperf\Guzzle\ClientFactory;
use RuntimeException;

/**
 * 往阿里云 OSS / 腾讯云 COS 上传、删除对象。不依赖两家的 SDK：两家都只用到 PutObject / DeleteObject，
 * 签名分别在 AliyunOssSigner / TencentCosSigner 里，走 Hyperf 的 Guzzle（协程友好）。
 *
 * 对象 key 只由平台生成（`前缀/images/日期/随机串.扩展名`，全是 ASCII），不接受外部传入，
 * 所以签名里的路径不涉及中文编码问题。
 *
 * 【测试方式】跟 KasushouDriver 一样：httpClient() 是 protected 方法，测试用匿名子类换成 Mockery 双重。
 */
class CloudStorageClient
{
    private readonly AliyunOssSigner $ossSigner;

    private readonly TencentCosSigner $cosSigner;

    public function __construct(private readonly StorageConfig $config)
    {
        $this->ossSigner = new AliyunOssSigner();
        $this->cosSigner = new TencentCosSigner();
    }

    /**
     * 上传对象，成功返回公开访问地址；失败抛 RuntimeException（带云存储返回的错误码，不含密钥）。
     */
    public function put(string $key, string $body, string $contentType): string
    {
        $this->send('PUT', $key, ['Content-Type' => $contentType], $body);

        return $this->config->publicUrl($key);
    }

    public function delete(string $key): void
    {
        $this->send('DELETE', $key, [], '');
    }

    protected function httpClient(): ClientInterface
    {
        return ApplicationContext::getContainer()->get(ClientFactory::class)->create();
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(string $method, string $key, array $headers, string $body): void
    {
        $host = $this->config->host();
        $headers = $this->signedHeaders($method, $key, $host, $headers);

        try {
            $response = $this->httpClient()->request($method, 'https://' . $host . '/' . $key, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => 30,
                'connect_timeout' => 5,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('连接云存储失败：' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        // 两家的错误响应都是 XML：<Code>…</Code><Message>…</Message>
        $xml = (string) $response->getBody();
        preg_match('#<Code>([^<]*)</Code>#', $xml, $code);
        preg_match('#<Message>([^<]*)</Message>#', $xml, $message);
        throw new RuntimeException(sprintf(
            '云存储返回 HTTP %d%s%s',
            $status,
            isset($code[1]) ? '，' . $code[1] : '',
            isset($message[1]) ? '：' . $message[1] : ''
        ));
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function signedHeaders(string $method, string $key, string $host, array $headers): array
    {
        if ($this->config->driver === StorageConfig::DRIVER_ALIYUN_OSS) {
            return $this->ossSigner->sign(
                $method,
                $this->config->bucket,
                $key,
                $this->config->region,
                $headers,
                $this->config->accessKeyId,
                $this->config->accessKeySecret
            ) + ['Host' => $host];
        }

        $signed = ['host' => $host];
        foreach ($headers as $name => $value) {
            $signed[strtolower($name)] = $value;
        }
        $now = time();
        // 签名 10 分钟内有效，往前留 60 秒防服务器时钟偏差
        $headers['Authorization'] = $this->cosSigner->authorization(
            $method,
            '/' . $key,
            $signed,
            $this->config->accessKeyId,
            $this->config->accessKeySecret,
            $now - 60,
            $now + 600
        );

        return $headers + ['Host' => $host];
    }
}
