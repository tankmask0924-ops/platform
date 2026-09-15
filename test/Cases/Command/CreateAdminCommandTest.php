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

namespace HyperfTest\Cases\Command;

use App\Command\CreateAdminCommand;
use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use App\Model\AdminUser;
use Hyperf\Testing\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * 这个项目里没有先例通过真实调用一个 `#[Command]` 类来测试，所以这里选择用
 * Symfony Console 自带的 CommandTester（`hyperf/command` 的 Command 基类本身就是
 * Symfony\Component\Console\Command\Command 的子类，这不是这个项目自己的约定，
 * 是 Symfony Console 本身就带的标准测试工具）真的跑一遍 execute()，而不是只反射
 * 检查类的存在——比 test/Cases/Service/Admin/AdminBootstrapServiceTest.php 里
 * 对业务逻辑的穷举覆盖更「薄」，只确认这一层胶水代码本身接线正确：命令能从容器
 * 解析出来、名字/选项定义符合预期、参数真的透传给了 Service、Service 抛出的校验
 * 异常被转换成了非 0 退出码而不是让异常直接冒出来、成功时打印用户名但绝不打印密码。
 * 业务分支覆盖（幂等性、重复用户名、密码长度等）留给 AdminBootstrapServiceTest。
 *
 * @internal
 * @coversNothing
 */
class CreateAdminCommandTest extends TestCase
{
    private const ROLE_NAME = 'super_admin';

    private const PERMISSION_CODE = 'merchant.view';

    private array $adminUserIds = [];

    private bool $roleOwnedByThisTest = false;

    private bool $permissionOwnedByThisTest = false;

    protected function tearDown(): void
    {
        foreach ($this->adminUserIds as $id) {
            AdminUser::destroy($id);
        }

        if ($this->roleOwnedByThisTest) {
            $role = AdminRole::where('name', self::ROLE_NAME)->first();
            if ($role) {
                AdminRolePermission::where('role_id', $role->id)->delete();
                AdminRole::destroy($role->id);
            }
        }

        if ($this->permissionOwnedByThisTest) {
            $permission = AdminPermission::where('code', self::PERMISSION_CODE)->first();
            if ($permission) {
                AdminRolePermission::where('permission_id', $permission->id)->delete();
                AdminPermission::destroy($permission->id);
            }
        }

        $this->adminUserIds = [];
        $this->roleOwnedByThisTest = false;
        $this->permissionOwnedByThisTest = false;

        parent::tearDown();
    }

    public function testCommandIsResolvableWithExpectedNameAndOptions()
    {
        $command = $this->getContainer()->get(CreateAdminCommand::class);

        $this->assertSame('admin:create', $command->getName());
        $this->assertTrue($command->getDefinition()->hasOption('username'));
        $this->assertTrue($command->getDefinition()->hasOption('password'));
        $this->assertTrue($command->getDefinition()->hasOption('real-name'));
    }

    public function testExecuteWithValidInputCreatesAdminAndPrintsUsernameButNotPassword()
    {
        $this->rememberOwnershipBeforeCall();
        $username = $this->uniqueUsername();
        $password = 'a-strong-password';

        $tester = new CommandTester($this->getContainer()->get(CreateAdminCommand::class));
        $exitCode = $tester->execute([
            '--username' => $username,
            '--password' => $password,
        ]);

        $admin = AdminUser::where('username', $username)->first();
        $this->assertNotNull($admin);
        $this->adminUserIds[] = $admin->id;

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString($username, $display);
        $this->assertStringNotContainsString($password, $display);
    }

    public function testExecuteWithTooShortPasswordFailsWithNonZeroExitCodeAndNoRowCreated()
    {
        $username = $this->uniqueUsername();

        $tester = new CommandTester($this->getContainer()->get(CreateAdminCommand::class));
        $exitCode = $tester->execute([
            '--username' => $username,
            '--password' => 'short',
        ]);

        $this->assertNotSame(0, $exitCode);
        $this->assertSame(0, AdminUser::where('username', $username)->count());
    }

    private function uniqueUsername(): string
    {
        return 'admin_' . uniqid('', true);
    }

    private function rememberOwnershipBeforeCall(): void
    {
        $this->roleOwnedByThisTest = ! AdminRole::where('name', self::ROLE_NAME)->exists();
        $this->permissionOwnedByThisTest = ! AdminPermission::where('code', self::PERMISSION_CODE)->exists();
    }
}
