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
use App\Model\Merchant;
use App\Model\MerchantLevel;
use App\Model\MerchantQualification;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * `GET /admin/merchants` 是 requirements.md 8.3「商户管理 - 商户列表」的第一个真实实现，
 * 也是 App\Middleware\AdminPermissionMiddleware（后台角色权限中间件，docs/modules.md
 * 第 1 节）第一次被真实 HTTP 派发端到端验证的地方。
 *
 * 详情 / 入驻审核通过 / 入驻审核驳回（requirements.md 4.1）是后续在此基础上加的三个动作：
 * 详情复用 'merchant.view'，审核通过/驳回用独立的 'merchant.review' 权限编码——
 * 下面既验证各自的业务行为，也专门验证两个权限编码是互相独立生效的
 * （拥有 merchant.view 不代表拥有 merchant.review）。
 *
 * @internal
 * @coversNothing
 */
class MerchantControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private const PERMISSION_CODE = 'merchant.view';

    private const REVIEW_PERMISSION_CODE = 'merchant.review';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $merchantIds = [];

    private array $qualificationIds = [];

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
        foreach ($this->qualificationIds as $id) {
            MerchantQualification::destroy($id);
        }
        foreach ($this->merchantIds as $id) {
            Merchant::destroy($id);
        }
        foreach ($this->levelIds as $id) {
            MerchantLevel::destroy($id);
        }
        $this->adminUserIds = [];
        $this->roleIds = [];
        $this->permissionIds = [];
        $this->merchantIds = [];
        $this->qualificationIds = [];
        $this->levelIds = [];

        parent::tearDown();
    }

    public function testAdminWithPermissionSeesTheList()
    {
        $merchant = Merchant::create([
            'type' => 'company',
            'password' => password_hash('whatever', PASSWORD_BCRYPT),
            'status' => 'active',
            'phone' => '186' . random_int(10000000, 99999999),
        ]);
        $this->merchantIds[] = $merchant->id;

        $token = $this->loginAs($this->createAdminWithPermissions([self::PERMISSION_CODE]));

        $response = $this->client->request('GET', '/admin/merchants', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('total', $body);
        $ids = array_column($body['data'], 'id');
        $this->assertContains($merchant->id, $ids);
    }

    public function testAdminWithoutPermissionGets403()
    {
        // 角色存在，但没有 grant merchant.view，验证「认证通过但权限不够」的路径。
        $token = $this->loginAs($this->createAdminWithoutAnyPermission());

        $response = $this->client->request('GET', '/admin/merchants', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * 证明 AdminAuthMiddleware 确实先于 AdminPermissionMiddleware 跑：没有 token 时
     * 应该在鉴权这一步就被拦下（401），而不是权限中间件先跑、拿不到 'admin'
     * attribute 时以 500 崩溃，也不是被静默放行。这是校验 #[Middleware] 注解
     * 书写顺序（AdminAuthMiddleware 在前、AdminPermissionMiddleware 在后，见
     * App\Controller\Admin\MerchantController 类注释）真的按预期生效的关键用例。
     */
    public function testNoTokenAtAllReturns401NotAPermissionError()
    {
        $response = $this->client->request('GET', '/admin/merchants');

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testDetailReturnsMerchantAndDecryptedQualification()
    {
        $merchant = $this->createMerchant('pending');
        $qualification = $this->createQualification($merchant->id, [
            'company_name' => '测试公司',
            'id_card_no' => '110101199001011234',
        ]);

        $token = $this->loginAs($this->createAdminWithPermissions([self::PERMISSION_CODE]));

        $response = $this->client->request('GET', '/admin/merchants/' . $merchant->id, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($merchant->id, $body['id']);
        $this->assertArrayHasKey('qualification', $body);
        $this->assertSame($qualification->company_name, $body['qualification']['company_name']);
        // 核心断言：管理后台详情接口必须回显解密后的明文身份证号，
        // 跟商户自己的商户后台（永远不回显）行为不同，见 App\Crypto\Encryptor 类注释。
        $this->assertSame('110101199001011234', $body['qualification']['id_card_no']);
        $this->assertSame('pending', $body['qualification']['status']);
    }

    public function testDetailForNonexistentMerchantReturns404()
    {
        $token = $this->loginAs($this->createAdminWithPermissions([self::PERMISSION_CODE]));

        $response = $this->client->request('GET', '/admin/merchants/999999999', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDetailWithoutPermissionGets403()
    {
        $merchant = $this->createMerchant('pending');
        $this->createQualification($merchant->id);

        $token = $this->loginAs($this->createAdminWithoutAnyPermission());

        $response = $this->client->request('GET', '/admin/merchants/' . $merchant->id, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testNoTokenOnDetailReturns401()
    {
        $merchant = $this->createMerchant('pending');

        $response = $this->client->request('GET', '/admin/merchants/' . $merchant->id);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testApproveActivatesMerchantAndApprovesQualification()
    {
        $merchant = $this->createMerchant('pending');
        $qualification = $this->createQualification($merchant->id);
        $level = $this->createLevel();
        $admin = $this->createAdminWithPermissions([self::REVIEW_PERMISSION_CODE]);
        $token = $this->loginAs($admin);

        $response = $this->client->request('POST', '/admin/merchants/' . $merchant->id . '/approve', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['level_id' => $level->id],
        ]);

        $this->assertSame(200, $response->getStatusCode());

        $merchant->refresh();
        $this->assertSame('active', $merchant->status);
        $this->assertSame($level->id, $merchant->level_id);

        $qualification->refresh();
        $this->assertSame('approved', $qualification->status);
        $this->assertSame($admin->id, $qualification->reviewed_by);
        $this->assertNotNull($qualification->reviewed_at);
    }

    public function testApproveWithInvalidLevelIdReturnsCleanErrorAndChangesNothing()
    {
        $merchant = $this->createMerchant('pending');
        $qualification = $this->createQualification($merchant->id);
        $token = $this->loginAs($this->createAdminWithPermissions([self::REVIEW_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/merchants/' . $merchant->id . '/approve', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['level_id' => 999999999],
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());

        $merchant->refresh();
        $qualification->refresh();
        $this->assertSame('pending', $merchant->status);
        $this->assertSame('pending', $qualification->status);
    }

    public function testApproveWhenMerchantNotPendingReturns409AndChangesNothing()
    {
        $merchant = $this->createMerchant('active');
        $qualification = $this->createQualification($merchant->id);
        $level = $this->createLevel();
        $token = $this->loginAs($this->createAdminWithPermissions([self::REVIEW_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/merchants/' . $merchant->id . '/approve', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['level_id' => $level->id],
        ]);

        $this->assertSame(409, $response->getStatusCode());

        $merchant->refresh();
        $qualification->refresh();
        $this->assertSame('active', $merchant->status);
        $this->assertSame('pending', $qualification->status);
    }

    /**
     * 关键用例：证明 'merchant.view' 和 'merchant.review' 是两个独立生效的权限编码，
     * 不是「有任意商户相关权限就能审核」——只授予 merchant.view 的管理员在
     * GET /admin/merchants 上应该放行，但在审核动作上必须被拒绝。
     */
    public function testApproveWithoutReviewPermissionGets403EvenWithViewPermission()
    {
        $merchant = $this->createMerchant('pending');
        $this->createQualification($merchant->id);
        $level = $this->createLevel();
        $token = $this->loginAs($this->createAdminWithPermissions([self::PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/merchants/' . $merchant->id . '/approve', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['level_id' => $level->id],
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testNoTokenOnApproveReturns401()
    {
        $merchant = $this->createMerchant('pending');

        $response = $this->client->request('POST', '/admin/merchants/' . $merchant->id . '/approve');

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testRejectStoresReasonAndRejectsMerchantAndQualification()
    {
        $merchant = $this->createMerchant('pending');
        $qualification = $this->createQualification($merchant->id);
        $admin = $this->createAdminWithPermissions([self::REVIEW_PERMISSION_CODE]);
        $token = $this->loginAs($admin);

        $response = $this->client->request('POST', '/admin/merchants/' . $merchant->id . '/reject', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['reason' => '营业执照信息与工商登记不符'],
        ]);

        $this->assertSame(200, $response->getStatusCode());

        $merchant->refresh();
        $this->assertSame('rejected', $merchant->status);

        $qualification->refresh();
        $this->assertSame('rejected', $qualification->status);
        $this->assertSame('营业执照信息与工商登记不符', $qualification->reject_reason);
        $this->assertSame($admin->id, $qualification->reviewed_by);
        $this->assertNotNull($qualification->reviewed_at);
    }

    public function testRejectWithMissingReasonReturnsCleanError()
    {
        $merchant = $this->createMerchant('pending');
        $qualification = $this->createQualification($merchant->id);
        $token = $this->loginAs($this->createAdminWithPermissions([self::REVIEW_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/merchants/' . $merchant->id . '/reject', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['reason' => ''],
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());

        $merchant->refresh();
        $qualification->refresh();
        $this->assertSame('pending', $merchant->status);
        $this->assertSame('pending', $qualification->status);
    }

    public function testRejectWhenMerchantNotPendingReturns409AndChangesNothing()
    {
        $merchant = $this->createMerchant('active');
        $qualification = $this->createQualification($merchant->id);
        $token = $this->loginAs($this->createAdminWithPermissions([self::REVIEW_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/merchants/' . $merchant->id . '/reject', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['reason' => '随便什么原因'],
        ]);

        $this->assertSame(409, $response->getStatusCode());

        $merchant->refresh();
        $qualification->refresh();
        $this->assertSame('active', $merchant->status);
        $this->assertSame('pending', $qualification->status);
    }

    public function testRejectWithoutReviewPermissionGets403EvenWithViewPermission()
    {
        $merchant = $this->createMerchant('pending');
        $this->createQualification($merchant->id);
        $token = $this->loginAs($this->createAdminWithPermissions([self::PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/merchants/' . $merchant->id . '/reject', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['reason' => '随便什么原因'],
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testNoTokenOnRejectReturns401()
    {
        $merchant = $this->createMerchant('pending');

        $response = $this->client->request('POST', '/admin/merchants/' . $merchant->id . '/reject');

        $this->assertSame(401, $response->getStatusCode());
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
     * $codes 里的每个编码都必须跟对应 Controller 方法上 #[RequiresPermission(...)]
     * 声明的字面量完全一致才能通过校验，不能像其他测试那样拼 uniqid 后缀。用
     * firstOrCreate 而不是 create——admin_permissions.code 有唯一约束，多个用例/
     * 多次运行都要用同一行（比如 'merchant.view'），这里只在真的新建了这一行时
     * 才把它加入 tearDown 清理列表，避免把可能已经存在的同名权限行删掉。
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

        return $this->createAdmin($role->id);
    }

    private function createAdminWithoutAnyPermission(): AdminUser
    {
        $role = AdminRole::create([
            'name' => 'role_' . uniqid('', true),
            'is_system' => false,
        ]);
        $this->roleIds[] = $role->id;

        return $this->createAdmin($role->id);
    }

    private function createAdmin(int $roleId): AdminUser
    {
        $admin = AdminUser::create([
            'username' => 'admin_' . uniqid('', true),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'real_name' => 'Test Admin',
            'role_id' => $roleId,
            'status' => 'active',
        ]);

        $this->adminUserIds[] = $admin->id;

        return $admin;
    }

    private function createMerchant(string $status): Merchant
    {
        $merchant = Merchant::create([
            'type' => 'company',
            'password' => password_hash('whatever', PASSWORD_BCRYPT),
            'status' => $status,
            'phone' => '186' . random_int(10000000, 99999999),
        ]);
        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createQualification(int $merchantId, array $overrides = []): MerchantQualification
    {
        $idCardNo = array_key_exists('id_card_no', $overrides) ? $overrides['id_card_no'] : '110101199001011234';
        unset($overrides['id_card_no']);

        $qualification = MerchantQualification::create(array_merge([
            'merchant_id' => $merchantId,
            'type' => 'company',
            'company_name' => '测试公司',
            'business_license_no' => 'BL' . uniqid(),
            'legal_person_name' => '张三',
            'contact_name' => '李四',
            'contact_phone' => '186' . random_int(10000000, 99999999),
            'id_card_no' => make(Encryptor::class)->encrypt($idCardNo),
            'status' => 'pending',
        ], $overrides));
        $this->qualificationIds[] = $qualification->id;

        return $qualification;
    }

    private function createLevel(): MerchantLevel
    {
        $level = MerchantLevel::create([
            'name' => 'level_' . uniqid('', true),
        ]);
        $this->levelIds[] = $level->id;

        return $level;
    }
}
