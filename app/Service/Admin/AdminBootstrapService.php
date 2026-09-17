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

use App\Dao\AdminPermissionDao;
use App\Dao\AdminRoleDao;
use App\Dao\AdminRolePermissionDao;
use App\Dao\AdminUserDao;
use App\Model\AdminRole;
use App\Model\AdminUser;
use App\Service\AbstractService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use InvalidArgumentException;

/**
 * 管理后台账号的「首次开通」引导逻辑（requirements.md 8.3），由 App\Command\CreateAdminCommand
 * 这个一次性 CLI 命令调用——管理员账号不是自助注册的（见 App\Service\Admin\AuthService
 * 类注释），生产环境需要先有这样一条种子数据，这个 Service 就是那条种子数据的落地方式。
 *
 * 设计成可安全重复执行（操作员可能会再跑一次去建第二个管理员账号，或者在给一个新接口
 * 加上 #[RequiresPermission] 之后重新授权）：角色、权限、角色-权限授权关系都是
 * find-or-create / 先查后插，不会在重复执行时报错或产生重复数据；只有 AdminUser 本身
 * 按 username 唯一，重复用户名会被拒绝而不是覆盖或再插一条。
 */
class AdminBootstrapService extends AbstractService
{
    /**
     * 密码最低长度，跟 App\Service\Merchant\AuthService::MIN_PASSWORD_LENGTH 保持一致
     * （同一套「8 位以上，不做复杂度校验」的判断，两个后台的密码强度要求没有理由不一样）。
     */
    private const MIN_PASSWORD_LENGTH = 8;

    private const SUPER_ADMIN_ROLE_NAME = 'super_admin';

    /**
     * 当前生产代码里真实会被 App\Middleware\AdminPermissionMiddleware 检查到的
     * 权限编码全集——即所有实际挂了 #[App\Annotation\RequiresPermission(...)] 的
     * Controller 方法对应的编码。截至本任务，App\Controller\Admin\MerchantController
     * 挂了 #[RequiresPermission('merchant.view')]（列表 + 详情 + 资金流水）、
     * #[RequiresPermission('merchant.review')]（入驻审核通过/驳回）和
     * #[RequiresPermission('merchant.balance_adjust')]（手动调账，requirements.md
     * 4.3，独立权限编码——财务改余额是有实际资金影响的动作，不跟"查看"共用一档），
     * App\Controller\Admin\SupplierController 挂了 #[RequiresPermission('supplier.view')]
     * （列表 + 详情）和 #[RequiresPermission('supplier.manage')]（新建/修改/启停），
     * App\Controller\Admin\ProductMappingController 挂了
     * #[RequiresPermission('product_mapping.view')]（列表）和
     * #[RequiresPermission('product_mapping.manage')]（新建 + 改价/优先级/状态/
     * 详情更新），App\Controller\Admin\RechargeRequestController 挂了
     * #[RequiresPermission('recharge.view')]（充值申请列表）和
     * #[RequiresPermission('recharge.manage')]（充值申请审核通过/驳回），
     * requirements.md 8.3 列的其它管理后台模块都还没有对应的 Controller/Service，
     * 为它们现在就编一个权限编码是没有意义的占位（没有任何中间件会去检查它，
     * 加了也白加）。
     *
     * **重要（容易忘的操作陷阱）**：以后每在某个 Controller 方法上新增一个
     * `#[RequiresPermission('some.new.code')]`，必须同步把 'some.new.code' 加进下面
     * 这个数组，并重新跑一次 `admin:create`（或者手动把新权限授权给已有角色）。否则
     * 'some.new.code' 永远不会出现在 admin_permissions 表里，
     * AdminRolePermissionDao::roleHasPermission() 对它的查询永远查不到匹配行，
     * AdminPermissionMiddleware 会把这当成「没有权限」直接 403 拒绝——包括这里创建的
     * super_admin 角色在内的所有角色都会被拒绝，即使 super_admin 在直觉上「应该」
     * 无所不能。这个失败是「closed（拒绝）」而不是报错，很容易被误判成别的 bug。
     *
     * @var array<int, array{code: string, module: string, name: string, type: string}>
     */
    private const KNOWN_PERMISSIONS = [
        ['code' => 'merchant.view', 'module' => 'merchant', 'name' => '商户列表查看', 'type' => 'action'],
        ['code' => 'merchant.review', 'module' => 'merchant', 'name' => '商户入驻审核', 'type' => 'action'],
        ['code' => 'merchant.balance_adjust', 'module' => 'merchant', 'name' => '商户余额手动调账', 'type' => 'action'],
        ['code' => 'merchant.manage', 'module' => 'merchant', 'name' => '商户启用禁用/等级/限流管理', 'type' => 'action'],
        ['code' => 'supplier.view', 'module' => 'supplier', 'name' => '供应商配置查看', 'type' => 'action'],
        ['code' => 'supplier.manage', 'module' => 'supplier', 'name' => '供应商配置管理', 'type' => 'action'],
        ['code' => 'product_mapping.view', 'module' => 'product_mapping', 'name' => '商品映射查看', 'type' => 'action'],
        ['code' => 'product_mapping.manage', 'module' => 'product_mapping', 'name' => '商品映射管理', 'type' => 'action'],
        ['code' => 'recharge.view', 'module' => 'recharge', 'name' => '充值申请查看', 'type' => 'action'],
        ['code' => 'recharge.manage', 'module' => 'recharge', 'name' => '充值申请审核', 'type' => 'action'],
        ['code' => 'merchant_level.view', 'module' => 'merchant_level', 'name' => '商户等级查看', 'type' => 'action'],
        ['code' => 'merchant_level.manage', 'module' => 'merchant_level', 'name' => '商户等级管理', 'type' => 'action'],
        ['code' => 'product.view', 'module' => 'product', 'name' => '本地商品查看', 'type' => 'action'],
        ['code' => 'product.manage', 'module' => 'product', 'name' => '本地商品管理', 'type' => 'action'],
    ];

