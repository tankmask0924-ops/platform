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

namespace HyperfTest\Cases\Admin;

use App\Crypto\Encryptor;
use App\Model\AdminOperationLog;
use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use App\Model\AdminUser;
use App\Model\SystemSetting;
use App\Service\Storage\CloudStorageService;
use App\Storage\CloudStorageClient;
use App\Storage\CloudStorageClientFactory;
use App\Storage\StorageConfig;
use Hyperf\Context\ApplicationContext;
use Hyperf\Testing\Client;
use HyperfTest\HttpTestCase;
use HyperfTest\RebuildsContainer;
use Mockery;
use RuntimeException;

use function Hyperf\Support\make;

/**
 * 系统设置 - 云存储（GET/PUT /admin/storage-settings、POST .../test）和后台图片上传（POST /admin/uploads/image）。
 * 云存储客户端全部换成 Mockery 双重，不连真实的 OSS/COS。
 *
 * 测试库是共享的：开始前把库里原有的 cloud_storage 配置记下来，结束后原样放回。
 *
 * @internal
 * @coversNothing
 */
class CloudStorageControllerTest extends HttpTestCase
{
    use RebuildsContainer;

    private const PASSWORD = 'correct-password';

    /** 1x1 透明 PNG */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private ?array $originalSetting = null;

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $tmpFiles = [];

    /** 当前用例要返回的云存储客户端双重（服务是单例，工厂必须在第一个请求之前就换好） */
    private ?CloudStorageClient $storage = null;

    /** @var null|callable(StorageConfig): void */
    private $inspectConfig;

    protected function setUp(): void
    {
        $this->rebuildContainer();
        $this->client = make(Client::class);
        $factory = Mockery::mock(CloudStorageClientFactory::class);
        $factory->shouldReceive('make')->andReturnUsing(function (StorageConfig $config) {
            $this->inspectConfig && ($this->inspectConfig)($config);

            return $this->storage ?? throw new RuntimeException('test did not set a storage double');
        });
        ApplicationContext::getContainer()->set(CloudStorageClientFactory::class, $factory);

        $row = SystemSetting::query()->where('key', CloudStorageService::SETTING_KEY)->first();
        $this->originalSetting = $row?->getAttributes();
        SystemSetting::query()->where('key', CloudStorageService::SETTING_KEY)->delete();
    }

    protected function tearDown(): void
    {
        SystemSetting::query()->where('key', CloudStorageService::SETTING_KEY)->delete();
        if ($this->originalSetting !== null) {
            SystemSetting::query()->insert($this->originalSetting);
        }
        foreach ($this->adminUserIds as $id) {
            AdminOperationLog::where('admin_user_id', $id)->delete();
            AdminUser::destroy($id);
        }
        foreach ($this->roleIds as $id) {
            AdminRolePermission::where('role_id', $id)->delete();
            AdminRole::destroy($id);
        }
        foreach ($this->permissionIds as $id) {
            AdminPermission::destroy($id);
        }
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        Mockery::close();

        parent::tearDown();
    }

