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

- `php` / `composer` 命令必须带 `docker exec pf` 前缀在容器里跑，宿主机上没有这两个命令。

## 代码风格

- 严格类型：所有文件开头必须有 `declare(strict_types=1);`
- 字符串统一用单引号（`single_quote` 规则）
- 数组统一用短语法 `[]`
- import 按字母顺序排序（`ordered_imports`），只保留实际用到的 `use`
- 规则来源：[.php-cs-fixer.php](.php-cs-fixer.php)（基于 `@PSR2` + `@Symfony` + `@DoctrineAnnotation` + `@PhpCsFixer`）

修改代码后，提交前先跑自动修复：

```bash
docker exec pf composer cs-fix
```

**注意 1**：`cs-fix` 是全项目扫描，会顺带把没改过的旧文件也格式化。跑完之后必须 `git status` / `git diff` 检查，用 `git checkout -- <file>` 撤销跟这次任务无关的文件改动，只保留真正相关的改动。

**注意 2（治本）**：项目开了 `global_namespace_import` 规则，凡是代码里裸写完整类名（比如 `\Hyperf\Xxx\Yyy::class` 或 `Hyperf\Xxx\Yyy::class`）而没有对应 `use` 导入的，`cs-fix` 会自动帮你把它转成顶部 `use` 语句——但这个转换动作跟 `header_comment` 规则冲突，会把文件头版权注释复制成两份（已知 bug）。**预防办法：写代码时必须自己把 `use` 导入写好，代码里一律用短类名**，这样 cs-fix 不需要插入新 `use` 语句，就不会触发这个 bug。`config/autoload/*.php` 这类配置文件尤其要注意：返回的是数组字面量，同样必须先写 `use` 再用短类名。

## 静态分析

```bash
docker exec pf composer analyse
```

- 配置见 [phpstan.neon.dist](phpstan.neon.dist)，级别 `level: 0`，扫描 `app/` 和 `config/`
- phpstan level 和这份配置保持现状，只有用户明确要求时才调整

## 目录结构约定

分层架构：`Controller → Service → Dao → Model`，职责严格分开，每一层只调用紧挨着的下一层，数据库查询必须放在 Dao 里。

- `app/Controller/` — 控制器，只负责接收请求、调用 Service、返回响应，需继承 `App\Controller\AbstractController`，**业务逻辑和数据库查询必须交给 Service / Dao**
- `app/Service/` — 业务逻辑层，编排业务流程、组合多个 Dao，需继承 `App\Service\AbstractService`
- `app/Dao/` — 数据访问层，封装对单个 Model 的增删改查，需继承 `App\Dao\AbstractDao` 并设置 `protected string $model` 指向对应 Model 类；只做数据访问，业务逻辑放在 Service
- `app/Model/` — 数据库模型，继承 `App\Model\Model`
- `app/Listener/` — 事件监听器
- `app/Exception/Handler/` — 异常处理器
- `config/autoload/` — 各类自动加载配置（databases、redis、middlewares 等），新增中间件/监听器/依赖注入必须在这里集中注册，业务代码里只做使用
- PSR-4 命名空间：`App\` 映射到 `app/`（见 [composer.json](composer.json)）

新增一个业务模块的标准做法（以 `Xxx` 为例）：
1. `app/Model/Xxx.php` — 继承 `App\Model\Model`
2. `app/Dao/XxxDao.php` — 继承 `App\Dao\AbstractDao`，`protected string $model = Xxx::class;`
3. `app/Service/XxxService.php` — 继承 `App\Service\AbstractService`，通过 `#[Inject]` 注入 `XxxDao`
4. `app/Controller/XxxController.php` — 继承 `App\Controller\AbstractController`，通过 `#[Inject]` 注入 `XxxService`

## 必须使用注解

Hyperf 的注解扫描已经开启（见 [config/autoload/annotations.php](config/autoload/annotations.php)，扫描 `app/` 目录）。凡是框架提供了注解方式的功能，新代码必须用注解实现：

| 场景 | 必须这样写 | 被它取代的旧写法（仅存量代码里有） |
|---|---|---|
| 路由 | `#[Controller(prefix: '/xxx')]` + `#[GetMapping(path: 'yyy')]` / `#[PostMapping]` | 在 `config/routes.php` 里 `Router::addRoute()` |
| 依赖注入 | `#[Inject]` 属性注入 | 手动 `$container->get()` |
| 事件监听 | `#[Listener]` | 在 `config/autoload/listeners.php` 里 `return [...]` 罗列 |
| 定时任务 | `#[Crontab]` | — |
| 自定义进程 / 框架内置进程 | 自己写的进程用 `#[Process]`；框架自带的进程类（`ConsumerProcess`、`CrontabDispatcherProcess` 等，见下面两节）必须先包一层子类，注解加在子类上 | 在 `config/autoload/processes.php` 里注册 |
| 方法级缓存 | `#[Cacheable]` / `#[CachePut]` / `#[CacheEvict]`（Service 层） | 手写 Redis 缓存逻辑 |
| 单个 Model 按主键缓存 | `hyperf/model-cache`（见下面单独一节，它是 trait 加接口，属于例外） | 手写 Redis 缓存逻辑 |
| 命令行命令 | `#[Command]` | 在 `config/autoload/commands.php` 里注册 |
| AOP 切面 | `#[Aspect]` | — |

