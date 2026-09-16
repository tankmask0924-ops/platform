# 模块设计与开发进度

> 依据：[requirements.md](requirements.md) v1.7、[database-design.md](database-design.md)（2026-09-14）
> 用途：把需求和数据库设计拆成可独立开发的模块/接口/任务，跟踪**开发阶段**的完成度（不是需求或表设计是否确定——那两份文档本身已经"设计已确定"）。
> 更新方式：每完成一层就把对应格子改成 ✅；一行的 Model/Dao/Service/Controller（或对应入口）都 ✅ 且联调通过，整行"状态"才算 ✅ 已完成。

**状态图例**：⬜ 未开始　🔨 开发中　✅ 已完成　➖ 不适用

**目录**

1. [基础设施与公共能力](#1-基础设施与公共能力)
2. [供应商驱动 - 卡速售 2.0（话费、卡券）](#2-供应商驱动---卡速售-20话费卡券)
3. [供应商驱动 - 云洋（快递）](#3-供应商驱动---云洋快递)
4. [供应商驱动 - 芒果（电影票）](#4-供应商驱动---芒果电影票)
5. [供应商路由与风控](#5-供应商路由与风控)
6. [开放 API 接口](#6-开放-api-接口)
7. [商户管理后台（web/merchant）](#7-商户管理后台webmerchant)
8. [系统管理后台（web/admin）](#8-系统管理后台webadmin)
9. [异步任务与定时任务](#9-异步任务与定时任务)
10. [进度总览](#10-进度总览)

---

## 1. 基础设施与公共能力

跨模块的公共能力，其他模块都要依赖它们，建议最先做。

| 模块 | 设计依据 | 实现要点 | 状态 |
|---|---|---|---|
| 数据库迁移 | [database-design.md](database-design.md) 全文 | 按[分期](database-design.md#6-按分期建表)把 39 张表拆成迁移文件；一期 27 张已建好并跑通（`migrations/2026_09_14_*`），含 `merchant_balance_logs` 的幂等生成列，已用真实数据验证去重逻辑 | ✅ |
| 加密服务 | [database-design.md 5.3](database-design.md#53-供应商配置和商户密钥的加密) | `App\Crypto\Encryptor`：`AES-256-GCM`，密钥读 `.env` 的 `APP_ENCRYPTION_KEY`；4 个单测通过（往返、非确定性、篡改检测、非法输入） | ✅ |
| 开放 API 签名鉴权中间件 | [requirements.md 8.1](requirements.md#81-开放-api) | 验 `app_key`/`timestamp`/`nonce`/`sign`；`nonce` 防重放放 Redis（见 [database-design.md 5.6](database-design.md#56-哪些东西故意不放进这份-mysql-设计)）。`App\Middleware\OpenApiSignatureMiddleware`（PSR-15）：按 `app_key` 查商户（`MerchantDao::findByAppKey`）、校验 `status === active`、用 `Encryptor` 解密 `app_secret`、`SignatureSigner::verify` 验签、校验 `timestamp` ±5 分钟窗口、`NonceGuard::consume` 防重放；验签通过后把 Merchant 通过 `$request->withAttribute('merchant', $merchant)` 传给下一个 handler；失败时直接返回 `{code, message, data}` 形状的 JSON（内部占位错误码，不是平台统一错误码，统一返回格式那行做完后要收敛过去）。**未注册进 `config/autoload/middlewares.php` 全局列表**（会连带拦住 `/`、`/users`），在开放 API Controller 上用 `#[Middleware(OpenApiSignatureMiddleware::class)]` 类级注解挂载（**不能**用 `#[Controller(options: ['middleware' => [...]])]`——这个 Hyperf 版本的 `DispatcherFactory::handleController()` 会把 Controller 注解 options 里的 middleware 键整个覆盖掉，只认 `#[Middleware]`/`#[Middlewares]` 注解，那样中间件会静默不生效，真机测试过才发现）。已通过第 6 节"查询余额"接口做了真实 HTTP 派发的端到端联调（`test/Cases/OpenApi/BalanceControllerTest.php`，用 `test/HttpTestCase.php` 走真实路由 + 中间件栈，覆盖正常签名和错误签名两条路径），证明中间件真的挂上了、Merchant 属性真的传下去了，不再是仅靠 mock handler 的单测。中间件本身连同它依赖的 Merchant Model/Dao（见下一行）已经完整可用 | ✅ |
| Merchant / MerchantLevel Model 与 Dao 基础设施 | [database-design.md](database-design.md) merchants / merchant_levels 表 | `App\Model\Merchant`（`hyperf/model-cache`，`ip_whitelist` 转 `array`）、`App\Model\MerchantLevel`；`App\Dao\MerchantDao`（`find()` 重写走 `findFromCache`，新增 `findByAppKey()` 不走缓存、新增 `lockForUpdate()` 加行锁不走缓存）、`App\Dao\MerchantLevelDao`。加密/解密逻辑不放在 Model，留在 Dao/Service/中间件层。单测覆盖按 id（含缓存）、按 app_key 查询、行锁查询（含验证编译出的 SQL 真的带 `for update`），真实 DB 建行后 teardown 清理 | ✅ |
| 商户余额冻结/扣款/解冻 | [requirements.md 4.4](requirements.md#44-账户余额)、[4.5](requirements.md#45-负余额) | `App\Model\MerchantBalanceLog`（对应 `merchant_balance_logs`，`$timestamps = false`，写一次不改，`dedupe_order_key`/`dedupe_rebate_key` 两个生成列不进 `$fillable`）+ `App\Dao\MerchantBalanceLogDao`；`App\Service\Merchant\BalanceService::freeze()/deduct()/unfreeze()` 三个余额变动原语：`freeze()` 在 `Db::transaction()` 里 `MerchantDao::lockForUpdate()` 锁行、检查可用余额≥金额，够则冻结（可用减、冻结加）写 `freeze` 日志返回 `true`，不够直接返回 `false`（事务内无任何写操作，等效回滚，不记日志）；`deduct()`/`unfreeze()` 结构对称：先按 `dedupe_order_key`（`"{order_id}-{type}"`）唯一索引尝试插日志，插入成功才改余额列，插入因唯一索引撞车抛 `QueryException` 时捕获当幂等 no-op（余额列不会被碰到），不依赖应用层查了再写的预检查。全程 bcmath 字符串运算，不用 float。金额、bcmath 全程 scale=2。`freeze()` 本身没有同订单重复调用的 DB 级防护（`dedupe_order_key` 的生成列 CASE 只覆盖 `deduct`/`unfreeze`，不含 `freeze`）——按下单时序（先冻结、冻结成功才建订单行）没有现成的订单级唯一键可用，这道防线留给下一个任务（下单流程）在调用 `freeze()` 之前用请求幂等键/订单表约束解决。**范围内**：`freeze`/`deduct`/`unfreeze` 三种流水类型；**范围外**：`recharge`/`supplement_deduct`/`refund`/`adjustment`/`rebate_settle`/`rebate_clawback` 对应的充值审核、售后补扣/退款、手动调账、返佣结算/扣回流程，以及 `merchants.debt_since` 的维护（只有 `supplement_deduct`/`rebate_clawback` 会触发，两者都不在这次任务），还有把这三个原语真正接进下单流程（路由 + 供应商驱动调用 + 判定成败）——都是后续任务。测试见 `test/Cases/Service/Merchant/BalanceServiceTest.php`（含 `deduct`/`unfreeze` 各自的重复调用幂等测试）+ `test/Cases/Dao/MerchantDaoTest.php` 新增的行锁相关用例 | ✅ |
| 开放 API IP 白名单校验 | [requirements.md 8.1](requirements.md#81-开放-api) | 读 `merchants.ip_whitelist` | ⬜ |
| 按商户限流中间件 | [requirements.md 8.1](requirements.md#81-开放-api) | 配置读 `merchant_rate_limits`/`system_settings.default_rate_limit_per_second`，计数器走 Redis | ⬜ |
| 统一返回格式与错误码 | [requirements.md 8.1](requirements.md#81-开放-api) | `{code, message, data}`；平台统一错误码，不透传供应商原始信息 | ⬜ |
| 供应商回调入口与验签框架 | [requirements.md 6.8](requirements.md#68-回调日志与统计) | `/notify/{供应商编码}?token=...`，按 `suppliers.driver` 分发给对应驱动解析 | ⬜ |
| 后台角色权限中间件 | [requirements.md 8.3](requirements.md#83-系统管理后台webadmin) | 系统管理后台（web/admin）第三套独立鉴权体系，跟商户端 JWT（`MERCHANT_JWT_SECRET`）、开放 API HMAC 签名都不共用任何密钥/中间件类。登录态：`App\Auth\AdminJwtGuard`（HS256，独立密钥 `ADMIN_JWT_SECRET`，TTL 8 小时——比商户端 7 天短，管理员权限更高），`App\Middleware\AdminAuthMiddleware` 解出 `AdminUser` 挂 `$request->withAttribute('admin', ...)`。角色权限校验：新增 `App\Annotation\RequiresPermission`（纯 PHP attribute，标在 Controller 方法上声明权限编码）+ `App\Middleware\AdminPermissionMiddleware`（通过 `Dispatched::$handler->callback` 反射读方法上的 `#[RequiresPermission]`，没有则放行，有则查 `App\Dao\AdminRolePermissionDao::roleHasPermission(role_id, code)` 判断，不通过 403）。两个中间件都以方法级 `#[Middleware(...)]` 挂载，`AdminAuthMiddleware` 必须写在 `AdminPermissionMiddleware` 前面（同优先级时 Hyperf 按注解书写顺序 FIFO 执行），已用真实 HTTP 派发验证顺序正确（`test/Cases/Admin/MerchantControllerTest.php::testNoTokenAtAllReturns401NotAPermissionError`：没 token 时 401 而不是拿不到 admin attribute 崩 500）。首个真实落地的受保护接口见第 8 节「商户管理：列表」。**账号开通**：管理员账号非自助注册，此前没有任何 API/工具能创建 `admin_users` 记录，测试只能直接插 Model；现已补上 `App\Command\CreateAdminCommand`（`docker exec pf php bin/hyperf.php admin:create --username= --password= [--real-name=]`，幂等可重复执行）+ `App\Service\Admin\AdminBootstrapService`：find-or-create `super_admin` 角色、find-or-create 当前已知权限（`AdminBootstrapService::KNOWN_PERMISSIONS`，目前只有 `merchant.view`，每新增一个 `#[RequiresPermission]` 都要同步进这个数组，否则该权限在 DB 里不存在，所有角色对它的检查都会被判定为无权限）并授权给该角色、创建 `AdminUser`（bcrypt 哈希密码），测试见 `test/Cases/Service/Admin/AdminBootstrapServiceTest.php` + `test/Cases/Command/CreateAdminCommandTest.php` | ✅ |
| 异步队列消费进程 | [hyperf-conventions](../.claude/skills/hyperf-conventions/SKILL.md) | 继承 `ConsumerProcess` 并 `#[Process]` 注册，别忘了这步——注解本身不会自动生效 | ⬜ |
| 定时任务调度进程 | [hyperf-conventions](../.claude/skills/hyperf-conventions/SKILL.md) | 继承 `CrontabDispatcherProcess` 并 `#[Process]` 注册，同上 | ⬜ |

---

## 2. 供应商驱动 - 卡速售 2.0（话费、卡券）

依据：[requirements.md 6.2](requirements.md#62-对接驱动的统一能力)、[kasushou.md](suppliers/kasushou.md)

| 能力 | Driver 方法 | Service 接入 | 单测 | 状态 |
|---|---|---|---|---|
| 下单 | ✅ `App\Supplier\Kasushou\KasushouDriver::placeOrder()` | ⬜（无订单处理 Service 调用，路由/供应商配置基础设施未建） | ✅ `test/Cases/Supplier/Kasushou/KasushouDriverTest.php` | 🔨 |
| 查询订单 | ✅ `KasushouDriver::queryOrder()` | ⬜ 同上 | ✅ 同上 | 🔨 |
| 解析回调（含验签） | ✅ `KasushouDriver::parseCallback()` + `App\Supplier\Kasushou\KasushouSigner` | ⬜ 同上（回调入口/验签框架本身也还是 ⬜，见第 1 节） | ✅ `KasushouDriverTest` + `KasushouSignerTest` | 🔨 |
| 查询余额 | ✅ `KasushouDriver::queryBalance()` | ⬜ 同上 | ✅ `KasushouDriverTest` | 🔨 |
| 同步商品（成本价/状态/库存） | ✅ `KasushouDriver::parseProductChangeNotification()`（验签见 `KasushouSigner::verifyProductChangeNotification()`）+ `queryProductDetail()` + `syncAllProducts()` | 🔨 `App\Service\Supplier\ProductSyncService`（`applyNotification()`/`applyFullSyncPage()`）已建好，但**目前没有任何调用方接入、没有在生产环境跑起来**：商品变更通知需要的 webhook 路由/控制器是第 1 节"供应商回调入口与验签框架"，还是 ⬜；每日全量同步需要的 `#[Crontab]` 定时任务，依赖一个从哪里读供应商配置（baseUrl/userId/apiKey）的 Supplier 配置加载机制，同样还没建，本次任务范围明确不含这两者 | ✅ `KasushouSignerTest`（验签，含 id+time 之外字段不参与签名的用例）、`KasushouDriverTest`（三个新方法）、`ProductSyncServiceTest`（映射命中/未命中、价格是否变化触发历史记录）、`SupplierProductDaoTest`（`applySync()` 改价必留痕，Dao 层直接单测） | 🔨 |
| 撤单（异常单处理用，可选） | ⬜ | ⬜ | ⬜ | ⬜ |
| 提交售后 / 接收售后结果 | ⬜ | ⬜ | ⬜ | ⬜ |
| 错误码映射表 | ✅ `App\Supplier\Kasushou\KasushouStatusMapper`（对应 kasushou.md 第 2 节状态表 + 第 3 节错误处理表，placeOrder/queryOrder 内部共用） | ➖ | ✅ `KasushouDriverTest` 覆盖各状态码分支 | ✅ |

> 本次新增（下单/查询订单/解析回调/查询余额）：`App\Supplier\UnifiedResult`（4 态枚举）、`App\Supplier\DriverResult`（统一结果 DTO，含超出 6.2 字面字段列表的 `cardList` 扩展字段，见类注释）、`App\Supplier\Kasushou\KasushouSigner`（sha1 签名/验签）、`App\Supplier\Kasushou\KasushouStatusMapper`、`App\Supplier\Kasushou\KasushouDriver`。未引入 `DriverInterface`：目前只有卡速售一个驱动实现，云洋/芒果尚未开工，接口形状还没被第二个实现验证过，判断属于过早抽象，留了代码注释提醒等第二个驱动落地后再抽取。Service 接入留空：订单处理/路由 Service 调用这个驱动尚未建立（依赖第 5 节路由与第 6 节话费下单 API，均未开工），且供应商配置从哪里读取（`suppliers.config`）本身也是单独一期的 ⬜ 行，本次驱动构造函数直接接收 baseUrl/userId/apiKey，跟配置来源解耦。
>
> 本次新增（商品同步）：`App\Model\SupplierProduct` + `App\Dao\SupplierProductDao`（`findBySupplierAndCode()` 按供应商+供应商商品编码反查映射行；`applySync()` 是全项目唯一负责改 `cost_price` 的方法，在同一个事务里做到"改价必留痕"，不需要在每个调用方各自重复判断要不要写历史）、`App\Model\SupplierProductPriceHistory` + `App\Dao\SupplierProductPriceHistoryDao`（写一次不再更新，同 `MerchantNotifyLog`/`OrderRecharge` 模式）、`KasushouSigner::verifyProductChangeNotification()`（第三套签名作用域，只覆盖 id+time，公式是按本类既有签名家族的推断，不是文档直接给出的字符串，见方法注释）、`KasushouDriver::parseProductChangeNotification()`/`queryProductDetail()`/`syncAllProducts()`、`App\Service\Supplier\ProductSyncService`（命名不带 Kasushou，为将来云洋/芒果复用留空间）。核心安全设计：商品变更通知的签名只覆盖 id+time，通知 payload 里即便夹带价格/状态/库存也不受签名保护，可被任意篡改，所以验签通过后只当"触发信号"，权威值一律重新调 `queryProductDetail()` 查询，不直接信任通知内容——跟 `KasushouDriver::parseCallback()` 对 `card_list`/`express_list` 的处理是同一个模式。商品映射行的创建（后台"商品映射"功能）、商品变更通知的 webhook 路由/控制器、每日全量同步的 `#[Crontab]` 定时任务，均不在本次任务范围内。

---

## 3. 供应商驱动 - 云洋（快递）

依据：[requirements.md 7.2](requirements.md#72-快递下单与补差价)、[yunyang.md](suppliers/yunyang.md)；三期功能。

| 能力 | Driver 方法 | Service 接入 | 单测 | 状态 |
|---|---|---|---|---|
| 查价（检测可用渠道） | ⬜ | ⬜ | ⬜ | ⬜ |
| 下单 | ⬜ | ⬜ | ⬜ | ⬜ |
| 查询订单详情 | ⬜ | ⬜ | ⬜ | ⬜ |
| 解析回调（无签名，触发查询确认） | ⬜ | ⬜ | ⬜ | ⬜ |
| 查询余额 | ⬜ | ⬜ | ⬜ | ⬜ |
| 取消 | ⬜ | ⬜ | ⬜ | ⬜ |
| 轨迹查询 | ⬜ | ⬜ | ⬜ | ⬜ |
| 提交售后工单 / 接收工单回调 | ⬜ | ⬜ | ⬜ | ⬜ |
| 错误码映射表 | ⬜ | ➖ | ⬜ | ⬜ |

---

## 4. 供应商驱动 - 芒果（电影票）

依据：[requirements.md 7.3](requirements.md#73-电影票下单)、[mango.md](suppliers/mango.md)；三期功能。

| 能力 | Driver 方法 | Service 接入 | 单测 | 状态 |
|---|---|---|---|---|
| 城市 / 行政区查询（含批量拉取） | ⬜ | ⬜ | ⬜ | ⬜ |
| 影院查询（含批量拉取 + 更新回调增量同步） | ⬜ | ⬜ | ⬜ | ⬜ |
| 影片 / 场次查询（视批量权限，可能只做实时转发） | ⬜ | ⬜ | ⬜ | ⬜ |
| 座位查询（不缓存，始终实时） | ⬜ | ⬜ | ⬜ | ⬜ |
| 锁座下单（含分区/情侣座/隔空选座/单笔限座校验） | ⬜ | ⬜ | ⬜ | ⬜ |
| 确认下单 | ⬜ | ⬜ | ⬜ | ⬜ |
| 释放座位 | ⬜ | ⬜ | ⬜ | ⬜ |
| 查询订单详情 | ⬜ | ⬜ | ⬜ | ⬜ |
| 解析回调（无签名，触发查询确认；出票后改票根幂等处理） | ⬜ | ⬜ | ⬜ | ⬜ |
| 查询余额 | ⬜ | ⬜ | ⬜ | ⬜ |
| 错误码映射表 | ⬜ | ➖ | ⬜ | ⬜ |

---

## 5. 供应商路由与风控

不属于任何一个驱动，是驱动之上的公共调度逻辑。

| 模块 | 设计依据 | 状态 |
|---|---|---|
| 固定优先级路由与失败切换 | [requirements.md 6.5](requirements.md#65-路由与失败切换) | ⬜ |
| 切换时长限制 | [requirements.md 6.5](requirements.md#65-路由与失败切换) | ⬜ |
| 熔断判定与自动恢复（二期） | [requirements.md 6.6](requirements.md#66-熔断) | ⬜ |
| 供应商商品成本价同步任务（卡速售自动，其余人工） | [requirements.md 6.4](requirements.md#64-商品映射与成本价) | ⬜ |
| 结果未知 / 明确失败归类的统一处理框架 | [requirements.md 6.2](requirements.md#62-对接驱动的统一能力) | ⬜ |

---

## 6. 开放 API 接口

依据：[requirements.md 8.1](requirements.md#81-开放-api)

> 路由前缀约定：本节所有开放 API 接口统一挂在 `/open-api` 前缀下（`查询余额`是第一个落地的接口，由它定的这个约定），后续新增接口请沿用，不要另起前缀。

| 接口 | Controller | Service | 单测 | 状态 |
|---|---|---|---|---|
| 查询余额（可用/冻结/待到账返佣） | ✅ | ✅ | ✅ | ✅ |
| 订单查询（平台单号或商户单号，二选一） | ✅ | ✅ | ✅ | ✅ |<sup>①</sup>
| 结果回调（平台 → 商户，含重试） | ➖ | ✅ | ✅ | ✅ |<sup>②</sup>
| 话费商品列表 | ✅ | ✅ | ✅ | ✅ |<sup>③</sup>
| 话费下单 | ⬜ | ⬜ | ⬜ | ⬜ |
| 卡券商品列表（二期） | ⬜ | ⬜ | ⬜ | ⬜ |
| 卡券下单（二期） | ⬜ | ⬜ | ⬜ | ⬜ |
| 电影票城市 / 影院 / 影片 / 场次 / 座位查询（三期） | ⬜ | ⬜ | ⬜ | ⬜ |
| 电影票锁座（三期） | ⬜ | ⬜ | ⬜ | ⬜ |
| 电影票确认出票（三期） | ⬜ | ⬜ | ⬜ | ⬜ |
| 电影票释放座位（三期） | ⬜ | ⬜ | ⬜ | ⬜ |
| 快递查价（三期） | ⬜ | ⬜ | ⬜ | ⬜ |
| 快递下单（三期） | ⬜ | ⬜ | ⬜ | ⬜ |
| 快递取消（三期） | ⬜ | ⬜ | ⬜ | ⬜ |
| 快递轨迹查询（三期） | ⬜ | ⬜ | ⬜ | ⬜ |

① `订单查询`目前只覆盖一期业务线（`recharge`/`card`）：`App\Model\Order`/`OrderRecharge`、
`App\Dao\OrderDao`（`findByOrderNoForMerchant`/`findByMerchantOrderNoForMerchant` 都带
`merchant_id` 条件，防止商户越权查到别人的订单和卡密，有专门的跨商户隔离测试覆盖）、
`App\Dao\OrderRechargeDao`、`App\Service\OpenApi\OrderQueryService`（卡密类订单查
`order_recharges` 并用 `Encryptor` 解密返回明文卡号卡密）、
`App\Controller\OpenApi\OrderController`（`GET /open-api/order`）。
`requirements.md 8.1` 提到的"快递订单返回费用明细"是三期功能，对应的费用明细表还没建，
这里**没有做任何 express 专属处理**——express 订单目前只会返回订单基础字段，不含快递费用
明细，等三期快递相关表（云洋驱动，见第 3 节）建好后再补。同时新增了
`App\Controller\OpenApi\AbstractOpenApiController::fail()`（业务失败信封，HTTP 状态码
统一保持 200，用 `code` 非 0 表达失败，跟中间件鉴权失败用 4xx 状态码是两套不同约定，
详见该类的注释）。

② `结果回调`是平台主动出站通知商户，没有入站 Controller，Controller 列填 ➖（不适用）
而不是 ⬜。这里落地的只是**通知原语**本身：`App\Notify\CallbackUrlGuard`（SSRF 防护，
校验 http/https + 拒绝内网/保留地址/云元数据地址，纯单测覆盖，不需要网络）、
`App\Model\MerchantNotifyLog` / `App\Dao\MerchantNotifyLogDao`（每次尝试写一条日志，
`payload` 不含卡密——结果通知只带订单状态字段，卡密走订单查询接口）、
`App\Job\NotifyMerchantJob`（一次尝试对应一个 Job 实例，签名复用
`App\Signature\SignatureSigner`，按 1 分钟/5 分钟/15 分钟/1 小时/2 小时/6 小时的
间隔自我重新入队重试，最多 7 次尝试）、`App\Service\MerchantNotifyService`（唯一入口
`notify(int $orderId)`，以 delay=0 派发首次尝试）。**真正在订单成功/失败/取消/已退款
等生命周期节点调用 `MerchantNotifyService::notify()` 的那部分代码还没有实现**——目前
没有任何订单生命周期/供应商对接驱动代码存在，等那部分工作（第 2/3/4 节驱动 +
订单处理流程）落地时接进来即可。

③ `话费商品列表`只支持 `business_line=recharge`：`App\Model\Product`/`ProductLevelRebate`/
`MerchantLevelBusinessRate`、对应的 `App\Dao\ProductDao`（`listOnShelfByBusinessLine`）/
`ProductLevelRebateDao`/`MerchantLevelBusinessRateDao`、`App\Service\Product\RebateCalculator`
（requirements.md 5.3 返佣公式：返佣基数 × 等级比例、向下取整到分，全程用 `bcmath` 字符串运算，
不用 float，`bcmul` 对 scale 是截断不是四舍五入，非负数场景下截断等价于向下取整）、
`App\Service\OpenApi\ProductListService`、`App\Controller\OpenApi\ProductController`
（`GET /open-api/products`）。传 `business_line=card`（卡券是本节单独一行"卡券商品列表（二期）"，
不在本次范围）或其它取值，一律用 `AbstractOpenApiController::fail()` 返回明确的 4xx 业务错误码，
不静默返回空列表。**已知范围限制**：requirements.md 8.1 原文是"商户已开通的商品"，即只列出
商户在 4.2 节"开通服务"审核通过的业务线，但那套开通/审核机制（`merchant_business_subscriptions`
表已建，见 migrations/2026_09_14_090900_create_merchant_business_subscriptions_table.php）
对应的正是第 7 节"服务开通：查看可开通业务线 / 提交申请 / 查看状态"这一整行独立、完全未开工的
功能（三列都是 ⬜），本任务无法实现一个不存在的门槛检查，所以这里只要求商户通过
`OpenApiSignatureMiddleware` 鉴权（商户存在且 `status = active`）即可看到该业务线全部在架商品，
等第 7 节那一行落地后再补上按 `merchant_business_subscriptions` 过滤的逻辑。

---

## 7. 商户管理后台（web/merchant）

依据：[requirements.md 8.2](requirements.md#82-商户管理后台webmerchant)

| 模块 | Controller | Service | 状态 |
|---|---|---|---|
| 账户：注册（企业/个人） | ✅ | ✅ | ✅ |
| 账户：登录 | ✅ | ✅ | ✅ |
| 账户：找回密码 | ⬜ | ⬜ | ⬜ |
| 账户：修改密码 | ⬜ | ⬜ | ⬜ |
| 账户：资质提交与审核状态查看 | ⬜ | ⬜ | ⬜ |
| 首页：统计数据展示 | ⬜ | ⬜ | ⬜ |
| 开发设置：生成 / 重置 AppKey 与 AppSecret | ✅ | ✅ | ✅ |
| 开发设置：IP 白名单配置 | ✅ | ✅ | ✅ |
| 服务开通：查看可开通业务线 / 提交申请 / 查看状态 | ⬜ | ⬜ | ⬜ |
| 商品价格：售价与自己等级的返佣展示 | ⬜ | ⬜ | ⬜ |
| 充值：提交申请 / 查看记录 | ⬜ | ⬜ | ⬜ |
| 资金流水：查询与导出 | ⬜ | ⬜ | ⬜ |
| 返佣：明细查询与导出 | ⬜ | ⬜ | ⬜ |
| 订单管理：列表 / 详情 / 回调记录与手动重推 / 导出 | ⬜ | ⬜ | ⬜ |
| 售后：未到账争议提交与查看 | ⬜ | ⬜ | ⬜ |
| 接口文档：在线查看 / 下载签名示例 | ⬜ | ➖ | ⬜ |

---

## 8. 系统管理后台（web/admin）

依据：[requirements.md 8.3](requirements.md#83-系统管理后台webadmin)

| 模块 | Controller | Service | 状态 |
|---|---|---|---|
| 商户管理：列表 | ✅ `App\Controller\Admin\MerchantController` | ✅ `App\Service\Admin\MerchantAdminService` | ✅ |
| 商户管理：入驻审核（通过 + 分配等级 / 驳回）/ 详情 | ✅ `App\Controller\Admin\MerchantController` | ✅ `App\Service\Admin\MerchantAdminService` | ✅ |
| 商户管理：启用禁用 / 调整等级（针对已 active 商户的后续变更）/ 限流设置 | ⬜ | ⬜ | ⬜ |
| 服务开通审核 | ⬜ | ⬜ | ⬜ |
| 充值与调账：充值审核 / 手动调账 | ⬜ | ⬜ | ⬜ |
| 本地商品库：CRUD | ⬜ | ⬜ | ⬜ |
| 供应商管理：配置 CRUD（新建/列表/详情/修改/启用禁用，requirements.md 6.3） | ✅ `App\Controller\Admin\SupplierController` | ✅ `App\Service\Admin\SupplierAdminService` | ✅ |
| 供应商管理：商品映射（新建/列表/改价（必留痕）/优先级/启停，requirements.md 6.4） | ✅ `App\Controller\Admin\ProductMappingController` | ✅ `App\Service\Admin\ProductMappingAdminService` | ✅ |
| 供应商管理：商品同步接入 / 余额监控 / 熔断状态 / 调用日志 / 统计 | ⬜ | ⬜ | ⬜ |
| 商户等级：CRUD / 各业务线比例设置 | ⬜ | ⬜ | ⬜ |
| 价格设置：电影票 / 快递加价规则 / 价格预览 | ⬜ | ⬜ | ⬜ |
| 返佣管理：固定期限设置 / 商户返佣明细 / 供应商返佣明细 | ⬜ | ⬜ | ⬜ |
| 订单管理：全部订单查询 / 详情 / 异常单处理 / 部分退款处理 / 手动查询供应商 / 手动重推商户回调 | ⬜ | ⬜ | ⬜ |
| 售后处理：话费卡券争议处理 / 快递工单代提交与跟踪 | ⬜ | ⬜ | ⬜ |
| 财务报表 | ⬜ | ⬜ | ⬜ |
| 对账：订单对账 / 返佣对账 / 差异标记处理 | ⬜ | ⬜ | ⬜ |
| 告警：列表查看 / 标记处理 | ⬜ | ⬜ | ⬜ |
| 系统设置：管理员账号 / 角色权限 / 系统参数 / 操作日志 | ⬜ | ⬜ | ⬜ |

> 「供应商管理：配置 CRUD」：新增 `App\Model\Supplier` + `App\Dao\SupplierDao` +
> `App\Service\Admin\SupplierAdminService` + `App\Controller\Admin\SupplierController`
> （`GET/POST /admin/suppliers`、`GET/PUT /admin/suppliers/{id}`、
> `POST /admin/suppliers/{id}/status`），两个权限编码 `supplier.view`（列表/详情）、
> `supplier.manage`（新建/修改/启停），已同步进
> `App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS`。`driver` 校验
> 白名单目前只有 `kasushou`（`SupplierAdminService::KNOWN_DRIVERS`，云洋/芒果落地后
> 追加）。`config`（接口地址/账号/密钥等，按驱动各不相同的自由 JSON）整段加密存储
> （`App\Crypto\Encryptor`，加密的是 `json_encode` 后的字符串，不是逐字段加密），
> 列表接口完全不返回 `config`，详情接口解密后按启发式脱敏——key 名大小写不敏感包含
> `key`/`secret`/`password`/`token` 的字段值替换成固定掩码 `'******'`，其余字段
> （如 `base_url`/`user_id`）原样返回，任何情况下都不明文回显密钥（比商户
> `app_secret` 生成时明文回显一次更严格，见 requirements.md 6.3）。**范围之外**：
> 不建 `/notify/{code}` 回调路由本身（第 1 节「供应商回调入口与验签框架」仍是
> ⬜，只保证 `code` 唯一且创建后不可改，给它留好稳定标识）；不把这里建的
> `Supplier` 行接入实际下单路由去构造 `KasushouDriver` 实例（订单路由，6.5 节，
> 更大的单独工作）；`balance`/`balance_synced_at` 在这个 API 里只读，由未来的
> 余额同步任务写入。测试见 `test/Cases/Admin/SupplierControllerTest.php`。

> 「供应商管理：商品映射」：这是 `App\Model\SupplierProduct`/`App\Dao\SupplierProductDao`
> 类注释里之前提到的"尚未开工"的创建功能（见第 2 节"本次新增（商品同步）"说明），
> 现已补上。新增 `App\Service\Admin\ProductMappingAdminService` + `App\Controller\Admin\ProductMappingController`
> （`GET/POST /admin/product-mappings`，列表用 `?product_id=` 查询参数过滤；
> `POST /admin/product-mappings/{id}/cost-price`、`POST .../{id}/priority`、
> `POST .../{id}/status`、`PUT /admin/product-mappings/{id}` 改详情），两个权限编码
> `product_mapping.view`（列表）、`product_mapping.manage`（新建 + 全部更新动作），
> 已同步进 `App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS`。核心约束：
> 创建时校验 `supplier.business_line === product.business_line`（movie-only 供应商不能
> 映射到 recharge 商品，源自 6.1「供应商只属于一条业务线」+ 6.4 映射概念的推论，
> 不是需求原文逐字给出）、`(product_id, supplier_id)` 唯一（先查后插 + `QueryException`
> 兜底）。**人工改价复用"改价必留痕"不变量**：`SupplierProductDao::applySync()`
> 重构为转发到新的私有 `writeCostPrice()`，新增的 `SupplierProductDao::updateCostPriceManually()`
> （`source=manual`）跟 `applySync()`（`source=sync`）共用同一个私有方法走同一套
> 事务 + 历史记录逻辑，不可能出现某条改价路径漏记历史；`ProductMappingAdminService`
> 里改价、优先级、状态、详情（`supplier_product_code`/`stock`/`param_mapping`/
> `sale_restrictions`）各自独立成方法，通用的 `updateDetails()` 刻意不接受
> `cost_price`，避免留一个绕过历史记录的后门。`sale_restrictions` 只做透传 JSON
> 校验，不解释其内容（kasushou.md 的 `can_buy`/`can_no_buy`/`can_price` 概念暂未映射
> 到这个字段的具体形状，超出本次任务范围）。**范围之外**：路由跳过逻辑本身
> （6.5 节，读 `priority`/`status`/`stock` 挑供应商）、商品变更通知/全量同步的
> 自动接入（仍是第 2 节标注的 🔨 状态）。测试见
> `test/Cases/Admin/ProductMappingControllerTest.php` + `test/Cases/Dao/SupplierProductDaoTest.php`
> 新增用例。

---

## 9. 异步任务与定时任务

依据：[requirements.md 2.2](requirements.md#22-系统组成)

| 任务 | 触发方式 | 状态 |
|---|---|---|
| 供应商下单异步执行 | 队列 Job | ⬜ |
| 供应商结果查询轮询 | 队列 Job / Crontab | ⬜ |
| 商户回调重试（1/5/15/60/120/360 分钟） | 队列 Job（延迟） | ⬜ |
| 供应商余额监控 | Crontab | ⬜ |
| 供应商商品同步（每日全量校准） | Crontab | ⬜ |
| 熔断自动恢复（二期） | Crontab | ⬜ |
| 城市 / 影院数据批量同步（三期） | Crontab | ⬜ |
| 场次数据批量同步（三期，视权限） | Crontab | ⬜ |
| 返佣到期自动入账 | Crontab | ⬜ |
| 异常单标记 | Crontab | ⬜ |

---

## 10. 进度总览

> 每次更新完各表状态后，手动同步这里的汇总（不做自动计算，避免又要建一个统计脚本）。

| 分类 | 总数 | 已完成 | 开发中 | 未开始 |
|---|---|---|---|---|
| 基础设施与公共能力 | 12 | 6 | 0 | 6 |
| 卡速售 2.0 驱动 | 8 | 1 | 5 | 2 |
| 云洋驱动 | 9 | 0 | 0 | 9 |
| 芒果驱动 | 11 | 0 | 0 | 11 |
| 供应商路由与风控 | 5 | 0 | 0 | 5 |
| 开放 API 接口 | 15 | 4 | 0 | 11 |
| 商户管理后台 | 16 | 4 | 0 | 12 |
| 系统管理后台 | 18 | 4 | 0 | 14 |
| 异步任务与定时任务 | 10 | 0 | 0 | 10 |
| **合计** | **104** | **19** | **5** | **80** |

**建议开发顺序**（按 [10. 分期计划](requirements.md#10-分期计划)）：

1. 第 1、5（部分）、9（部分）节的基础设施 → 第 2 节卡速售驱动 → 第 6/7/8 节里标注一期的话费相关行 → 打通一期闭环
2. 二期：卡券相关行、熔断、告警、对账
3. 三期：第 3、4 节云洋/芒果驱动、快递与电影票相关的第 6/7/8 节行、沙箱环境
