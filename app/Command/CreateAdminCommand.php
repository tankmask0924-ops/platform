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

namespace App\Command;

use App\Service\Admin\AdminBootstrapService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;

/**
 * 管理后台唯一的账号开通入口：`docker exec pf php bin/hyperf.php admin:create
 * --username=xxx --password=xxx`，运维手动跑一次，建出第一个能登录 web/admin 的
 * 超级管理员账号。管理员账号不是自助注册的（requirements.md 8.3），在这个命令之前
 * 没有任何 API/工具能建出一条真实 admin_users 记录，见 App\Service\Admin\AuthService
 * 类注释里记录的这个已知缺口。
 *
 * 这个类本身只负责「解析命令行输入 -> 调用 Service -> 格式化输出」，真正的
 * find-or-create 角色/权限/授权 + 建用户逻辑都在 App\Service\Admin\AdminBootstrapService
 * 里，这样不需要真的跑一次控制台命令也能测（见该 Service 类注释）。
 *
 * 可安全重复执行：重复用户名会被 Service 拒绝并以非 0 退出码失败，不会覆盖或
 * 建出重复账号；角色/权限/授权关系是 find-or-create，多次运行不会重复插入。
 */
#[Command]
class CreateAdminCommand extends HyperfCommand
{
    public function __construct(private readonly AdminBootstrapService $adminBootstrapService)
    {
        parent::__construct('admin:create');
    }

    public function configure(): void
    {
        $this->setDescription('创建管理后台的超级管理员账号（幂等，可重复执行以创建更多账号）')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, '登录用户名')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, '登录密码，至少 8 位')
            ->addOption('real-name', null, InputOption::VALUE_OPTIONAL, '显示名称，缺省时等于 username');

        parent::configure();
    }

    public function handle(): int
    {
        $username = (string) $this->input->getOption('username');
        $password = (string) $this->input->getOption('password');
        $realNameOption = $this->input->getOption('real-name');
        $realName = is_string($realNameOption) ? $realNameOption : null;

        try {
            $adminUser = $this->adminBootstrapService->createSuperAdmin($username, $password, $realName);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // 故意不打印密码——运维自己刚敲过一遍，没有必要在终端历史/日志里再留一份明文。
        $this->info("管理员账号创建成功：{$adminUser->username}");

        return self::SUCCESS;
    }
}
