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

use App\Auth\AdminJwtGuard;
use App\Auth\MerchantJwtGuard;
use App\Model\AdminRole;
use App\Model\AdminUser;
use App\Model\Merchant;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * 真实 HTTP 派发的端到端测试，用法跟 test/Cases/Merchant/AuthControllerTest.php 一致
 * （用 test/HttpTestCase.php 包的 Hyperf\Testing\Client，走真实路由 + 中间件栈）。
 *
 * 目前没有任何 API 能创建一条真实 admin_users 记录（管理员账号不是自助注册的，
 * 见 App\Service\Admin\AuthService 类注释），所以这里跟 test/Cases/Dao/* 一样，
 * 直接用 Model 插测试数据，不通过接口注册。
 *
 * @internal
 * @coversNothing
 */
class AuthControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $adminUserIds = [];

    private array $roleIds = [];

    protected function tearDown(): void
    {
        foreach ($this->adminUserIds as $id) {
            AdminUser::destroy($id);
        }
        foreach ($this->roleIds as $id) {
            AdminRole::destroy($id);
        }
        $this->adminUserIds = [];
        $this->roleIds = [];

        parent::tearDown();
    }

    public function testLoginWithCorrectCredentialsSucceedsAndUpdatesLastLoginAt()
    {
        $admin = $this->createActiveAdmin();
        $this->assertNull($admin->last_login_at);

        $response = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => [
                'username' => $admin->username,
                'password' => self::PASSWORD,
            ],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($body['token']);
        $this->assertSame($admin->username, $body['username']);

        $fresh = AdminUser::find($admin->id);
        $this->assertNotNull($fresh->last_login_at);
    }

    public function testLoginWithWrongPasswordAndUnknownUsernameShareTheSameGenericMessage()
    {
        $admin = $this->createActiveAdmin();

        $wrongPassword = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => [
                'username' => $admin->username,
                'password' => 'totally-wrong-password',
            ],
        ]);

        $unknownUsername = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => [
                'username' => 'no-such-admin-' . uniqid('', true),
                'password' => 'whatever',
            ],
        ]);

        $this->assertSame(401, $wrongPassword->getStatusCode());
        $this->assertSame(401, $unknownUsername->getStatusCode());
        $this->assertSame(
            (string) $wrongPassword->getBody(),
            (string) $unknownUsername->getBody()
        );
    }

    public function testLoginWithDisabledAdminIsBlockedDespiteCorrectPassword()
    {
        $admin = $this->createActiveAdmin(['status' => 'disabled']);

        $response = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => [
                'username' => $admin->username,
                'password' => self::PASSWORD,
            ],
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMeWithValidTokenReturnsSafeFieldsWithoutPassword()
    {
        $admin = $this->createActiveAdmin();

        $login = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => [
                'username' => $admin->username,
                'password' => self::PASSWORD,
            ],
        ]);
        $token = json_decode((string) $login->getBody(), true)['token'];

        $response = $this->client->request('GET', '/admin/auth/me', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($admin->id, $body['id']);
        $this->assertSame($admin->username, $body['username']);
        $this->assertSame($admin->real_name, $body['real_name']);
        $this->assertSame($admin->role_id, $body['role_id']);
        $this->assertSame('active', $body['status']);
        $this->assertArrayNotHasKey('password', $body);
    }

    public function testMeWithMissingOrInvalidTokenReturns401()
    {
        $noHeader = $this->client->request('GET', '/admin/auth/me');
        $this->assertSame(401, $noHeader->getStatusCode());

        $garbage = $this->client->request('GET', '/admin/auth/me', [
            'headers' => ['Authorization' => 'Bearer garbage-token-' . uniqid('', true)],
        ]);
        $this->assertSame(401, $garbage->getStatusCode());
    }

    /**
     * 跨子系统隔离：商户端 JWT 和管理后台 JWT 用完全独立的密钥
     * （MERCHANT_JWT_SECRET / ADMIN_JWT_SECRET）签发，任何一方签发的 token
     * 都不应该被另一方的中间件接受——这是这两套鉴权系统真正独立的证明，
     * 不只是命名上分开。
     */
    public function testMerchantTokenIsRejectedByAdminAuthMiddleware()
    {
        $merchant = Merchant::create([
            'type' => 'company',
            'password' => password_hash('whatever', PASSWORD_BCRYPT, ['cost' => 4]),
            'status' => 'active',
            'phone' => '188' . random_int(10000000, 99999999),
        ]);

        try {
            $merchantJwtGuard = make(MerchantJwtGuard::class);
            $merchantToken = $merchantJwtGuard->issue($merchant->id, $merchant->password);

            $response = $this->client->request('GET', '/admin/auth/me', [
                'headers' => ['Authorization' => 'Bearer ' . $merchantToken],
            ]);

            $this->assertSame(401, $response->getStatusCode());
        } finally {
            Merchant::destroy($merchant->id);
        }
    }

    public function testAdminTokenIsRejectedByMerchantAuthMiddleware()
    {
        $admin = $this->createActiveAdmin();

        $adminJwtGuard = make(AdminJwtGuard::class);
        $adminToken = $adminJwtGuard->issue($admin->id, $admin->password);

        $response = $this->client->request('GET', '/merchant/auth/me', [
            'headers' => ['Authorization' => 'Bearer ' . $adminToken],
        ]);

        $this->assertSame(401, $response->getStatusCode());
    }

    private function createActiveAdmin(array $overrides = []): AdminUser
    {
        $role = AdminRole::create([
            'name' => 'role_' . uniqid('', true),
            'is_system' => false,
        ]);
        $this->roleIds[] = $role->id;

        $admin = AdminUser::create(array_merge([
            'username' => 'admin_' . uniqid('', true),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'real_name' => 'Test Admin',
            'role_id' => $role->id,
            'status' => 'active',
        ], $overrides));

        $this->adminUserIds[] = $admin->id;

        return $admin;
    }
}
