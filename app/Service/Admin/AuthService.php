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

use App\Auth\AdminJwtGuard;
use App\Dao\AdminRoleDao;
use App\Dao\AdminRolePermissionDao;
use App\Dao\AdminUserDao;
use App\Model\AdminUser;
use App\Service\AbstractService;
use Carbon\Carbon;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统管理后台（web/admin）账户登录（requirements.md 8.3）。
 *
 * 跟 App\Service\Merchant\AuthService 是同一个鉴权形状（HttpException 401/403 +
 * JWT 签发），但没有对应的 register：管理员账号不是自助注册的，requirements.md 8.3
 * 把「系统设置：管理员账号」列为由其他管理员管理的功能，本任务没有建这个管理端
 * CRUD（超出范围，见类注释底部的已知缺口），所以目前没有任何 API 能创建一个真实
 * admin_users 行——测试里都是直接用 AdminUserDao/Model 插数据，生产环境要先有这样
 * 一条种子数据（后续需要一个 #[Command] CLI 种子命令，或者补一个管理员管理 CRUD
 * 接口，两者都还没做）。
 */
class AuthService extends AbstractService
{
    private const GENERIC_LOGIN_FAIL_MESSAGE = '账号或密码错误';

    #[Inject]
    protected AdminUserDao $adminUserDao;

    #[Inject]
    protected AdminJwtGuard $tokenGuard;

    #[Inject]
    protected AdminRoleDao $adminRoleDao;

    #[Inject]
    protected AdminRolePermissionDao $adminRolePermissionDao;

    /**
     * @return array{token: string, username: string}
     */
    public function login(string $username, string $password): array
    {
        $username = trim($username);

        $adminUser = $this->adminUserDao->findByUsername($username);

        if (! $adminUser || ! password_verify($password, $adminUser->password)) {
            // 账号不存在 / 密码错误共用同一条消息，防止枚举出哪些用户名存在，
            // 跟 App\Service\Merchant\AuthService::login() 同样的理由。
            throw new HttpException(401, self::GENERIC_LOGIN_FAIL_MESSAGE);
        }

        if ($adminUser->status === 'disabled') {
            // 密码本身是对的，只是账号被禁用，跟「账号或密码错误」是不同性质的失败，
            // 用 403 而不是 401，同 App\Service\Merchant\AuthService::login() 的理由。
            throw new HttpException(403, '账号已被禁用');
        }

        $token = $this->tokenGuard->issue($adminUser->id, $adminUser->password);

        $adminUser->last_login_at = Carbon::now();
        $adminUser->save();

        return [
            'token' => $token,
            'username' => $adminUser->username,
        ];
    }

    /**
     * 当前登录管理员的资料和权限编码，前端据此隐藏没有权限的菜单和按钮。
     *
     * @return array<string, mixed>
     */
    public function me(AdminUser $admin): array
    {
        $role = $this->adminRoleDao->find($admin->role_id);

        return [
            'id' => $admin->id,
            'username' => $admin->username,
            'real_name' => $admin->real_name,
            'role_id' => $admin->role_id,
            'role_name' => $role?->name,
            'is_super_admin' => $role?->name === AdminBootstrapService::SUPER_ADMIN_ROLE_NAME,
            'status' => $admin->status,
            'permissions' => $this->adminRolePermissionDao->codesForRole($admin->role_id),
        ];
    }

    /**
     * 修改自己的密码，返回新 token（旧 token 全部失效）。
     *
     * @return array{token: string}
     */
    public function changePassword(AdminUser $admin, string $oldPassword, string $newPassword): array
    {
        if (! password_verify($oldPassword, $admin->password)) {
            throw new HttpException(422, '原密码不正确');
        }
        if (mb_strlen($newPassword) < AdminUserAdminService::MIN_PASSWORD_LENGTH) {
            throw new HttpException(422, '新密码长度至少 ' . AdminUserAdminService::MIN_PASSWORD_LENGTH . ' 位');
        }
        if ($oldPassword === $newPassword) {
            throw new HttpException(422, '新密码不能和原密码相同');
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $this->adminUserDao->update($admin->id, ['password' => $hash]);

        return ['token' => $this->tokenGuard->issue($admin->id, $hash)];
    }
}