新增的注册项一律通过注解完成；`config/autoload/*.php` 里已有的旧路由/配置保持原样，只在顺带修改那块代码时再迁移成注解。

## Model 缓存（hyperf/model-cache）

按主键查询的 Model 统一用 `hyperf/model-cache`（已安装）实现缓存；`#[Cacheable]` 注解只用在 Service 层的方法上（Model 的静态方法它不支持）。

**用法**（以 `User` 为例，见 [app/Model/User.php](app/Model/User.php)）：

```php
class User extends Model implements CacheableInterface
{
    use Cacheable;
    // ...
}
```

**哪些 Model 接缓存**：只给读多写少、所有修改都走模型 `save()` / `delete()` 的 Model 接缓存，目前是 `Merchant`、`User`（商户余额的修改也是 `save()`，见 `BalanceService::persistBalance()`）。

- 订单（`Order`）及订单明细（`order_recharges` / `order_expresses` / `order_movies`）、尝试记录（`order_attempts`）这类数据必须直接读库，原因有两个：
  - 状态流转靠带条件的直接更新防并发（如 `OrderDao::finishIfStatus()`：`where status = 预期值` 再 `update`），这类更新不经过模型、不会清缓存，接了缓存就会读到旧状态。
  - 状态和金额决定是否扣款、退款，读到旧值的代价是重复扣款或重复退款。
- 新 Model 接缓存前必须同时满足两条：
  - 这张表的所有修改都经过模型 `save()` / `delete()`。
  - 读取以按主键为主（model-cache 只缓存按主键读一行）。
- 以后订单查询成了瓶颈，先加索引；要缓存也只缓存「单号 → 订单 id」这类永远不变的映射，整行订单一律读库。

**什么时候读缓存、什么时候读库**（针对已接缓存的 Model）：

| 场景 | 必须这样读 |
|---|---|
| 按主键取一条做展示、鉴权后取当前商户资料 | Dao 的 `find($id)`（内部是 `findFromCache`） |
| 列表里按一批 id 补名称、手机号等 | Dao 的批量方法，商户用 `MerchantDao::findMany($ids)` |
| 读出来要据此判断再写库（余额够不够、状态能不能改） | 在事务里用 `lockForUpdate()` 读库（如 `MerchantDao::lockForUpdate()`），读、判断、写在同一个事务里完成 |
| 刚被别的流程改过、必须拿最新值 | `$model->refresh()` 或 Dao 的普通查询 |
| 按非主键条件查（手机号、邮箱、`app_key` 等） | Dao 的普通查询，缓存不覆盖这类查询 |

- 修改已接缓存的 Model 必须走 `save()` / `delete()`，缓存由框架自动清掉。确实需要直接 `update()`（比如带条件的并发更新）时，更新后必须调用 `$model->deleteCache()` 清掉这一行的缓存。

**关键点，容易踩坑**：
- `Cacheable` trait **不会**自动接管 `find()`，必须显式调用 `User::findFromCache($id)` 才走缓存（这个 Dao 层的坑已经在 [app/Dao/AbstractDao.php](app/Dao/AbstractDao.php) 和 `UserDao::find()` 里踩过一次）。新的 Dao 要用缓存时，必须重写 `find()` 改调 `findFromCache`，父类的 `find()` 不走缓存
- 按**一批** id 取已接缓存的 Model（目前是 `Merchant`、`User`）时，必须走 Dao 里基于 `findManyFromCache()` 的批量方法，比如商户用 `MerchantDao::findMany($ids)`（按 id 作键返回，空数组返回空集合）。没接缓存的 Model（订单、供应商等）按一批 id 查时直接 `whereIn('id', $ids)`，空数组也能正常返回空结果
- `save()` / `delete()` 会自动触发 `DeleteCacheListener` 清缓存，这部分由框架负责
- handler 必须保持 `RedisStringHandler`（整行序列化）：默认的 `RedisHandler` 用 hash 存储，会把 NULL 字段读回成 `''`，`=== null` 判断全部失效（踩过：商户没生成密钥却显示已生成）。代价是不支持缓存层的 `increment()`
- 缓存配置在 `config/autoload/databases.php` 里每个连接下的 `cache` 键（[已配置](config/autoload/databases.php)），`cache_key` 必须用 `sprintf` 占位符格式（默认 `mc:%s:m:%s:%s:%s`）；写成 `{module}:cache:{table}:{id}` 这种花括号格式会让缓存悄悄失效且不报错

## 异步队列（hyperf/async-queue）

已安装。用法：定义一个继承 `Hyperf\AsyncQueue\Job` 的类，实现 `handle()`，通过 `Hyperf\AsyncQueue\Driver\DriverFactory` 注入后 `->get('default')->push($job)` 推送（参考 [app/Job/SendUserWelcomeJob.php](app/Job/SendUserWelcomeJob.php) 和 [app/Service/UserService.php](app/Service/UserService.php)）。

