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

namespace App\Service\Admin;

use App\Crypto\Encryptor;
use App\Dao\AdminOperationLogDao;
use App\Dao\AdminUserDao;
use App\Dao\SystemSettingDao;
use App\Model\AdminUser;
use App\Service\AbstractService;
use App\Service\Storage\CloudStorageService;
use App\Storage\StorageConfig;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Guzzle\ClientFactory;
use Hyperf\HttpMessage\Exception\HttpException;
use RuntimeException;
use Throwable;

/**
 * 系统设置 - 云存储：选阿里云 OSS 或腾讯云 COS，填存储空间、地域、AccessKey、访问域名、路径前缀。
 *
 * - AccessKey Secret 只写不读：加密落库，查看时只返回「已设置」；保存时留空表示不改。换了云厂商必须重填。
 * - 每次保存记操作日志（不含密钥），跟系统参数一样属于重点审计（requirements.md 9）。
 * - 「测试连接」真的上传一个小文件、用公开地址读一次、再删掉，能看出 AccessKey 权限和存储空间是否公共读。
 */
class StorageSettingAdminService extends AbstractService
{
    private const MODULE = 'system';

    private const DRIVERS = [StorageConfig::DRIVER_ALIYUN_OSS, StorageConfig::DRIVER_TENCENT_COS];

    private const DEFAULT_PATH_PREFIX = 'uploads';

    #[Inject]
    protected CloudStorageService $cloudStorageService;

    #[Inject]
    protected SystemSettingDao $systemSettingDao;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    #[Inject]
    protected Encryptor $encryptor;

    #[Inject]
    protected ClientFactory $clientFactory;

    /**
     * @return array<string, mixed>
     */
    public function show(): array
    {
        $stored = $this->cloudStorageService->storedSetting();
        $row = $stored === null ? null : $this->systemSettingDao->findByKey(CloudStorageService::SETTING_KEY);
        $updatedBy = $row?->updated_by !== null ? $this->adminUserDao->find((int) $row->updated_by) : null;

        return [
            'configured' => $stored !== null,
            'driver' => $stored['driver'] ?? null,
            'bucket' => $stored['bucket'] ?? null,
            'region' => $stored['region'] ?? null,
            'access_key_id' => $stored['access_key_id'] ?? null,
            'access_key_secret_set' => isset($stored['access_key_secret']) && $stored['access_key_secret'] !== '',
            'domain' => $stored['domain'] ?? null,
            'path_prefix' => $stored['path_prefix'] ?? self::DEFAULT_PATH_PREFIX,
            'updated_by' => $updatedBy instanceof AdminUser ? $updatedBy->real_name : null,
            'updated_at' => $row?->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @param array<string, mixed> $data driver/bucket/region/access_key_id/access_key_secret/domain/path_prefix
     * @return array<string, mixed>
     */
    public function update(AdminUser $operator, array $data, ?string $ip): array
    {
        $stored = $this->cloudStorageService->storedSetting();

        $driver = $data['driver'] ?? null;
        if (! is_string($driver) || ! in_array($driver, self::DRIVERS, true)) {
            throw new HttpException(422, '请选择云存储：阿里云 OSS 或腾讯云 COS');
        }
        $bucket = $this->requiredString($data, 'bucket', '存储空间（Bucket）');
        if ($driver === StorageConfig::DRIVER_TENCENT_COS
            ? preg_match('/^[a-z0-9][a-z0-9-]{0,48}-\d{5,12}$/', $bucket) !== 1
            : preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $bucket) !== 1) {
            throw new HttpException(422, $driver === StorageConfig::DRIVER_TENCENT_COS
                ? '腾讯云存储桶名称要带 APPID，形如 images-1250000000'
                : '阿里云存储空间名称只能是小写字母、数字和短横线，3~63 位');
        }
        $region = $this->requiredString($data, 'region', '地域');
        if (preg_match('/^[a-z0-9-]{2,32}$/', $region) !== 1) {
            throw new HttpException(422, $driver === StorageConfig::DRIVER_TENCENT_COS ? '地域形如 ap-guangzhou' : '地域形如 cn-hangzhou（不带 oss- 前缀）');
        }
        $region = $driver === StorageConfig::DRIVER_ALIYUN_OSS ? preg_replace('/^oss-/', '', $region) : $region;
        $accessKeyId = $this->requiredString($data, 'access_key_id', 'AccessKey ID');
        if (preg_match('/^[A-Za-z0-9]{8,128}$/', $accessKeyId) !== 1) {
            throw new HttpException(422, 'AccessKey ID 格式不对');
        }

        $secret = is_string($data['access_key_secret'] ?? null) ? trim($data['access_key_secret']) : '';
        if ($secret !== '' && (strlen($secret) > 128 || preg_match('/^[\x21-\x7e]+$/', $secret) !== 1)) {
            throw new HttpException(422, 'AccessKey Secret 格式不对');
        }
        if ($secret === '' && ($stored === null || ($stored['driver'] ?? null) !== $driver || ($stored['access_key_id'] ?? null) !== $accessKeyId)) {
            throw new HttpException(422, '请填写 AccessKey Secret（第一次配置、换了云厂商或换了 AccessKey ID 时必须重填）');
        }
        $encryptedSecret = $secret !== '' ? $this->encryptor->encrypt($secret) : (string) $stored['access_key_secret'];

        $domain = is_string($data['domain'] ?? null) ? rtrim(trim($data['domain']), '/') : '';
        if ($domain !== '' && preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~/-]*)?$#', $domain) !== 1) {
            throw new HttpException(422, '访问域名要以 https:// 或 http:// 开头，例如 https://img.example.com');
        }
        $pathPrefix = is_string($data['path_prefix'] ?? null) ? trim($data['path_prefix'], " /\t") : '';
        $pathPrefix = $pathPrefix === '' ? self::DEFAULT_PATH_PREFIX : $pathPrefix;
        if (preg_match('#^[a-z0-9][a-z0-9_-]{0,31}(/[a-z0-9][a-z0-9_-]{0,31}){0,3}$#', $pathPrefix) !== 1) {
            throw new HttpException(422, '路径前缀只能是小写字母、数字、下划线和短横线，最多 4 级，例如 platform/uploads');
        }

