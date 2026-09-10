---
name: hyperf-conventions
description: Coding standards and workflow conventions for this Hyperf PHP project (platform). Use whenever writing, editing, or reviewing PHP code in this repo, or running tests/lint/static analysis.
---

# Hyperf 项目规范（platform）

这个项目是基于 `hyperf/hyperf-skeleton` 的 Hyperf 3.1 项目，PHP 8.4 + Swoole，跑在 Docker 容器（`pf`）里。

## 本机环境

- 本机**没有安装 PHP/Composer**，所有 PHP 相关命令（composer、phpunit、php-cs-fixer、phpstan）都必须通过容器执行：

```bash
docker compose up -d          # 启动/确保容器在跑
docker exec pf <command>      # 在容器里执行命令
```

- 不要尝试直接在宿主机跑 `php` / `composer`，会因为没装而失败。

## 代码风格

- 严格类型：所有文件开头必须有 `declare(strict_types=1);`
- 字符串统一用单引号（`single_quote` 规则）
- 数组用短语法 `[]`，不用 `array()`
- import 按字母顺序排序（`ordered_imports`），不要用未使用的 `use`
- 规则来源：[.php-cs-fixer.php](.php-cs-fixer.php)（基于 `@PSR2` + `@Symfony` + `@DoctrineAnnotation` + `@PhpCsFixer`）

修改代码后，提交前先跑自动修复：

```bash
docker exec pf composer cs-fix
```

**注意 1**：`cs-fix` 是全项目扫描，会顺带把没改过的旧文件也格式化。跑完之后一定要 `git status` / `git diff` 看一下，把跟这次任务无关的文件改动 `git checkout -- <file>` 撤销掉，只保留真正相关的改动。

**注意 2（治本）**：项目开了 `global_namespace_import` 规则，凡是代码里裸写完整类名（比如 `\Hyperf\Xxx\Yyy::class` 或 `Hyperf\Xxx\Yyy::class`）而没有对应 `use` 导入的，`cs-fix` 会自动帮你把它转成顶部 `use` 语句——但这个转换动作跟 `header_comment` 规则冲突，会把文件头版权注释复制成两份（已知 bug）。**预防办法：自己写代码时就把 `use` 导入写好，不要留一个裸的完整类名给 cs-fix 去处理**，这样它不需要插入新 `use` 语句，就不会触发这个 bug。`config/autoload/*.php` 这类配置文件尤其容易中招，因为返回的是数组字面量，很容易图省事直接写完整类名。

## 静态分析

```bash
docker exec pf composer analyse
```

- 配置见 [phpstan.neon.dist](phpstan.neon.dist)，级别 `level: 0`，扫描 `app/` 和 `config/`
- 新增代码不要主动提高 phpstan level 或改动这个配置，除非用户明确要求

## 目录结构约定

分层架构：`Controller → Service → Dao → Model`，职责严格分开，不要跳层直接在 Controller 里写数据库查询。

- `app/Controller/` — 控制器，只负责接收请求、调用 Service、返回响应，需继承 `App\Controller\AbstractController`，**不写业务逻辑和数据库查询**
- `app/Service/` — 业务逻辑层，编排业务流程、组合多个 Dao，需继承 `App\Service\AbstractService`
- `app/Dao/` — 数据访问层，封装对单个 Model 的增删改查，需继承 `App\Dao\AbstractDao` 并设置 `protected string $model` 指向对应 Model 类；不写业务逻辑
- `app/Model/` — 数据库模型，继承 `App\Model\Model`
- `app/Listener/` — 事件监听器
- `app/Exception/Handler/` — 异常处理器
- `config/autoload/` — 各类自动加载配置（databases、redis、middlewares 等），新增中间件/监听器/依赖注入要在这里注册，而不是散落在业务代码里
- PSR-4 命名空间：`App\` 映射到 `app/`（见 [composer.json](composer.json)）

新增一个业务模块的标准做法（以 `Xxx` 为例）：
1. `app/Model/Xxx.php` — 继承 `App\Model\Model`
2. `app/Dao/XxxDao.php` — 继承 `App\Dao\AbstractDao`，`protected string $model = Xxx::class;`
3. `app/Service/XxxService.php` — 继承 `App\Service\AbstractService`，通过 `#[Inject]` 注入 `XxxDao`
4. `app/Controller/XxxController.php` — 继承 `App\Controller\AbstractController`，通过 `#[Inject]` 注入 `XxxService`