**关键点，容易踩坑**：
- 框架不会自动跑消费者，必须注册一个继承 `Hyperf\AsyncQueue\Process\ConsumerProcess` 的进程。这个进程类是 vendor 代码，必须包一层子类再加注解：见 [app/Process/QueueConsumerProcess.php](app/Process/QueueConsumerProcess.php)，加 `#[Process(name: 'async-queue')]` 即完成注册
- Job 类里打日志必须用 `LoggerFactory`（Monolog，写文件，见下面"日志"一节）；`StdoutLoggerInterface` 只写到 `docker logs`，不受日志切割策略管

## 定时任务（hyperf/crontab）

已安装。在类上加 `#[Crontab(rule: '* * * * *', name: 'Xxx')]`，方法用 `__invoke()`（参考 [app/Crontab/HeartbeatCrontab.php](app/Crontab/HeartbeatCrontab.php)）。

**关键点，容易踩坑（比 async-queue 更隐蔽）**：
- 光加 `#[Crontab]` 注解只是把任务"登记"了，框架不会自动检查/触发，必须再注册一个继承 `Hyperf\Crontab\Process\CrontabDispatcherProcess` 的进程去做真正的调度检查（跟 async-queue 需要 `ConsumerProcess` 是同一个道理）。同样是 vendor 类，必须包一层子类再注解：见 [app/Process/CrontabDispatcherProcess.php](app/Process/CrontabDispatcherProcess.php)，加 `#[Process(name: 'crontab-dispatcher')]`
- 没注册这个 dispatcher 进程时，`#[Crontab]` 加了也完全不会报错、看起来一切正常，但永远不会执行——排查时必须先看 `docker exec pf ps aux` 里有没有 `crontab-dispatcher` 这个进程
- 日志同样必须用 `LoggerFactory`

## 日志

- Monolog 文件日志（`config/autoload/logger.php`，用 `RotatingFileHandler`）按天切割，写到 `runtime/logs/hyperf-YYYY-MM-DD.log`，保留最近 14 天（`maxFiles`）。业务代码（Job、Crontab、Service 等）打日志必须统一用这套，通过 `Hyperf\Logger\LoggerFactory` 注入后 `->get('渠道名')`
- `Hyperf\Contract\StdoutLoggerInterface` 只留给框架自身的启动/运维类信息。它只写到进程标准输出（`docker exec` 起的容器就是 `docker logs`），不经过 Monolog，不受切割/保留策略管，容器重启就没了

## 测试

```bash
docker exec pf composer test
```

- 测试文件放在 `test/Cases/`，命名空间 `HyperfTest\`
- HTTP 测试继承 `test/HttpTestCase.php`

## 环境变量

- 本地/远程服务连接信息（MySQL、Redis 等）都写在 `.env`（只留在本地，已在 `.gitignore` 里）
- 新增配置项时，必须同步更新 `.env.example`，里面一律填占位值
- 当前 MySQL/Redis 指向局域网服务器 `192.168.1.12`，不是容器内服务，host 必须保持 `192.168.1.12`

## Docker

- 容器名固定是 `pf`（在 [docker-compose.yml](docker-compose.yml) 里配置），所有地方必须保持 `pf`；要改名时各处必须一起改
- 常用命令：

```bash
docker compose up -d --build   # 改了 Dockerfile 或依赖后重建
docker compose restart         # 只改了 .env / 代码，重启生效
docker compose stop            # 停止容器（保留容器，用 start 原样起回来）
docker compose start           # 起回被 stop 的容器
docker compose down            # 停止并删除容器和网络，只在确实要清理时用
```

- **"停掉服务"必须用 `docker compose stop`**：`down` 会把容器和网络一起删掉，下次得重新创建容器。这个项目里 `down` 的实际损失很小（compose 里唯一的挂载是 `./:/opt/www` 这个 bind mount，代码、`vendor/`、`runtime/logs` 全在宿主机；没有任何 named volume；MySQL/Redis 在 `192.168.1.12` 不在容器里），但"停止"和"删除"是两件事，**必须严格按用户说的范围执行**。踩过：用户说"把服务停掉"，执行的是 `docker compose down`。
- 同理，`down -v` 会连 volume 一起删，这个项目现在没有 named volume，但以后加了就是真丢数据 —— `-v` 只在用户明确要求时才加。
- **改完 PHP 代码后必须 `docker compose restart` 才生效**——Swoole worker 常驻内存跑，不会自动感知文件变化重新加载，改代码后直接 curl 测试大概率还是跑的旧代码，报错也可能是旧错误堆栈（日志时间戳没变就是这个原因）
- 改了依赖注入相关代码或者加了新注解类，重启前必须先 `docker exec pf rm -rf runtime/container` 清一下容器缓存，确保用的是最新的代理类

## Git

- 这个仓库根目录就是 `platform`（独立 git 仓库，不是上层 `/Users/yangjing/product` 那个仓库的子目录）
- 远程仓库：`https://github.com/tankmask0924-ops/platform`（private）