        $value = [
            'driver' => $driver,
            'bucket' => $bucket,
            'region' => $region,
            'access_key_id' => $accessKeyId,
            'access_key_secret' => $encryptedSecret,
            'domain' => $domain === '' ? null : $domain,
            'path_prefix' => $pathPrefix,
        ];

        Db::transaction(function () use ($operator, $stored, $value, $secret, $ip) {
            $attrs = ['value' => json_encode($value, JSON_UNESCAPED_SLASHES), 'description' => '云存储', 'updated_by' => $operator->id];
            $row = $this->systemSettingDao->findByKey(CloudStorageService::SETTING_KEY);
            $row ? $row->fill($attrs)->save() : $this->systemSettingDao->create(['key' => CloudStorageService::SETTING_KEY] + $attrs);

            $this->operationLogDao->record(
                $operator->id,
                self::MODULE,
                'update_cloud_storage',
                'system_setting',
                null,
                $stored === null ? null : $this->withoutSecret($stored),
                $this->withoutSecret($value) + ['access_key_secret_changed' => $secret !== ''],
                $ip
            );
        });

        return $this->show();
    }

    /**
     * 测试连接：上传一个小文本文件 → 用公开地址读一次 → 删掉。上传失败直接报错（通常是 AccessKey、
     * 存储空间名或地域不对）；读不到说明存储空间不是公共读，图片商户打不开。
     *
     * @return array{uploaded: bool, public_readable: bool, url: string, message: string}
     */
    public function test(): array
    {
        try {
            $config = $this->cloudStorageService->config();
        } catch (RuntimeException $e) {
            throw new HttpException(409, $e->getMessage(), 0, $e);
        }
        if ($config === null) {
            throw new HttpException(409, '还没有配置云存储');
        }

        $client = $this->cloudStorageService->client($config);
        $key = trim($config->pathPrefix, '/') . '/connection-test/' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.txt';
        $content = 'platform cloud storage connection test';

        try {
            $url = $client->put($key, $content, 'text/plain');
        } catch (RuntimeException $e) {
            throw new HttpException(502, '上传测试文件失败：' . $e->getMessage(), 0, $e);
        }

        $readable = false;
        try {
            $response = $this->clientFactory->create()->request('GET', $url, ['timeout' => 10, 'connect_timeout' => 5, 'http_errors' => false]);
            $readable = $response->getStatusCode() === 200 && (string) $response->getBody() === $content;
        } catch (Throwable) {
            $readable = false;
        }

        try {
            $client->delete($key);
        } catch (RuntimeException) {
            // 删不掉只是留下一个测试小文件，不影响结论
        }

        return [
            'uploaded' => true,
            'public_readable' => $readable,
            'url' => $url,
            'message' => $readable
                ? '连接正常：上传成功，公开地址可以访问'
                : '上传成功，但用公开地址读不到：请把存储空间设为公共读，或检查访问域名是否正确',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredString(array $data, string $field, string $label): string
    {
        $value = is_string($data[$field] ?? null) ? trim($data[$field]) : '';
        if ($value === '') {
            throw new HttpException(422, '请填写' . $label);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function withoutSecret(array $value): array
    {
        unset($value['access_key_secret']);

        return $value;
    }
}