## 优先使用注解

Hyperf 的注解扫描已经开启（见 [config/autoload/annotations.php](config/autoload/annotations.php)，扫描 `app/` 目录）。凡是框架提供了注解方式的功能，优先用注解，不要手写配置文件或手动注册：

| 场景 | 用注解 | 不要 |
|---|---|---|
| 路由 | `#[Controller(prefix: '/xxx')]` + `#[GetMapping(path: 'yyy')]` / `#[PostMapping]` | 手动编辑 `config/routes.php` 加 `Router::addRoute()` |
| 依赖注入 | `#[Inject]` 属性注入 | 手动 `$container->get()` |
| 事件监听 | `#[Listener]` | 手动在 `config/autoload/listeners.php` 里 `return [...]` 罗列 |
| 定时任务 | `#[Crontab]` | — |
| 自定义进程 / 框架内置进程 | 自己写的进程用 `#[Process]`；框架自带的进程类（`ConsumerProcess`、`CrontabDispatcherProcess` 等，见下面两节）**不能**直接加注解，包一层子类再注解 | 手动在 `config/autoload/processes.php` 里注册 |
| 方法级缓存 | `#[Cacheable]` / `#[CachePut]` / `#[CacheEvict]`（Service 层） | 手写 Redis 缓存逻辑 |
| 单个 Model 按主键缓存 | `hyperf/model-cache`（见下面单独一节，**不是**注解） | 手写 Redis 缓存逻辑 |
| 命令行命令 | `#[Command]` | 手动在 `config/autoload/commands.php` 里注册 |
| AOP 切面 | `#[Aspect]` | — |

`config/autoload/*.php` 里能用注解替代的手动注册项，新代码不要再往里加；已有的旧路由/配置暂时保留，不用为了统一风格去动它们，除非顺带在改这块代码。

## Model 缓存（hyperf/model-cache）

按主键查询的 Model 用 `hyperf/model-cache`（已安装），不要自己手写 Redis 缓存逻辑，也不要用 `#[Cacheable]` 注解套在 Model 的静态方法上（不支持）。

**用法**（以 `User` 为例，见 [app/Model/User.php](app/Model/User.php)）：

```php
class User extends Model implements CacheableInterface
{
    use Cacheable;
    // ...
}
```

**关键点，容易踩坑**：
- `Cacheable` trait **不会**自动接管 `find()`，必须显式调用 `User::findFromCache($id)` 才走缓存（这个 Dao 层的坑已经在 [app/Dao/AbstractDao.php](app/Dao/AbstractDao.php) 和 `UserDao::find()` 里踩过一次，新的 Dao 如果要用缓存记得重写 `find()` 调 `findFromCache`，不要指望父类的 `find()` 自动生效）
- `save()` / `delete()` 会自动触发 `DeleteCacheListener` 清缓存，这部分不用管
- 缓存配置在 `config/autoload/databases.php` 里每个连接下的 `cache` 键（[已配置](config/autoload/databases.php)），`cache_key` 用 `sprintf` 占位符格式（默认 `mc:%s:m:%s:%s:%s`），**不是** `{module}:cache:{table}:{id}` 这种花括号写法，写错了会导致缓存悄悄失效但不报错

## 异步队列（hyperf/async-queue）

已安装。用法：定义一个继承 `Hyperf\AsyncQueue\Job` 的类，实现 `handle()`，通过 `Hyperf\AsyncQueue\Driver\DriverFactory` 注入后 `->get('default')->push($job)` 推送（参考 [app/Job/SendUserWelcomeJob.php](app/Job/SendUserWelcomeJob.php) 和 [app/Service/UserService.php](app/Service/UserService.php)）。

