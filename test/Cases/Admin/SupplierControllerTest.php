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
use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use App\Model\AdminUser;
use App\Model\Supplier;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * 系统管理后台（web/admin）「供应商管理 - 配置 CRUD」（requirements.md 6.3），
 * docs/modules.md 第 8 节。结构跟 test/Cases/Admin/MerchantControllerTest.php
 * 一致：两个独立权限编码（supplier.view / supplier.manage）各自验证生效，
 * 加上本任务最核心的一条断言——config 密文在数据库里真的不是明文，
 * 且通过 App\Crypto\Encryptor 解密后能原样还原成写入时的 JSON。
 *
 * @internal
 * @coversNothing
 */
class SupplierControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private const VIEW_PERMISSION_CODE = 'supplier.view';

    private const MANAGE_PERMISSION_CODE = 'supplier.manage';

    /**
     * 配置里明显是密钥的字段，masking 之后不应该在任何响应体里出现。
     */
    private const SECRET_VALUE = 'super-secret-api-key-should-never-leak';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $supplierIds = [];

    protected function tearDown(): void
    {
        foreach ($this->adminUserIds as $id) {
            AdminUser::destroy($id);
        }
        foreach ($this->roleIds as $id) {
            AdminRolePermission::where('role_id', $id)->delete();
            AdminRole::destroy($id);
        }
        foreach ($this->permissionIds as $id) {
            AdminPermission::destroy($id);
        }
        foreach ($this->supplierIds as $id) {
            Supplier::destroy($id);
        }
        $this->adminUserIds = [];
        $this->roleIds = [];
        $this->permissionIds = [];
        $this->supplierIds = [];

        parent::tearDown();
    }

    public function testCreateStoresEncryptedConfigAndRoundTripsThroughEncryptor()
    {
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $config = [
            'base_url' => 'https://api.kasushou.example.com',
            'user_id' => 'u-10086',
            'api_key' => self::SECRET_VALUE,
        ];

        $response = $this->jsonRequest('POST', '/admin/suppliers', $token, [
            'name' => '卡速售',
            'code' => 'kasushou_' . uniqid('', true),
            'driver' => 'kasushou',
            'business_line' => 'recharge',
            'config' => $config,
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('id', $body);
        $this->supplierIds[] = $body['id'];

        // 核心断言之一：数据库里的原始列值绝不是明文 JSON，也绝不包含密钥明文。
        $raw = Supplier::query()->find($body['id']);
        $this->assertNotSame(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $raw->config);
        $this->assertStringNotContainsString(self::SECRET_VALUE, $raw->config);

        // 核心断言之二：用 Encryptor 解密后能原样还原成写入时的 JSON（round-trip）。
        $decrypted = json_decode(make(Encryptor::class)->decrypt($raw->config), true);
        $this->assertSame($config, $decrypted);
    }

    public function testCreateWithUnknownDriverReturns422()
    {
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->jsonRequest('POST', '/admin/suppliers', $token, [
            'name' => '云洋',
            'code' => 'yunyang_' . uniqid('', true),
            'driver' => 'yunyang',
            'business_line' => 'recharge',
            'config' => ['foo' => 'bar'],
        ]);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testCreateWithInvalidBusinessLineReturns422()
    {
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->jsonRequest('POST', '/admin/suppliers', $token, [
            'name' => '卡速售',
            'code' => 'kasushou_' . uniqid('', true),
            'driver' => 'kasushou',
            'business_line' => 'not_a_real_business_line',
            'config' => ['foo' => 'bar'],
        ]);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testCreateWithDuplicateCodeReturnsCleanError()
    {
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $code = 'kasushou_' . uniqid('', true);
        $this->createSupplier(['code' => $code]);

        $response = $this->jsonRequest('POST', '/admin/suppliers', $token, [
            'name' => '卡速售-重复',
            'code' => $code,
            'driver' => 'kasushou',
            'business_line' => 'recharge',
            'config' => ['foo' => 'bar'],
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());
    }

    public function testListNeverLeaksPlaintextSecret()
    {
        $supplier = $this->createSupplier([
            'config' => ['base_url' => 'https://api.example.com', 'api_key' => self::SECRET_VALUE],
        ]);
        $token = $this->loginAs($this->createAdminWithPermissions([self::VIEW_PERMISSION_CODE]));

        $response = $this->client->request('GET', '/admin/suppliers', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $raw = (string) $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString(self::SECRET_VALUE, $raw);

        $body = json_decode($raw, true);
        $ids = array_column($body['data'], 'id');
        $this->assertContains($supplier->id, $ids);
    }

    public function testDetailMasksSensitiveConfigKeysButKeepsNonSecretFieldsVisible()
    {
        $supplier = $this->createSupplier([
            'config' => [
                'base_url' => 'https://api.example.com',
                'user_id' => 'u-10086',
                'api_key' => self::SECRET_VALUE,
            ],
        ]);
        $token = $this->loginAs($this->createAdminWithPermissions([self::VIEW_PERMISSION_CODE]));

        $response = $this->client->request('GET', '/admin/suppliers/' . $supplier->id, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $raw = (string) $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        // 明文密钥任何形式都不应该出现在响应体里。
        $this->assertStringNotContainsString(self::SECRET_VALUE, $raw);

        $body = json_decode($raw, true);
        $this->assertSame('https://api.example.com', $body['config']['base_url']);
        $this->assertSame('u-10086', $body['config']['user_id']);
        $this->assertSame('******', $body['config']['api_key']);
    }

    /**
     * 库里存在早期测试/调试留下的供应商行，`config` 是用别的 APP_ENCRYPTION_KEY 加密的
     * （轮换密钥后也会出现同样的行），当前密钥解不开。这种行必须还能打开详情页——
     * 运营要做的恰恰是进去把配置重新填一遍，不能让一条坏数据把整页锁死。
     * 降级逻辑见 App\Service\Admin\SupplierAdminService::readConfig()。
     */
    public function testDetailDegradesGracefullyWhenConfigCannotBeDecrypted()
    {
        $supplier = $this->createSupplier();
        // 绕开 Encryptor 直接写一段当前密钥解不开的密文
        $supplier->forceFill(['config' => base64_encode(random_bytes(64))])->save();
        $token = $this->loginAs($this->createAdminWithPermissions([self::VIEW_PERMISSION_CODE]));

        $response = $this->client->request('GET', '/admin/suppliers/' . $supplier->id, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertTrue($body['config_unreadable']);
        $this->assertNull($body['config']);
        // 页面其它部分照常可用，运营才能进来重新填配置
        $this->assertSame($supplier->name, $body['name']);
        $this->assertSame($supplier->code, $body['code']);
        $this->assertArrayHasKey('order_notify_url', $body);
    }

    /**
     * 解密成功但内容不是 JSON 对象的历史脏数据，走同一条降级路径。
     */
    public function testDetailDegradesWhenConfigIsNotAJsonObject()
    {
        $supplier = $this->createSupplier();
        $supplier->forceFill(['config' => make(Encryptor::class)->encrypt('not json at all')])->save();
        $token = $this->loginAs($this->createAdminWithPermissions([self::VIEW_PERMISSION_CODE]));

        $response = $this->client->request('GET', '/admin/suppliers/' . $supplier->id, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertTrue(json_decode((string) $response->getBody(), true)['config_unreadable']);
    }

    /**
     * 正常的行不该被误标成"解不开"。
     */
    public function testDetailMarksReadableConfigAsReadable()
    {
        $supplier = $this->createSupplier(['config' => ['base_url' => 'https://api.example.com']]);
        $token = $this->loginAs($this->createAdminWithPermissions([self::VIEW_PERMISSION_CODE]));

        $response = $this->client->request('GET', '/admin/suppliers/' . $supplier->id, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertFalse($body['config_unreadable']);
        $this->assertSame('https://api.example.com', $body['config']['base_url']);
    }

    public function testDetailForNonexistentSupplierReturns404()
    {
        $token = $this->loginAs($this->createAdminWithPermissions([self::VIEW_PERMISSION_CODE]));

        $response = $this->client->request('GET', '/admin/suppliers/999999999', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateWithDifferentCodeReturns422AndLeavesCodeUnchanged()
    {
        $supplier = $this->createSupplier();
        $originalCode = $supplier->code;
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->jsonRequest('PUT', '/admin/suppliers/' . $supplier->id, $token, [
            'code' => $originalCode . '-changed',
        ]);

        $this->assertSame(422, $response->getStatusCode());

        $supplier->refresh();
        $this->assertSame($originalCode, $supplier->code);
    }

    public function testUpdateReplacesConfigCiphertextAndNewPlaintextRoundTrips()
    {
        $supplier = $this->createSupplier([
            'config' => ['base_url' => 'https://old.example.com', 'api_key' => 'old-secret'],
        ]);
        $oldCiphertext = $supplier->config;
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $newConfig = ['base_url' => 'https://new.example.com', 'api_key' => self::SECRET_VALUE];
        $response = $this->jsonRequest('PUT', '/admin/suppliers/' . $supplier->id, $token, [
            'config' => $newConfig,
        ]);

        $this->assertSame(200, $response->getStatusCode());

        $supplier->refresh();
        $this->assertNotSame($oldCiphertext, $supplier->config);
        $this->assertStringNotContainsString(self::SECRET_VALUE, $supplier->config);

        $decrypted = json_decode(make(Encryptor::class)->decrypt($supplier->config), true);
        $this->assertSame($newConfig, $decrypted);
    }

    public function testUpdateNonexistentSupplierReturns404()
    {
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->jsonRequest('PUT', '/admin/suppliers/999999999', $token, [
            'name' => '不存在',
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testStatusToggleActiveToDisabledAndBack()
    {
        $supplier = $this->createSupplier(['status' => 'active']);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/suppliers/' . $supplier->id . '/status', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['status' => 'disabled'],
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $supplier->refresh();
        $this->assertSame('disabled', $supplier->status);

        $response = $this->client->request('POST', '/admin/suppliers/' . $supplier->id . '/status', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['status' => 'active'],
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $supplier->refresh();
        $this->assertSame('active', $supplier->status);
    }

    public function testStatusToggleWithInvalidValueReturns422()
    {
        $supplier = $this->createSupplier(['status' => 'active']);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/suppliers/' . $supplier->id . '/status', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['status' => 'not_a_real_status'],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $supplier->refresh();
        $this->assertSame('active', $supplier->status);
    }

    /**
     * 关键用例：证明 'supplier.view' 不能替代 'supplier.manage'——只有查看权限的
     * 管理员在列表接口上放行，但在新建/修改/启停这些会改变供应商配置的动作上
     * 必须被拒绝，跟商户管理那组 merchant.view/merchant.review 的隔离测试同理。
     */
    public function testViewOnlyAdminGets403OnManageActions()
    {
        $supplier = $this->createSupplier();
        $token = $this->loginAs($this->createAdminWithPermissions([self::VIEW_PERMISSION_CODE]));

        $createResponse = $this->jsonRequest('POST', '/admin/suppliers', $token, [
            'name' => '卡速售',
            'code' => 'kasushou_' . uniqid('', true),
            'driver' => 'kasushou',
            'business_line' => 'recharge',
            'config' => ['foo' => 'bar'],
        ]);
        $this->assertSame(403, $createResponse->getStatusCode());

        $updateResponse = $this->jsonRequest('PUT', '/admin/suppliers/' . $supplier->id, $token, [
            'name' => '改个名字',
        ]);
        $this->assertSame(403, $updateResponse->getStatusCode());

        $statusResponse = $this->client->request('POST', '/admin/suppliers/' . $supplier->id . '/status', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['status' => 'disabled'],
        ]);
        $this->assertSame(403, $statusResponse->getStatusCode());
    }

    public function testNoTokenAtAllReturns401OnEveryRoute()
    {
        $supplier = $this->createSupplier();

        $this->assertSame(401, $this->client->request('GET', '/admin/suppliers')->getStatusCode());
        $this->assertSame(401, $this->client->request('GET', '/admin/suppliers/' . $supplier->id)->getStatusCode());
        $this->assertSame(401, $this->client->request('POST', '/admin/suppliers')->getStatusCode());
        $this->assertSame(401, $this->client->request('PUT', '/admin/suppliers/' . $supplier->id)->getStatusCode());
        $this->assertSame(
            401,
            $this->client->request('POST', '/admin/suppliers/' . $supplier->id . '/status')->getStatusCode()
        );
    }

    private function jsonRequest(string $method, string $path, string $token, array $data)
    {
        return $this->client->request($method, $path, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'json' => $data,
        ]);
    }

    private function loginAs(AdminUser $admin): string
    {
        $login = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => [
                'username' => $admin->username,
                'password' => self::PASSWORD,
            ],
        ]);

        return json_decode((string) $login->getBody(), true)['token'];
    }

    private function createAdminWithPermissions(array $codes): AdminUser
    {
        $role = AdminRole::create([
            'name' => 'role_' . uniqid('', true),
            'is_system' => false,
        ]);
        $this->roleIds[] = $role->id;

        foreach ($codes as $code) {
            $permission = AdminPermission::firstOrCreate(
                ['code' => $code],
                ['module' => 'supplier', 'name' => $code, 'type' => 'action']
            );
            if ($permission->wasRecentlyCreated) {
                $this->permissionIds[] = $permission->id;
            }

            AdminRolePermission::create([
                'role_id' => $role->id,
                'permission_id' => $permission->id,
            ]);
        }

        return $this->createAdmin($role->id);
    }

    private function createAdmin(int $roleId): AdminUser
    {
        $admin = AdminUser::create([
            'username' => 'admin_' . uniqid('', true),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'real_name' => 'Test Admin',
            'role_id' => $roleId,
            'status' => 'active',
        ]);

        $this->adminUserIds[] = $admin->id;

        return $admin;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createSupplier(array $overrides = []): Supplier
    {
        $config = $overrides['config'] ?? ['base_url' => 'https://api.example.com', 'api_key' => 'a-secret'];
        unset($overrides['config']);

        $supplier = Supplier::create(array_merge([
            'name' => '测试供应商',
            'code' => 'supplier_' . uniqid('', true),
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => make(Encryptor::class)->encrypt(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'status' => 'active',
        ], $overrides));
        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }
}
