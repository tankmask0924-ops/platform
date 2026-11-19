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

namespace App\Command\Migration;

use Hyperf\Command\Annotation\Command;
use Hyperf\Database\Commands\Migrations\InstallCommand as BaseInstallCommand;

/**
 * `migrate:install`：建 migrations 记录表，migrate 首次运行时会自己调用。
 *
 * 框架的迁移命令由 hyperf/devtool（require-dev）注册，生产环境 `composer install --no-dev`
 * 后就没有了，所以在这里包一层子类注册。开发环境两边都注册时同名命令后者覆盖前者，不冲突。
 * 只注册部署需要的 install / migrate / status，migrate:fresh、migrate:reset 这类会删表的命令
 * 故意不注册，避免在生产库上误操作。
 */
#[Command]
class InstallCommand extends BaseInstallCommand
{
}
