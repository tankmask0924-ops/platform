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

/**
 * 腾讯云 COS 请求签名（官方文档「请求签名」，q-sign-algorithm=sha1）：.
 *
 *   KeyTime      = start;end
 *   SignKey      = HMAC-SHA1(SecretKey, KeyTime)
 *   HttpString   = lower(Method) \n UriPath \n HttpParameters \n HttpHeaders \n
 *   StringToSign = sha1 \n KeyTime \n SHA1(HttpString) \n
 *   Signature    = HMAC-SHA1(SignKey, StringToSign)
 *
 * HttpHeaders 是参与签名的请求头：键小写、键值都 URL 编码、按键排序、`key=value` 用 & 连接。
 * 这里没有查询参数（HttpParameters、UrlParamList 都是空串）。
 */
class TencentCosSigner
{
    /**
     * @param array<string, string> $headers 参与签名的请求头（至少要有 host）
     */
    public function authorization(
        string $method,
        string $uriPath,
        array $headers,
        string $secretId,
        string $secretKey,
        int $startTime,
        int $endTime
    ): string {
        $keyTime = $startTime . ';' . $endTime;
        [$headerList, $httpHeaders] = $this->canonicalHeaders($headers);

        $signature = $this->signature($method, $uriPath, $httpHeaders, $secretKey, $keyTime);

        return 'q-sign-algorithm=sha1'
            . '&q-ak=' . $secretId
            . '&q-sign-time=' . $keyTime
            . '&q-key-time=' . $keyTime
            . '&q-header-list=' . $headerList
            . '&q-url-param-list='
            . '&q-signature=' . $signature;
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: string, 1: string} [HeaderList, HttpHeaders]
     */
    public function canonicalHeaders(array $headers): array
    {
        $encoded = [];
        foreach ($headers as $name => $value) {
            $encoded[rawurlencode(strtolower($name))] = rawurlencode(trim($value));
        }
        ksort($encoded);

        $pairs = [];
        foreach ($encoded as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return [implode(';', array_keys($encoded)), implode('&', $pairs)];
    }

    public function signature(string $method, string $uriPath, string $httpHeaders, string $secretKey, string $keyTime): string
    {
        $httpString = strtolower($method) . "\n" . $uriPath . "\n\n" . $httpHeaders . "\n";
        $stringToSign = "sha1\n" . $keyTime . "\n" . sha1($httpString) . "\n";

        return hash_hmac('sha1', $stringToSign, hash_hmac('sha1', $keyTime, $secretKey));
    }
}