    public function testShowBeforeConfiguredAndSaveKeepsSecretWriteOnly()
    {
        [$token, $admin] = $this->manage();

        $body = $this->json('GET', '/admin/storage-settings', $token);
        $this->assertFalse($body['configured']);
        $this->assertFalse($body['access_key_secret_set']);
        $this->assertSame('uploads', $body['path_prefix']);

        $saved = $this->json('PUT', '/admin/storage-settings', $token, $this->ossPayload());
        $this->assertTrue($saved['configured']);
        $this->assertSame('aliyun_oss', $saved['driver']);
        $this->assertSame('cn-hangzhou', $saved['region'], '带 oss- 前缀的地域会去掉前缀');
        $this->assertTrue($saved['access_key_secret_set']);
        $this->assertArrayNotHasKey('access_key_secret', $saved);
        $this->assertSame('https://img.example.com', $saved['domain']);

        // 密钥加密落库，操作日志里没有密钥
        $stored = json_decode(SystemSetting::query()->where('key', CloudStorageService::SETTING_KEY)->value('value'), true);
        $this->assertNotSame('oss-secret-value', $stored['access_key_secret']);
        $this->assertSame('oss-secret-value', make(Encryptor::class)->decrypt($stored['access_key_secret']));
        $log = AdminOperationLog::where('admin_user_id', $admin->id)->where('action', 'update_cloud_storage')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('oss-secret-value', json_encode($log->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString($stored['access_key_secret'], json_encode($log->toArray(), JSON_UNESCAPED_UNICODE));

        // 留空密钥 = 不改
        $payload = $this->ossPayload();
        $payload['access_key_secret'] = '';
        $payload['path_prefix'] = '/platform/uploads/';
        $saved = $this->json('PUT', '/admin/storage-settings', $token, $payload);
        $this->assertSame('platform/uploads', $saved['path_prefix']);
        $stored = json_decode(SystemSetting::query()->where('key', CloudStorageService::SETTING_KEY)->value('value'), true);
        $this->assertSame('oss-secret-value', make(Encryptor::class)->decrypt($stored['access_key_secret']));

        // 换了云厂商必须重填密钥
        $cos = $this->cosPayload();
        $cos['access_key_secret'] = '';
        $this->assertSame(422, $this->request('PUT', '/admin/storage-settings', $token, $cos)->getStatusCode());
        $saved = $this->json('PUT', '/admin/storage-settings', $token, $this->cosPayload());
        $this->assertSame('tencent_cos', $saved['driver']);
    }

    public function testSaveValidatesFields()
    {
        [$token] = $this->manage();
        $cases = [
            ['driver' => 'qiniu'],
            ['bucket' => 'Bad_Bucket'],
            ['region' => 'cn hangzhou'],
            ['access_key_id' => 'id with space'],
            ['access_key_secret' => ''],
            ['domain' => 'img.example.com'],
            ['domain' => 'javascript:alert(1)'],
            ['path_prefix' => '../etc'],
        ];
        foreach ($cases as $override) {
            $response = $this->request('PUT', '/admin/storage-settings', $token, array_merge($this->ossPayload(), $override));
            $this->assertSame(422, $response->getStatusCode(), json_encode($override));
        }
        // 腾讯云存储桶名称要带 APPID
        $this->assertSame(422, $this->request('PUT', '/admin/storage-settings', $token, array_merge($this->cosPayload(), ['bucket' => 'images']))->getStatusCode());
        $this->assertNull(SystemSetting::query()->where('key', CloudStorageService::SETTING_KEY)->first());
    }

    public function testViewOnlyAdminCannotSaveOrTestAndNoTokenIs401()
    {
        $viewer = $this->loginAs($this->createAdminWithPermissions(['setting.view']));
        $this->assertSame(200, $this->request('GET', '/admin/storage-settings', $viewer)->getStatusCode());
        $this->assertSame(403, $this->request('PUT', '/admin/storage-settings', $viewer, $this->ossPayload())->getStatusCode());
        $this->assertSame(403, $this->request('POST', '/admin/storage-settings/test', $viewer)->getStatusCode());
        $this->assertSame(403, $this->request('POST', '/admin/uploads/image', $viewer)->getStatusCode());
        $this->assertSame(401, $this->client->request('GET', '/admin/storage-settings')->getStatusCode());
        $this->assertSame(401, $this->client->request('POST', '/admin/uploads/image')->getStatusCode());
    }

    public function testConnectionTestUploadsThenDeletes()
    {
        [$token] = $this->manage();
        $this->assertSame(409, $this->request('POST', '/admin/storage-settings/test', $token)->getStatusCode(), '没配置时不能测试');
        $this->json('PUT', '/admin/storage-settings', $token, $this->ossPayload());

        $storage = Mockery::mock(CloudStorageClient::class);
        $storage->shouldReceive('put')->once()->with(Mockery::pattern('#^uploads/connection-test/\d{14}-[0-9a-f]{8}\.txt$#'), Mockery::any(), 'text/plain')
            ->andReturn('http://127.0.0.1:1/unreachable.txt');
        $storage->shouldReceive('delete')->once();
        $this->bindStorage($storage, function (StorageConfig $config) {
            $this->assertSame('oss-secret-value', $config->accessKeySecret, '用解密后的密钥连云存储');
        });

        $body = $this->json('POST', '/admin/storage-settings/test', $token);
        $this->assertTrue($body['uploaded']);
        $this->assertFalse($body['public_readable'], '公开地址读不到要提示');
        $this->assertStringContainsString('公共读', $body['message']);

        $failing = Mockery::mock(CloudStorageClient::class);
        $failing->shouldReceive('put')->andThrow(new RuntimeException('云存储返回 HTTP 403，AccessDenied'));
        $this->bindStorage($failing);
        $response = $this->request('POST', '/admin/storage-settings/test', $token);
        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringContainsString('AccessDenied', (string) $response->getBody());
    }

    public function testUploadImage()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['product.manage']));
        [$manageToken] = $this->manage();

