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
 * 云存储配置（系统设置 - 云存储，管理员在后台填写；密钥解密后才会出现在这里，不落日志、不回给前端）。
 *
 * - 阿里云 OSS：bucket 是存储空间名，region 是地域 ID（如 cn-hangzhou），访问域名 {bucket}.oss-{region}.aliyuncs.com；
 * - 腾讯云 COS：bucket 是「名称-APPID」（如 images-1250000000），region 如 ap-guangzhou，访问域名 {bucket}.cos.{region}.myqcloud.com。
 *
 * domain 是图片对外访问的域名（绑定的自定义域名或 CDN，含 https://），不填就用上面的默认域名。
 * 图片要能被商户直接打开，存储空间需要设成公共读（或在 CDN 上回源鉴权）。
 */
final class StorageConfig
{
    public const DRIVER_ALIYUN_OSS = 'aliyun_oss';

    public const DRIVER_TENCENT_COS = 'tencent_cos';

    public function __construct(
        public readonly string $driver,
        public readonly string $bucket,
        public readonly string $region,
        public readonly string $accessKeyId,
        public readonly string $accessKeySecret,
        public readonly ?string $domain,
        public readonly string $pathPrefix,
    ) {
    }

    public function host(): string
    {
        return $this->driver === self::DRIVER_ALIYUN_OSS
            ? $this->bucket . '.oss-' . $this->region . '.aliyuncs.com'
            : $this->bucket . '.cos.' . $this->region . '.myqcloud.com';
    }

    /**
     * 对象的公开访问地址：有自定义域名用自定义域名，没有就用存储空间默认域名（https）。
     */
    public function publicUrl(string $key): string
    {
        $base = $this->domain !== null && $this->domain !== '' ? rtrim($this->domain, '/') : 'https://' . $this->host();

        return $base . '/' . $key;
    }
}