    #[Inject]
    protected AdminUserDao $adminUserDao;

    #[Inject]
    protected AdminRoleDao $adminRoleDao;

    #[Inject]
    protected AdminPermissionDao $adminPermissionDao;

    #[Inject]
    protected AdminRolePermissionDao $adminRolePermissionDao;

    /**
     * 创建一个拥有当前已知全部权限的超级管理员账号，find-or-create 角色/权限/授权关系，
     * 只有最终的 AdminUser 行是本次调用真正新建的东西。
     */
    public function createSuperAdmin(string $username, string $password, ?string $realName = null): AdminUser
    {
        $username = trim($username);
        $realName = $this->resolveRealName($realName, $username);

        $this->validate($username, $password);

        return Db::transaction(function () use ($username, $password, $realName) {
            $role = $this->ensureSuperAdminRole();
            $this->ensureRoleHasKnownPermissions($role);

            return $this->adminUserDao->create([
                'username' => $username,
                'password' => password_hash($password, PASSWORD_BCRYPT),
                'real_name' => $realName,
                'role_id' => $role->id,
                'status' => 'active',
            ]);
        });
    }

    private function validate(string $username, string $password): void
    {
        if ($username === '') {
            throw new InvalidArgumentException('username 不能为空');
        }

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('密码长度至少 ' . self::MIN_PASSWORD_LENGTH . ' 位');
        }

        if ($this->adminUserDao->findByUsername($username)) {
            throw new InvalidArgumentException("用户名「{$username}」已存在");
        }
    }

    private function resolveRealName(?string $realName, string $username): string
    {
        $realName = $realName !== null ? trim($realName) : '';

        return $realName !== '' ? $realName : $username;
    }

    private function ensureSuperAdminRole(): AdminRole
    {
        $role = $this->adminRoleDao->newQuery()->where('name', self::SUPER_ADMIN_ROLE_NAME)->first();
        if ($role) {
            return $role;
        }

        return $this->adminRoleDao->create([
            'name' => self::SUPER_ADMIN_ROLE_NAME,
            'is_system' => true,
            'remark' => '超级管理员，拥有系统当前已知的全部权限（见 AdminBootstrapService::KNOWN_PERMISSIONS）',
        ]);
    }

    private function ensureRoleHasKnownPermissions(AdminRole $role): void
    {
        foreach (self::KNOWN_PERMISSIONS as $definition) {
            $permission = $this->adminPermissionDao->findByCode($definition['code']);
            if (! $permission) {
                $permission = $this->adminPermissionDao->create($definition);
            }

            // 先查后插而不是直接 create() 再 catch 唯一约束冲突：这个方法只会被
            // CreateAdminCommand 这个单进程、串行执行的 CLI 命令调用，不存在并发写入
            // 同一个 (role_id, permission_id) 的场景，先查后插足够幂等，也比 try/catch
            // 唯一约束异常更直白。
            $alreadyGranted = $this->adminRolePermissionDao->newQuery()
                ->where('role_id', $role->id)
                ->where('permission_id', $permission->id)
                ->exists();

            if (! $alreadyGranted) {
                $this->adminRolePermissionDao->create([
                    'role_id' => $role->id,
                    'permission_id' => $permission->id,
                ]);
            }
        }
    }
}
