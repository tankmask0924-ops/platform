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

use App\Dao\SystemSettingDao;
use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use App\Model\AdminUser;
use App\Model\Merchant;
use App\Model\MerchantLevel;
use App\Model\MerchantRateLimit;
use App\Service\Admin\MerchantAdminService;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * 系统管理后台「商户管理：启用禁用 / 调整等级 / 限流设置」（requirements.md 8.3），
 * docs/modules.md 第 8 节。三个动作共用 'merchant.manage' 权限编码，跟
 * MerchantControllerTest 里的列表/审核/调账分开放，结构保持一致。
 *
 * @internal
 * @coversNothing
 */
class MerchantManagementControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private const VIEW_PERMISSION_CODE = 'merchant.view';

    private const MANAGE_PERMISSION_CODE = 'merchant.manage';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $merchantIds = [];

    private array $levelIds = [];

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
        foreach ($this->merchantIds as $id) {
            MerchantRateLimit::where('merchant_id', $id)->delete();
            Merchant::destroy($id);
        }
        foreach ($this->levelIds as $id) {
            MerchantLevel::destroy($id);
        }
        $this->adminUserIds = [];
        $this->roleIds = [];
        $this->permissionIds = [];
        $this->merchantIds = [];
        $this->levelIds = [];

        parent::tearDown();
    }

    public function testDisableThenEnableActiveMerchant()
    {
        $merchant = $this->createMerchant('active');
        $token = $this->manageToken();

        $response = $this->changeStatus($token, $merchant->id, 'disabled');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('disabled', $merchant->refresh()->status);

        $response = $this->changeStatus($token, $merchant->id, 'active');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('active', $merchant->refresh()->status);
    }

    public function testDisableKeepsLevelAndBalance()
    {
        $level = $this->createLevel();
        $merchant = $this->createMerchant('active', [
            'level_id' => $level->id,
            'available_balance' => '88.50',
        ]);

        $this->changeStatus($this->manageToken(), $merchant->id, 'disabled');

        $merchant->refresh();
        $this->assertSame($level->id, (int) $merchant->level_id);
        $this->assertSame('88.50', $merchant->available_balance);
    }

    public function testChangeStatusToSameStatusIsIdempotent()
    {
        $merchant = $this->createMerchant('disabled');

        $response = $this->changeStatus($this->manageToken(), $merchant->id, 'disabled');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('disabled', $merchant->refresh()->status);
    }

    public function testChangeStatusWithInvalidValueReturns422()
    {
        $merchant = $this->createMerchant('active');
        $token = $this->manageToken();

        foreach (['pending', 'rejected', '', null] as $status) {
            $response = $this->changeStatus($token, $merchant->id, $status);
            $this->assertSame(422, $response->getStatusCode(), var_export($status, true));
        }
        $this->assertSame('active', $merchant->refresh()->status);
    }

    /**
     * 启用禁用不能绕过入驻审核：pending/rejected 商户直接改成 active 必须被拒绝。
     */
    public function testChangeStatusOnUnreviewedMerchantReturns409()
    {
        $token = $this->manageToken();

        foreach (['pending', 'rejected'] as $status) {
            $merchant = $this->createMerchant($status);

            $response = $this->changeStatus($token, $merchant->id, 'active');

            $this->assertSame(409, $response->getStatusCode());
            $this->assertSame($status, $merchant->refresh()->status);
        }
    }

    public function testChangeStatusOnNonexistentMerchantReturns404()
    {
        $response = $this->changeStatus($this->manageToken(), 999999999, 'disabled');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testChangeLevelUpdatesActiveAndDisabledMerchants()
    {
        $oldLevel = $this->createLevel();
        $newLevel = $this->createLevel();
        $token = $this->manageToken();

        foreach (['active', 'disabled'] as $status) {
            $merchant = $this->createMerchant($status, ['level_id' => $oldLevel->id]);

            $response = $this->jsonRequest('PUT', '/admin/merchants/' . $merchant->id . '/level', $token, [
                'level_id' => $newLevel->id,
            ]);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame($newLevel->id, (int) $merchant->refresh()->level_id);
        }
    }

    public function testChangeLevelWithInvalidLevelReturns422()
    {
        $level = $this->createLevel();
        $merchant = $this->createMerchant('active', ['level_id' => $level->id]);
        $token = $this->manageToken();

        foreach ([999999999, 'abc', null] as $levelId) {
            $response = $this->jsonRequest('PUT', '/admin/merchants/' . $merchant->id . '/level', $token, [
                'level_id' => $levelId,
            ]);
            $this->assertSame(422, $response->getStatusCode(), var_export($levelId, true));
        }
        $this->assertSame($level->id, (int) $merchant->refresh()->level_id);
    }

    public function testChangeLevelOnPendingMerchantReturns409()
    {
        $merchant = $this->createMerchant('pending');
        $level = $this->createLevel();

        $response = $this->jsonRequest('PUT', '/admin/merchants/' . $merchant->id . '/level', $this->manageToken(), [
            'level_id' => $level->id,
        ]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertNull($merchant->refresh()->level_id);
    }

    public function testChangeLevelOnNonexistentMerchantReturns404()
    {
        $level = $this->createLevel();

        $response = $this->jsonRequest('PUT', '/admin/merchants/999999999/level', $this->manageToken(), [
            'level_id' => $level->id,
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSetRateLimitCreatesThenUpdatesAndShowsInDetail()
    {
        $merchant = $this->createMerchant('active');
        $token = $this->manageToken();

        $response = $this->setRateLimit($token, $merchant->id, 200);
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['limit_per_second' => 200, 'is_custom' => true], $body);

        // 纯数字字符串也接受，且原地更新同一行。
        $response = $this->setRateLimit($token, $merchant->id, '300');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, MerchantRateLimit::where('merchant_id', $merchant->id)->count());
        $this->assertSame(300, MerchantRateLimit::where('merchant_id', $merchant->id)->first()->limit_per_second);

        $detail = $this->jsonRequest('GET', '/admin/merchants/' . $merchant->id, $this->viewToken());
        $detailBody = json_decode((string) $detail->getBody(), true);
        $this->assertSame(['limit_per_second' => 300, 'is_custom' => true], $detailBody['rate_limit']);
    }

    public function testDetailWithoutCustomRateLimitShowsGlobalDefault()
    {
        $merchant = $this->createMerchant('active');

        $response = $this->jsonRequest('GET', '/admin/merchants/' . $merchant->id, $this->viewToken());
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'limit_per_second' => $this->expectedDefaultRateLimit(),
            'is_custom' => false,
        ], $body['rate_limit']);
    }

    public function testResetRateLimitFallsBackToDefaultAndIsIdempotent()
    {
        $merchant = $this->createMerchant('active');
        $token = $this->manageToken();
        $this->setRateLimit($token, $merchant->id, 200);

        $expected = ['limit_per_second' => $this->expectedDefaultRateLimit(), 'is_custom' => false];

        for ($i = 0; $i < 2; ++$i) {
            $response = $this->jsonRequest('DELETE', '/admin/merchants/' . $merchant->id . '/rate-limit', $token);
            $body = json_decode((string) $response->getBody(), true);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame($expected, $body);
        }
        $this->assertSame(0, MerchantRateLimit::where('merchant_id', $merchant->id)->count());
    }

    public function testSetRateLimitWithInvalidValuesReturns422()
    {
        $merchant = $this->createMerchant('active');
        $token = $this->manageToken();

        $invalid = [0, -1, 1.5, '1.5', 'abc', '', null, MerchantAdminService::MAX_RATE_LIMIT_PER_SECOND + 1];
        foreach ($invalid as $value) {
            $response = $this->setRateLimit($token, $merchant->id, $value);
            $this->assertSame(422, $response->getStatusCode(), var_export($value, true));
        }
        $this->assertSame(0, MerchantRateLimit::where('merchant_id', $merchant->id)->count());
    }

    public function testRateLimitOnNonexistentMerchantReturns404()
    {
        $token = $this->manageToken();

        $this->assertSame(404, $this->setRateLimit($token, 999999999, 100)->getStatusCode());
        $this->assertSame(
            404,
            $this->jsonRequest('DELETE', '/admin/merchants/999999999/rate-limit', $token)->getStatusCode()
        );
    }

    /**
     * 只有 merchant.view 的管理员不能做任何管理动作。
     */
    public function testViewOnlyAdminGets403OnManageActions()
    {
        $level = $this->createLevel();
        $merchant = $this->createMerchant('active', ['level_id' => $level->id]);
        $token = $this->viewToken();
        $base = '/admin/merchants/' . $merchant->id;

        $this->assertSame(403, $this->changeStatus($token, $merchant->id, 'disabled')->getStatusCode());
        $this->assertSame(403, $this->jsonRequest('PUT', $base . '/level', $token, ['level_id' => $level->id])->getStatusCode());
        $this->assertSame(403, $this->setRateLimit($token, $merchant->id, 100)->getStatusCode());
        $this->assertSame(403, $this->jsonRequest('DELETE', $base . '/rate-limit', $token)->getStatusCode());

        $this->assertSame('active', $merchant->refresh()->status);
        $this->assertSame(0, MerchantRateLimit::where('merchant_id', $merchant->id)->count());
    }

    public function testNoTokenAtAllReturns401OnEveryRoute()
    {
        $merchant = $this->createMerchant('active');
        $base = '/admin/merchants/' . $merchant->id;

        foreach ([['POST', $base . '/status'], ['PUT', $base . '/level'], ['PUT', $base . '/rate-limit'], ['DELETE', $base . '/rate-limit']] as [$method, $path]) {
            $response = $this->client->request($method, $path);
            $this->assertSame(401, $response->getStatusCode(), $method . ' ' . $path);
        }
    }

    private function expectedDefaultRateLimit(): int
    {
        return (int) make(SystemSettingDao::class)->getValue(
            MerchantAdminService::DEFAULT_RATE_LIMIT_SETTING_KEY,
            MerchantAdminService::DEFAULT_RATE_LIMIT_PER_SECOND
        );
    }

    private function changeStatus(string $token, int $merchantId, mixed $status)
    {
        return $this->jsonRequest('POST', '/admin/merchants/' . $merchantId . '/status', $token, ['status' => $status]);
    }

    private function setRateLimit(string $token, int $merchantId, mixed $limit)
    {
        return $this->jsonRequest('PUT', '/admin/merchants/' . $merchantId . '/rate-limit', $token, [
            'limit_per_second' => $limit,
        ]);
    }

    private function jsonRequest(string $method, string $path, string $token, array $data = [])
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
        ];
        if ($method !== 'GET') {
            $options['json'] = $data;
        }

        return $this->client->request($method, $path, $options);
    }

    private function manageToken(): string
    {
        return $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));
    }

    private function viewToken(): string
    {
        return $this->loginAs($this->createAdminWithPermissions([self::VIEW_PERMISSION_CODE]));
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

    /**
     * 同 MerchantControllerTest::createAdminWithPermissions()：权限编码必须跟
     * #[RequiresPermission(...)] 字面量一致，只清理本用例真正新建的权限行。
     */
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
                ['module' => 'merchant', 'name' => $code, 'type' => 'action']
            );
            if ($permission->wasRecentlyCreated) {
                $this->permissionIds[] = $permission->id;
            }

            AdminRolePermission::create([
                'role_id' => $role->id,
                'permission_id' => $permission->id,
            ]);
        }

        $admin = AdminUser::create([
            'username' => 'admin_' . uniqid('', true),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'real_name' => 'Test Admin',
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $this->adminUserIds[] = $admin->id;

        return $admin;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createMerchant(string $status, array $overrides = []): Merchant
    {
        $merchant = Merchant::create(array_merge([
            'type' => 'company',
            'password' => password_hash('whatever', PASSWORD_BCRYPT),
            'status' => $status,
            'phone' => '185' . random_int(10000000, 99999999),
        ], $overrides));
        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createLevel(): MerchantLevel
    {
        // merchant_levels.name 最长 32 字符。
        $level = MerchantLevel::create(['name' => 'lv_' . bin2hex(random_bytes(8))]);
        $this->levelIds[] = $level->id;

        return $level;
    }
}
