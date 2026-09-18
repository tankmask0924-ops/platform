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

use App\Dao\AdminOperationLogDao;
use App\Dao\AdminRoleDao;
use App\Dao\AdminUserDao;
use App\Model\AdminRole;
use App\Model\AdminUser;
use App\Service\AbstractService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统设置 - 管理员账号（requirements.md 8.3）：列表、新建、改姓名/角色、启用禁用、重置密码。
 *
 * 防止把自己或整个后台锁在外面：
 * - 不能禁用自己、不能改自己的角色（自己的密码在右上角"修改密码"里改）；
 * - 至少保留一个启用状态的超级管理员；
 * - 只有超级管理员能创建超级管理员、修改超级管理员账号，否则有"管理员账号管理"
 *   权限的人可以给自己建一个超管账号绕开权限。
 * 改密码后该账号之前的登录全部失效（见 AdminAuthMiddleware）。
 */
class AdminUserAdminService extends AbstractService
{
    public const MIN_PASSWORD_LENGTH = 8;

    private const MODULE = 'system';

    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    #[Inject]
    protected AdminRoleDao $adminRoleDao;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 15)));

        $builder = $this->adminUserDao->newQuery();
        $keyword = trim((string) ($query['keyword'] ?? ''));
        if ($keyword !== '') {
            $builder->where(fn ($q) => $q->where('username', 'like', "%{$keyword}%")->orWhere('real_name', 'like', "%{$keyword}%"));
        }
        if (! empty($query['role_id'])) {
            $builder->where('role_id', (int) $query['role_id']);
        }
        if (in_array($query['status'] ?? null, ['active', 'disabled'], true)) {
            $builder->where('status', $query['status']);
        }

        $total = (clone $builder)->count();
        $users = $builder->orderBy('id')->forPage($page, $perPage)->get();
        $roles = $this->adminRoleDao->newQuery()->whereIn('id', $users->pluck('role_id')->unique()->all())->get()->keyBy('id');

        return [
            'data' => $users->map(fn (AdminUser $user) => $this->format($user, $roles->get($user->role_id)))->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(AdminUser $operator, array $data, ?string $ip): array
    {
        $username = trim((string) ($data['username'] ?? ''));
        $realName = trim((string) ($data['real_name'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if (! preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $username)) {
            throw new HttpException(422, '用户名为 3~64 位字母、数字或 _ . @ -');
        }
        if ($this->adminUserDao->findByUsername($username)) {
            throw new HttpException(422, "用户名「{$username}」已存在");
        }
        $this->validatePassword($password);
        $role = $this->assignableRole($operator, $data['role_id'] ?? null);

        $user = $this->adminUserDao->create([
            'username' => $username,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'real_name' => $realName !== '' ? mb_substr($realName, 0, 64) : $username,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $after = $this->format($user, $role);
        $this->operationLogDao->record($operator->id, self::MODULE, 'create_admin_user', 'admin_user', $user->id, null, $after, $ip);

        return $after;
    }

    /**
     * 改姓名、角色。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(AdminUser $operator, int $id, array $data, ?string $ip): array
    {
        $user = $this->findOrFail($id);
        $this->guardEditable($operator, $user);

        return Db::transaction(function () use ($operator, $user, $data, $ip) {
            $before = $this->format($user, $this->adminRoleDao->find($user->role_id));
            $attrs = [];

            if (array_key_exists('real_name', $data)) {
                $realName = trim((string) $data['real_name']);
                if ($realName === '') {
                    throw new HttpException(422, '姓名不能为空');
                }
                $attrs['real_name'] = mb_substr($realName, 0, 64);
            }

            if (array_key_exists('role_id', $data) && (int) $data['role_id'] !== $user->role_id) {
                if ($user->id === $operator->id) {
                    throw new HttpException(422, '不能修改自己的角色');
                }
                $role = $this->assignableRole($operator, $data['role_id']);
                $attrs['role_id'] = $role->id;
                if ($this->isSuperAdmin($user) && $user->status === 'active') {
                    $this->guardNotLastActiveSuperAdmin($user);
                }
            }

            if ($attrs !== []) {
                $this->adminUserDao->update($user->id, $attrs);
            }
            $user = $this->findOrFail($user->id);
            $after = $this->format($user, $this->adminRoleDao->find($user->role_id));
            $this->operationLogDao->record($operator->id, self::MODULE, 'update_admin_user', 'admin_user', $user->id, $before, $after, $ip);

            return $after;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function changeStatus(AdminUser $operator, int $id, mixed $status, ?string $ip): array
    {
        if (! in_array($status, ['active', 'disabled'], true)) {
            throw new HttpException(422, 'status 只能是 active 或 disabled');
        }
        $user = $this->findOrFail($id);
        $this->guardEditable($operator, $user);
        if ($status === $user->status) {
            return $this->format($user, $this->adminRoleDao->find($user->role_id));
        }
        if ($status === 'disabled' && $user->id === $operator->id) {
            throw new HttpException(422, '不能禁用自己');
        }

        Db::transaction(function () use ($operator, $user, $status, $ip) {
            if ($status === 'disabled' && $this->isSuperAdmin($user)) {
                $this->guardNotLastActiveSuperAdmin($user);
            }
            $this->adminUserDao->update($user->id, ['status' => $status]);
            $this->operationLogDao->record(
                $operator->id,
                self::MODULE,
                $status === 'active' ? 'enable_admin_user' : 'disable_admin_user',
                'admin_user',
                $user->id,
                ['status' => $user->status],
                ['status' => $status],
                $ip
            );
        });

        return $this->format($this->findOrFail($user->id), $this->adminRoleDao->find($user->role_id));
    }

    /**
     * 其他管理员帮忙重置密码。重置自己的密码走"修改密码"（要验证原密码）。
     */
    public function resetPassword(AdminUser $operator, int $id, mixed $password, ?string $ip): void
    {
        $user = $this->findOrFail($id);
        if ($user->id === $operator->id) {
            throw new HttpException(422, '修改自己的密码请使用右上角的"修改密码"');
        }
        $this->guardEditable($operator, $user);
        $this->validatePassword((string) $password);

        $this->adminUserDao->update($user->id, ['password' => password_hash((string) $password, PASSWORD_BCRYPT)]);
        // 不记密码本身
        $this->operationLogDao->record($operator->id, self::MODULE, 'reset_admin_password', 'admin_user', $user->id, null, ['username' => $user->username], $ip);
    }

    private function findOrFail(int $id): AdminUser
    {
        $user = $this->adminUserDao->find($id);
        if (! $user) {
            throw new HttpException(404, '管理员不存在');
        }

        return $user;
    }

    private function validatePassword(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new HttpException(422, '密码长度至少 ' . self::MIN_PASSWORD_LENGTH . ' 位');
        }
    }

    private function assignableRole(AdminUser $operator, mixed $roleId): AdminRole
    {
        $role = is_numeric($roleId) ? $this->adminRoleDao->find((int) $roleId) : null;
        if (! $role) {
            throw new HttpException(422, '请选择有效的角色');
        }
        if ($role->name === AdminBootstrapService::SUPER_ADMIN_ROLE_NAME && ! $this->isSuperAdmin($operator)) {
            throw new HttpException(403, '只有超级管理员可以分配超级管理员角色');
        }

        return $role;
    }

    private function guardEditable(AdminUser $operator, AdminUser $target): void
    {
        if ($this->isSuperAdmin($target) && ! $this->isSuperAdmin($operator)) {
            throw new HttpException(403, '只有超级管理员可以修改超级管理员账号');
        }
    }

    private function guardNotLastActiveSuperAdmin(AdminUser $user): void
    {
        $others = $this->adminUserDao->newQuery()
            ->where('role_id', $user->role_id)
            ->where('status', 'active')
            ->where('id', '!=', $user->id)
            ->lockForUpdate()
            ->count();
        if ($others === 0) {
            throw new HttpException(422, '至少要保留一个启用的超级管理员');
        }
    }

    private function isSuperAdmin(AdminUser $user): bool
    {
        return $this->adminRoleDao->find($user->role_id)?->name === AdminBootstrapService::SUPER_ADMIN_ROLE_NAME;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(AdminUser $user, ?AdminRole $role): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'real_name' => $user->real_name,
            'role_id' => $user->role_id,
            'role_name' => $role?->name,
            'status' => $user->status,
            'last_login_at' => $user->last_login_at?->toDateTimeString(),
            'created_at' => $user->created_at?->toDateTimeString(),
        ];
    }
}
