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

use DateTimeImmutable;
use DateTimeZone;

/**
 * 阿里云 OSS V4 签名（在 Header 中包含签名，OSS4-HMAC-SHA256）。V1 签名阿里云已在推动下线，
 * 这里只实现 V4。算法按官方文档「在Header中包含V4签名」：.
 *
 *   CanonicalRequest = Verb \n UriEncode(/bucket/key) \n CanonicalQuery \n CanonicalHeaders \n AdditionalHeaders \n UNSIGNED-PAYLOAD
 *   StringToSign     = "OSS4-HMAC-SHA256" \n TimeStamp \n Date/Region/oss/aliyun_v4_request \n hex(sha256(CanonicalRequest))
 *   SigningKey       = HMAC-SHA256(HMAC-SHA256(HMAC-SHA256(HMAC-SHA256("aliyun_v4" + SK, Date), Region), "oss"), "aliyun_v4_request")
 *   Signature        = hex(HMAC-SHA256(SigningKey, StringToSign))
 *
 * 参与签名的请求头：content-type、content-md5（有则签）和全部 x-oss-*；不额外签 AdditionalHeaders。
 */
class AliyunOssSigner
{
    /**
     * 给一次请求补上 x-oss-date、x-oss-content-sha256、Authorization 三个头，返回完整的请求头。
     *
     * @param array<string, string> $headers 已有请求头（如 Content-Type）
     * @return array<string, string>
     */
    public function sign(
        string $method,
        string $bucket,
        string $key,
        string $region,
        array $headers,
        string $accessKeyId,
        string $accessKeySecret,
        ?DateTimeImmutable $now = null
    ): array {
        $now = ($now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
        $timestamp = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');

        $headers['x-oss-date'] = $timestamp;
        $headers['x-oss-content-sha256'] = 'UNSIGNED-PAYLOAD';

        $canonicalRequest = $this->canonicalRequest($method, '/' . $bucket . '/' . $key, $headers);
        $scope = $date . '/' . $region . '/oss/aliyun_v4_request';
        $stringToSign = "OSS4-HMAC-SHA256\n" . $timestamp . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

        $signingKey = hash_hmac('sha256', 'aliyun_v4_request', hash_hmac('sha256', 'oss', hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'aliyun_v4' . $accessKeySecret, true), true), true), true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['Authorization'] = 'OSS4-HMAC-SHA256 Credential=' . $accessKeyId . '/' . $scope . ', Signature=' . $signature;

        return $headers;
    }

    /**
     * 规范化请求（没有查询参数、没有 AdditionalHeaders），单独公开出来方便对照文档示例测试。
     *
     * @param array<string, string> $headers
     */
    public function canonicalRequest(string $method, string $resourcePath, array $headers, string $additionalHeaders = ''): string
    {
        $canonical = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            if ($lower === 'content-type' || $lower === 'content-md5' || str_starts_with($lower, 'x-oss-')
                || in_array($lower, explode(';', $additionalHeaders), true)) {
                $canonical[$lower] = trim($value);
            }
        }
        ksort($canonical);

        $lines = '';
        foreach ($canonical as $name => $value) {
            $lines .= $name . ':' . $value . "\n";
        }

        return strtoupper($method) . "\n"
            . str_replace('%2F', '/', rawurlencode($resourcePath)) . "\n"
            . "\n"
            . $lines . "\n"
            . $additionalHeaders . "\n"
            . 'UNSIGNED-PAYLOAD';
    }
}