        $this->assertSame(409, $this->upload($token, $this->pngFile())->getStatusCode(), '没配置云存储时提示先去配置');
        $this->json('PUT', '/admin/storage-settings', $manageToken, $this->ossPayload());

        $storage = Mockery::mock(CloudStorageClient::class);
        $storage->shouldReceive('put')->once()
            ->with(Mockery::pattern('#^uploads/images/\d{8}/[0-9a-f]{32}\.png$#'), base64_decode(self::PNG_BASE64), 'image/png')
            ->andReturnUsing(fn (string $key) => 'https://img.example.com/' . $key);
        $this->bindStorage($storage);

        $response = $this->upload($token, $this->pngFile());
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertMatchesRegularExpression('#^https://img\.example\.com/uploads/images/\d{8}/[0-9a-f]{32}\.png$#', $body['url']);

        // 按内容判断类型，不认扩展名
        $fake = $this->tmpFile('fake.png', '<?php echo 1;');
        $this->assertSame(422, $this->upload($token, $fake)->getStatusCode());
        $this->assertSame(422, $this->request('POST', '/admin/uploads/image', $token)->getStatusCode(), '没传文件');
    }

    private function ossPayload(): array
    {
        return [
            'driver' => 'aliyun_oss',
            'bucket' => 'platform-images',
            'region' => 'oss-cn-hangzhou',
            'access_key_id' => 'testAccessKeyId01',
            'access_key_secret' => 'oss-secret-value',
            'domain' => 'https://img.example.com/',
            'path_prefix' => '',
        ];
    }

    private function cosPayload(): array
    {
        return [
            'driver' => 'tencent_cos',
            'bucket' => 'images-1250000000',
            'region' => 'ap-guangzhou',
            'access_key_id' => 'testSecretId0001',
            'access_key_secret' => 'cos-secret-value',
            'domain' => '',
            'path_prefix' => 'uploads',
        ];
    }

    private function bindStorage(CloudStorageClient $storage, ?callable $inspect = null): void
    {
        $this->storage = $storage;
        $this->inspectConfig = $inspect;
    }

    private function pngFile(): string
    {
        return $this->tmpFile('pixel-' . uniqid() . '.png', base64_decode(self::PNG_BASE64));
    }

    private function tmpFile(string $name, string $content): string
    {
        $path = BASE_PATH . '/runtime/' . $name;
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        return $path;
    }

    private function upload(string $token, string $file)
    {
        return $this->client->request('POST', '/admin/uploads/image', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'multipart' => [['name' => 'file', 'contents' => fopen($file, 'r'), 'filename' => basename($file)]],
        ]);
    }

    /**
     * @return array{0: string, 1: AdminUser}
     */
    private function manage(): array
    {
        $admin = $this->createAdminWithPermissions(['setting.view', 'setting.manage']);

        return [$this->loginAs($admin), $admin];
    }

    private function json(string $method, string $path, string $token, array $data = []): array
    {
        $response = $this->request($method, $path, $token, $data);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function request(string $method, string $path, string $token, array $data = [])
    {
        return $this->client->request($method, $path, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'json' => $data,
        ]);
    }

    private function loginAs(AdminUser $admin): string
    {
        $login = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => ['username' => $admin->username, 'password' => self::PASSWORD],
        ]);

        return json_decode((string) $login->getBody(), true)['token'];
    }

    private function createAdminWithPermissions(array $codes): AdminUser
    {
        $role = AdminRole::create(['name' => 'role_' . uniqid('', true), 'is_system' => false]);
        $this->roleIds[] = $role->id;

        foreach ($codes as $code) {
            $permission = AdminPermission::firstOrCreate(['code' => $code], ['module' => 'system', 'name' => $code, 'type' => 'action']);
            if ($permission->wasRecentlyCreated) {
                $this->permissionIds[] = $permission->id;
            }
            AdminRolePermission::create(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }

        $admin = AdminUser::create([
            'username' => 'admin_' . uniqid('', true),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'real_name' => 'Test Admin',
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $this->adminUserIds[] = $admin->id;

        return $admin;
    }
}
