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

use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use App\Model\AdminUser;
use App\Model\Merchant;
use HyperfTest\HttpTestCase;

/**
 * `GET /admin/merchants` 是 requirements.md 8.3「商户管理 - 商户列表」的第一个真实实现，
 * 也是 App\Middleware\AdminPermissionMiddleware（后台角色权限中间件，docs/modules.md
 * 第 1 节）第一次被真实 HTTP 派发端到端验证的地方。
 *
 * @internal
 * @coversNothing
 */
class MerchantControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private const PERMISSION_CODE = 'merchant.view';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $merchantIds = [];

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
            Merchant::destroy($id);
        }
        $this->adminUserIds = [];
        $this->roleIds = [];
        $this->permissionIds = [];
        $this->merchantIds = [];

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

        $token = $this->loginAs($this->createAdminWithPermission(self::PERMISSION_CODE));

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
     * $code 必须跟 App\Controller\Admin\MerchantController::index() 上
     * #[RequiresPermission('merchant.view')] 声明的字面量完全一致才能通过校验，
     * 不能像其他测试那样拼 uniqid 后缀。用 firstOrCreate 而不是 create——
     * admin_permissions.code 有唯一约束，多个用例/多次运行都要用同一个
     * 'merchant.view' 行，这里只在真的新建了这一行时才把它加入 tearDown 清理列表，
     * 避免把可能已经存在的同名权限行删掉。
     */
    private function createAdminWithPermission(string $code): AdminUser
    {
        $role = AdminRole::create([
            'name' => 'role_' . uniqid('', true),
            'is_system' => false,
        ]);
        $this->roleIds[] = $role->id;

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
}
