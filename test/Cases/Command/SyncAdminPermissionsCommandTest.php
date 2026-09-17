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

use App\Command\SyncAdminPermissionsCommand;
use App\Service\Admin\AdminBootstrapService;
use Hyperf\Testing\TestCase;
use Mockery;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * admin:sync-permissions 的胶水层冒烟测试，写法同 CreateAdminCommandTest。Service 换成
 * mock，不动共享库里真实的超级管理员角色；同步逻辑本身见 AdminBootstrapServiceTest。
 *
 * @internal
 * @coversNothing
 */
class SyncAdminPermissionsCommandTest extends TestCase
{
    public function testCommandReportsGrantedCount()
    {
        $service = Mockery::mock(AdminBootstrapService::class);
        $service->shouldReceive('syncSuperAdminPermissions')->times(3)->andReturn(3, 0, null);

        $command = new SyncAdminPermissionsCommand($service);
        $this->assertSame('admin:sync-permissions', $command->getName());

        $tester = new CommandTester($command);

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('补充 3 个权限', $tester->getDisplay());

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('无需补充', $tester->getDisplay());

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('admin:create', $tester->getDisplay());
    }

    public function testCommandIsResolvableFromContainer()
    {
        $this->assertInstanceOf(SyncAdminPermissionsCommand::class, $this->getContainer()->get(SyncAdminPermissionsCommand::class));
    }
}