**关键点，容易踩坑**：
- 框架不会自动跑消费者，必须注册一个继承 `Hyperf\AsyncQueue\Process\ConsumerProcess` 的进程。这个进程类是 vendor 代码不能直接加注解，要包一层子类：见 [app/Process/QueueConsumerProcess.php](app/Process/QueueConsumerProcess.php)，加 `#[Process(name: 'async-queue')]`，不用手动写进 `config/autoload/processes.php`
- Job 类里如果要打日志，别用 `StdoutLoggerInterface`（只写到 `docker logs`，不受日志切割策略管），统一用 `LoggerFactory`（Monolog，写文件，见下面"日志"一节）

## 定时任务（hyperf/crontab）

已安装。在类上加 `#[Crontab(rule: '* * * * *', name: 'Xxx')]`，方法用 `__invoke()`（参考 [app/Crontab/HeartbeatCrontab.php](app/Crontab/HeartbeatCrontab.php)）。

**关键点，容易踩坑（比 async-queue 更隐蔽）**：
- 光加 `#[Crontab]` 注解只是把任务"登记"了，框架不会自动检查/触发，必须再注册一个继承 `Hyperf\Crontab\Process\CrontabDispatcherProcess` 的进程去做真正的调度检查（跟 async-queue 需要 `ConsumerProcess` 是同一个道理）。同样是 vendor 类不能直接注解，包一层子类：见 [app/Process/CrontabDispatcherProcess.php](app/Process/CrontabDispatcherProcess.php)，加 `#[Process(name: 'crontab-dispatcher')]`
- 没注册这个 dispatcher 进程时，`#[Crontab]` 加了也完全不会报错、看起来一切正常，但永远不会执行——排查的时候记得先看 `docker exec pf ps aux` 里有没有 `crontab-dispatcher` 这个进程
- 日志同样用 `LoggerFactory`，别用 `StdoutLoggerInterface`

## 日志

- Monolog 文件日志（`config/autoload/logger.php`，用 `RotatingFileHandler`）按天切割，写到 `runtime/logs/hyperf-YYYY-MM-DD.log`，保留最近 14 天（`maxFiles`）。业务代码（Job、Crontab、Service 等）打日志统一用这套，通过 `Hyperf\Logger\LoggerFactory` 注入后 `->get('渠道名')`
- **不要**跟业务日志混用 `Hyperf\Contract\StdoutLoggerInterface`——这套只写到进程标准输出（`docker exec` 起的容器就是 `docker logs`），不经过 Monolog，不受切割/保留策略管，容器重启就没了。`StdoutLoggerInterface` 只用于框架自身的启动/运维类信息

## 测试

```bash
docker exec pf composer test
```

- 测试文件放在 `test/Cases/`，命名空间 `HyperfTest\`
- HTTP 测试继承 `test/HttpTestCase.php`

## 环境变量

- 本地/远程服务连接信息（MySQL、Redis 等）都写在 `.env`（**不提交到 git**，已在 `.gitignore` 里）
- 新增配置项时，同步更新 `.env.example`（用占位值，不要写真实密码）
- 当前 MySQL/Redis 指向局域网服务器 `192.168.1.12`，不是容器内服务，注意别把 host 改回 `localhost`

## Docker

- 容器名固定是 `pf`（在 [docker-compose.yml](docker-compose.yml) 里配置），改容器名要保持一致改法，不要改回默认的 `hyperf-skeleton`
- 常用命令：

```bash
docker compose up -d --build   # 改了 Dockerfile 或依赖后重建
docker compose restart         # 只改了 .env / 代码，重启生效
docker compose down            # 彻底停止并删除容器
```

- **改完 PHP 代码后必须 `docker compose restart` 才生效**——Swoole worker 常驻内存跑，不会自动感知文件变化重新加载，改代码后直接 curl 测试大概率还是跑的旧代码，报错也可能是旧错误堆栈（日志时间戳没变就是这个原因）
- 改了依赖注入相关代码或者加了新注解类，重启前建议先 `docker exec pf rm -rf runtime/container` 清一下容器缓存，避免用到过期的代理类

## Git

- 这个仓库根目录就是 `platform`（独立 git 仓库，不是上层 `/Users/yangjing/product` 那个仓库的子目录）
- 远程仓库：`https://github.com/tankmask0924-ops/platform`（private）
