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

namespace App\Service\Storage;

use App\Crypto\Encryptor;
use App\Dao\SystemSettingDao;
use App\Service\AbstractService;
use App\Storage\CloudStorageClient;
use App\Storage\CloudStorageClientFactory;
use App\Storage\StorageConfig;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\HttpMessage\Upload\UploadedFile;
use RuntimeException;
use Throwable;

/**
 * 云存储（阿里云 OSS / 腾讯云 COS）：读取管理员在「系统设置 - 云存储」里填的配置，上传图片。
 * 配置存在 `system_settings` 的 `cloud_storage` 一行（JSON），AccessKey Secret 用 Encryptor 加密后才落库。
 */
class CloudStorageService extends AbstractService
{
    public const SETTING_KEY = 'cloud_storage';

    /** 图片最大 2MB */
    public const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    private const IMAGE_EXTENSIONS = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_GIF => ['gif', 'image/gif'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    #[Inject]
    protected SystemSettingDao $systemSettingDao;

    #[Inject]
    protected Encryptor $encryptor;

    #[Inject]
    protected CloudStorageClientFactory $clientFactory;

    /**
     * 库里存的原始配置（密钥还是密文），没配置过返回 null。
     *
     * @return null|array<string, mixed>
     */
    public function storedSetting(): ?array
    {
        $value = $this->systemSettingDao->getValue(self::SETTING_KEY);

        return is_array($value) ? $value : null;
    }

    /**
     * 解密后的配置，没配置过返回 null。密钥解不开（换过 APP_ENCRYPTION_KEY）抛 RuntimeException。
     */
    public function config(): ?StorageConfig
    {
        $stored = $this->storedSetting();
        if ($stored === null) {
            return null;
        }

        try {
            $secret = $this->encryptor->decrypt((string) ($stored['access_key_secret'] ?? ''));
        } catch (Throwable $e) {
            throw new RuntimeException('云存储密钥无法解密，请在「系统设置 - 云存储」重新填写 AccessKey Secret', 0, $e);
        }

        return new StorageConfig(
            driver: (string) $stored['driver'],
            bucket: (string) $stored['bucket'],
            region: (string) $stored['region'],
            accessKeyId: (string) $stored['access_key_id'],
            accessKeySecret: $secret,
            domain: isset($stored['domain']) && $stored['domain'] !== '' ? (string) $stored['domain'] : null,
            pathPrefix: (string) ($stored['path_prefix'] ?? 'uploads'),
        );
    }

    public function client(StorageConfig $config): CloudStorageClient
    {
        return $this->clientFactory->make($config);
    }

    /**
     * 上传一张图片到云存储，返回公开访问地址。只认 jpg/png/gif/webp（按文件内容判断，不信扩展名），
     * 最大 2MB；对象 key 由平台生成：`前缀/images/年月日/随机串.扩展名`。
     *
     * @return array{url: string, key: string}
     */
    public function uploadImage(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw new HttpException(422, '图片上传失败，请重新选择文件');
        }
        if ($file->getSize() > self::MAX_IMAGE_BYTES) {
            throw new HttpException(422, '图片不能超过 2MB');
        }

        $path = (string) $file->getRealPath();
        $info = $path !== '' ? @getimagesize($path) : false;
        $type = is_array($info) ? ($info[2] ?? null) : null;
        if (! isset(self::IMAGE_EXTENSIONS[$type])) {
            throw new HttpException(422, '只支持 jpg、png、gif、webp 格式的图片');
        }
        [$extension, $contentType] = self::IMAGE_EXTENSIONS[$type];

        try {
            $config = $this->config();
        } catch (RuntimeException $e) {
            throw new HttpException(409, $e->getMessage(), 0, $e);
        }
        if ($config === null) {
            throw new HttpException(409, '还没有配置云存储，请先到「系统设置 - 云存储」配置');
        }

        $key = trim($config->pathPrefix, '/') . '/images/' . date('Ymd') . '/' . bin2hex(random_bytes(16)) . '.' . $extension;

        try {
            $url = $this->client($config)->put($key, (string) file_get_contents($path), $contentType);
        } catch (RuntimeException $e) {
            throw new HttpException(502, '上传到云存储失败：' . $e->getMessage(), 0, $e);
        }

        return ['url' => $url, 'key' => $key];
    }
}
