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
| 商户余额冻结/扣款/解冻/返佣结算 | [requirements.md 4.4](requirements.md#44-账户余额)、[4.5](requirements.md#45-负余额)、[5.4](requirements.md#54-返佣到账) | `App\Model\MerchantBalanceLog`（对应 `merchant_balance_logs`，`$timestamps = false`，写一次不改，`dedupe_order_key`/`dedupe_rebate_key` 两个生成列不进 `$fillable`）+ `App\Dao\MerchantBalanceLogDao`；`App\Service\Merchant\BalanceService::freeze()/deduct()/unfreeze()/settleRebate()/recharge()` 五个余额变动原语：`freeze()` 在 `Db::transaction()` 里 `MerchantDao::lockForUpdate()` 锁行、检查可用余额≥金额，够则冻结（可用减、冻结加）写 `freeze` 日志返回 `true`，不够直接返回 `false`（事务内无任何写操作，等效回滚，不记日志）；`deduct()`/`unfreeze()` 结构对称：先按 `dedupe_order_key`（`"{order_id}-{type}"`）唯一索引尝试插日志，插入成功才改余额列，插入因唯一索引撞车抛 `QueryException` 时捕获当幂等 no-op（余额列不会被碰到），不依赖应用层查了再写的预检查。全程 bcmath 字符串运算，不用 float。金额、bcmath 全程 scale=2。`freeze()` 本身没有同订单重复调用的 DB 级防护（`dedupe_order_key` 的生成列 CASE 只覆盖 `deduct`/`unfreeze`，不含 `freeze`）——按下单时序（先冻结、冻结成功才建订单行）没有现成的订单级唯一键可用，这道防线留给下一个任务（下单流程）在调用 `freeze()` 之前用请求幂等键/订单表约束解决。`settleRebate(MerchantRebate $rebate): bool` 是后加的第四个原语（本次"返佣到账"任务）：只加可用余额、不动冻结余额（返佣从没被冻结过），幂等靠同一张表的 `dedupe_rebate_key`（`"{rebate_id}-{type}"`，只覆盖 `rebate_settle`/`rebate_clawback`）唯一索引，同一套"先插日志、插入成功才改余额列"套路；额外在同一个事务里把 `merchant_rebates.status` 改成 `settled`、写 `settled_at`，返回值 `true`/`false` 区分"这次真的结算了"还是"幂等 no-op"，供调用方统计。`recharge(int $merchantId, string $amount, ?string $reason = null): void` 是"充值与调账"任务加的第五个原语：只加可用余额、不动冻结余额，**没有任何幂等保护**——`dedupe_order_key`/`dedupe_rebate_key` 两个生成列的 CASE 表达式都不覆盖 `recharge` 类型，数据库层面拦不住重复调用。这是有意的分工：真正要防的"同一条充值申请只能被批准一次"是请求行自身的状态机问题（`merchant_recharge_requests.status` 从 `pending` 单向翻到 `approved`），不是能塞进生成列的订单/返佣外键，所以幂等责任整体交给调用方 `App\Service\Admin\RechargeRequestAdminService::approve()`（见第 8 节"充值与调账"）：在事务里锁请求行、重新检查 `pending`、翻转状态之后才调用 `recharge()`。`adjust(int $merchantId, string $amount, string $reason, ?int $operatorId): void` 是"手动调账"任务加的第六个原语（requirements.md 4.3）：`amount` 可正可负（正数加、负数扣），格式校验（非零、最多两位小数，允许前导负号）是本方法自己的职责，`reason`/`operatorId` 的合法性校验交给调用方；只加/减可用余额、不动冻结余额；提交即生效，没有二次审核这一层，调用方（`App\Service\Admin\MerchantAdminService::adjustBalance()`）校验完 `reason` 非空之后直接调用，中间不像 `recharge()` 那样有一个请求状态机。**关键判断（写在这里防止以后被任务描述文字带偏）**：requirements.md 4.5「负余额」原文列出的、会让可用余额变负的场景**有三个**——快递补扣、返佣扣回、**财务手动扣款调账**（原文列表第三项，通篇 requirements.md 只有 4.3/4.4/4.5 三处提到"手动调账"，指的是同一个功能，"财务手动扣款调账"就是 `adjust()` 在 `amount` 为负时的这个分支），处理规则"不设下限，必须如实记账"同样适用——所以 `adjust()` **不**校验"扣完是否小于 0"，扣多少扣多少，如实记账，这一点跟"必须拒绝会让余额变负的调账"这种描述是矛盾的，实现以 requirements.md 原文为准（完整推理见 `BalanceService::adjust()` 方法文档注释）。**`merchants.debt_since` 的维护/恢复（本次"负余额暂停下单"任务补上，此前一直是有意留白的半成品）**：六个方法最终改余额列的代码统一收口到新增的私有方法 `BalanceService::persistBalance(Merchant $merchant, string $newAvailable, string $newFrozen): void`，`available_balance`/`frozen_balance`/`debt_since` 在同一次 `save()` 里一起落盘；`debt_since` 只在**真正跨越 0 这条线**时才动——写入前 ≥0、写入后 <0 记下起始时间，写入前 <0、写入后 ≥0 清空，停留在同一侧（含欠款状态下继续被扣得更负）完全不碰这一列，不会把已有的欠款起始时间刷新掉。用 `Carbon::now()` 而不是本类别处一律用的 `date('Y-m-d H:i:s')`，是为了让测试能用 `Carbon::setTestNow()` 精确控制"进入欠款"和"欠款期间再扣一次"两次调用之间的时间差，可靠断言时间戳没被重置，不依赖两次调用凑巧落在系统时钟的不同秒上。本类只负责让 `debt_since` 本身随时正确，**不是**暂停下单判断的真实来源——本次任务新增的 `BalanceService::isSuspended(Merchant $merchant): bool` 才是（直接对 `available_balance` 做一次新鲜 `bccomp < 0`，不读 `debt_since`：后者是派生缓存，正确性依赖每条改余额路径都记得同步，前者永远权威、不可能过期），当前唯一调用方是 `App\Service\Order\RechargeOrderPlacementService::place()`（见下单相关行），未来卡券/电影票/快递下单流程应该复用同一个方法，不要各自重新实现判断逻辑或直接读 `debt_since`。充值/正数调账让余额回到 ≥0 时自动清空 `debt_since`（且 `isSuspended()` 下一次自然读到非负余额），下一次下单请求到达时就已经是"不再欠款"，不需要另外一个"恢复"动作——已用 `adjust()` 扣负 -> 下单被拒 -> `recharge()` 补足 -> 下单成功的完整链路测过，见下单相关行。**欠款预警线（4.5"后台设置统一的欠款预警线"）的最小 scaffolding**：新增 `SystemSettingDao` key `debt_warning_threshold`（零配置时代码级默认值 `1000.00` 元，跟 `rebate_due_period_days` 同一套惯例）+ `BalanceService::isOverDebtWarningThreshold(Merchant $merchant): bool`（欠款绝对值是否超过这条线），**不含任何真正的告警动作**——这个代码库没有邮件/短信/IM 之类能通知内部财务人员的通道，告警本身和商户后台的"醒目提示"UI 都还没建，属于另一个未来任务，这里只是不让那个任务落地时才回来定义"超线"是什么意思。**范围内**：`freeze`/`deduct`/`unfreeze`/`rebate_settle`/`recharge`/`adjustment` 六种流水类型 + `merchants.debt_since` 的维护/恢复 + `isSuspended()` 暂停下单闸门 + 欠款预警线判断（不含告警动作）；**范围外**：`supplement_deduct`/`refund`/`rebate_clawback` 对应的售后补扣/退款、返佣扣回流程（这两个也是会让余额变负的场景，业务流程本身都还没建），欠款预警线的真正告警通道和商户后台"醒目提示"UI（这个代码库没有邮件/短信/IM 之类能通知内部财务人员的通道，商户门户前端也还没有一页读余额状态）。测试见 `test/Cases/Service/Merchant/BalanceServiceTest.php`（含 `deduct`/`unfreeze`/`settleRebate` 各自的重复调用幂等测试 + `adjust()` 的加/扣/扣到负数不设下限/零金额拒绝各用例 + `isSuspended()` 与实时 `bccomp` 双向一致的用例（含 `debt_since` 故意摆成不一致中间态、证明只看余额不看该列）+ 新增的 `debt_since` 穿越 0 用例：`adjust()` 从非负变负、`recharge()` 从负变非负、已欠款时再扣一次不重置起始时间三条）+ `test/Cases/Dao/MerchantDaoTest.php` 新增的行锁相关用例，`recharge()` 本身的行为通过第 7/8 节的充值审核 HTTP 测试间接覆盖（含关键的双重批准只加一次款用例，见第 8 节），`adjust()` 本身通过第 7/8 节的资金流水/手动调账 HTTP 测试间接覆盖 | ✅ |
| 返佣待到账生成 + 到期结算 | [requirements.md 5.4](requirements.md#54-返佣到账) | **生成**：`App\Service\Product\RebateCalculator` 新增 `calculateDetailed()`（`calculate()` 改造成它的薄包装，共用同一份 3 步比例取法，不分叉两套实现），返回 `App\Service\Product\RebateCalculationResult{rate, rateSource: 'product_level'\|'level'\|null, amount}`——比 `calculate()` 只给最终金额多了"用的哪个比例、从哪一层取的"，供生成待到账记录时按 5.4 原文快照"比例及来源"。`App\Model\MerchantRebate` 补上 `$dates`（`order_completed_at`/`due_at`/`settled_at`/`voided_at`/`clawed_back_at`，原来漏加，读出来一直是裸字符串不是 Carbon）；`App\Dao\MerchantRebateDao` 新增 `findByOrderId()`/`findDuePending()`。真正生成的逻辑加在 `App\Service\Order\OrderResultApplier::applySuccess()`（`BalanceService::deduct()` + 通知之后）：按"下单成功这一刻"重新查一次商户等级（不是下单发起时的旧快照）算出返佣，金额 > 0 才插入一条 `merchant_rebates`（`status = pending`，`due_at = order_completed_at + SystemSettingDao::getValue('rebate_due_period_days', 默认 7)`），金额为 0 时**不插入任何记录**；`apply()` 新增可选的 `?Product $product` 参数——`RechargeOrderPlacementService::finalizeOrder()` 把下单时已经查过的 `Product` 直接透传（不重复查询），`SupplierCallbackService::handle()` 处理异步回调时手上没有 `Product`，传 `null`，由 `OrderResultApplier` 按 `order_id` 反查 `order_recharges.product_id` 再查一次；插入撞上 `merchant_rebates.order_id` 唯一索引时捕获 `QueryException` 当幂等 no-op。**系统参数**：新增 `App\Model\SystemSetting` + `App\Dao\SystemSettingDao::getValue()`（`system_settings` 主键是字符串 `key`、只有 `updated_at` 没有 `created_at`，Model 用 `CREATED_AT = null` 覆盖常量处理；`getValue()` 通用解码——先 `json_decode()`，失败就原样返回字符串，具体类型由调用方按 key 约定自己转型），零配置行时代码级默认值兜底（`rebate_due_period_days` 默认 7），无编辑用的后台 UI（本次任务不含，见第 8 节"返佣管理"行）。**结算**：`App\Crontab\RebateSettlementCrontab`（`#[Crontab(rule: '*\/5 * * * *', onOneServer: true)]`，5 分钟一次，需求没规定具体频率）扫 `MerchantRebateDao::findDuePending()`（`status=pending AND due_at<=now()`），逐条调 `BalanceService::settleRebate()`，每条记录各自独立事务、外层 try/catch 包住，一条结算异常不连累同批次其它记录；`settleDueRebates()` 单独暴露成公开方法给测试直接调用，不用等真实 cron tick。**明确不做**（本次任务范围说明里点名排除）：`merchant_rebates.status` 到 `voided`/`clawed_back` 的转换——触发它们的售后争议处理、人工改判订单状态在这个代码库里都还不存在，没有任何代码路径会产生这两种状态；争议期间暂停到账（5.4"争议暂停"一节）同样依赖不存在的争议处理流程；供应商返佣晚到重算（电影票/快递专属，本次只覆盖话费）；返佣固定期限的后台编辑 UI。测试：`test/Cases/Service/Product/RebateCalculatorTest.php`（`calculateDetailed()` 的比例/来源断言，复用 5.3 例 1 的三档 fixture）+ `test/Cases/Service/Order/OrderResultApplierRebateTest.php`（生成：一条记录/零返佣不生成/等级快照/默认与覆盖到账期限/无现成 Product 时的反查 fallback）+ `test/Cases/Service/Merchant/BalanceServiceTest.php` 新增 `settleRebate()` 用例（含双调用幂等）+ `test/Cases/Crontab/RebateSettlementCrontabTest.php`（到期/未到期过滤、重复扫描不重复入账） | ✅ |
| 开放 API IP 白名单校验 | [requirements.md 8.1](requirements.md#81-开放-api) | 读 `merchants.ip_whitelist` | ⬜ |
| 按商户限流中间件 | [requirements.md 8.1](requirements.md#81-开放-api) | 配置读 `merchant_rate_limits`/`system_settings.default_rate_limit_per_second`，计数器走 Redis | ⬜ |
| 统一返回格式与错误码 | [requirements.md 8.1](requirements.md#81-开放-api) | `{code, message, data}`；平台统一错误码，不透传供应商原始信息 | ⬜ |
| 供应商回调入口与验签框架 | [requirements.md 6.8](requirements.md#68-回调日志与统计) | `App\Controller\NotifySupplierController`：`POST /notify/{供应商编码}`，第三套独立于开放 API/系统管理后台的路由命名空间，故意不挂任何既有鉴权中间件——验签本身就是唯一的身份校验，由 `App\Service\Order\SupplierCallbackService::handle()` 按 `suppliers.code`（`SupplierDao::findByCode()`）找到供应商、`SupplierDriverFactory::build()` 建驱动、调驱动的 `parseCallback()`（目前只有卡速售一家，验签失败返回 `null`）验签+拿权威结果，再从 `DriverResult::$rawRequest['external_orderno']`（形如 `"{order_no}-{attemptNo}"`，`order_no` 本身不含 `-`，截第一个 `-` 前面即可）反查平台订单（新增 `OrderDao::findByOrderNo()`，全局查询不限定 merchant_id——`orders.order_no` 本身就是唯一列，且回调到达时还不知道订单属于哪个商户）。响应体是纯文本（`Response::raw()`），不是 `{code,message,data}` 信封，成功/幂等重放固定回复卡速售要求的字面字符串 `ok`（HTTP 200）；供应商编码不存在或反查不到订单 → 404；验签失败 → 403，绝不能回 `ok`。**推进订单状态的"成功/明确失败即解冻+失败/处理中不动"逻辑从 `RechargeOrderPlacementService` 抽成了共享的 `App\Service\Order\OrderResultApplier::apply()`**（同步下单路由循环选出最终结果、和这里推进 `processing` 订单共用同一份状态转换+余额+通知代码，失败换供应商的循环逻辑本身没有抽，仍然只属于同步下单路径，因为订单一旦 `processing` 就已经绑死一次供应商尝试，不可能再背着商户换供应商）。**幂等**：`BalanceService::deduct()/unfreeze()` 本身的 `dedupe_order_key` 唯一索引保证同一 `order_id` 的余额变动只生效一次，`SupplierCallbackService` 在此之上再显式检查——订单已经是 `success`/`failed` 终态就直接短路回复 `ok`，不重新调用 `apply()`，避免供应商按 kasushou.md 描述的间隔（5/10/15/20/25 分钟，最多 5 次）重试同一个回调时被重复推进/重复推送商户通知。测试：`test/Cases/Controller/NotifySupplierControllerTest.php`（`test/HttpTestCase.php` 真实路由派发，覆盖成功/明确失败/处理中三种驱动结果、已终态订单重复回调不被重新应用、验签失败、供应商编码不存在、订单号反查不到六条路径）+ `test/Cases/Service/Order/RechargeOrderPlacementServiceTest.php`（抽取 `OrderResultApplier` 后原样保留，验证同步下单行为未变）。**范围外，留给后续任务**：商品变更通知 webhook（`ProductSyncService::applyNotification()` 已有原语但没有路由，是另一个更小的独立任务，不是本行）、卡速售之外的其它驱动接入这个入口、IP 白名单/限流、`#[Crontab]` 定时按 `external_orderno` 重查询兜底真正丢失的回调（本行只处理"回调正常到达"这一条路径） | ✅ |
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
| 话费下单 | ✅ | ✅ | ✅ | ✅ |<sup>④</sup>
| 卡券商品列表（二期） | ⬜ | ⬜ | ⬜ | ⬜ |
| 卡券下单（二期） | ✅ | ✅ | ✅ | ✅ |<sup>⑤</sup>
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

④ `话费下单`（`POST /open-api/orders/recharge`，requirements.md 8.1「下单」的话费一侧，
卡券参数不同、单独设计，不在本次范围）是第一条打通「商品校验 -> 冻结 -> 供应商路由 ->
判定成败 -> 扣款/解冻 -> 回调通知」的完整下单链路。新增 `App\Model\OrderAttempt` /
`App\Dao\OrderAttemptDao`（`order_attempts` 一次尝试一行，`App\Supplier\UnifiedResult`
四态到 `result` 字符串的映射见该 Model 类注释）、`App\Service\Order\
RechargeOrderPlacementService`（编排本体）、`App\Controller\OpenApi\
RechargeOrderController`。**幂等 + 冻结最多一次**：`orders` 表
`unique(['merchant_id', 'merchant_order_no'])` 约束 + 严格保证 `Order::create()`
先于 `BalanceService::freeze()` 执行，两个并发的重复请求里只有一个能建单成功，
另一个在建单这一步就被数据库唯一约束挡下，根本走不到 `freeze()`，不是业务判断层面
「决定不调用」。**欠款暂停下单（requirements.md 4.5"负余额"，"负余额暂停下单"任务
补上，本次任务把闸门本身收口成可复用方法，详见第 1 节"商户余额冻结/扣款/解冻/
返佣结算"行的 `persistBalance()`/`isSuspended()` 说明）**：`place()` 在幂等重放查找
（`findByMerchantOrderNoForMerchant()`）*之后*、校验商品/建单/冻结*之前*调
`BalanceService::isSuspended($merchant)`（直接对实时 `available_balance` 做一次
新鲜 `bccomp`，不读 `debt_since` 这个派生缓存——理由见该方法文档），命中则直接
`HttpException(422)` 拒绝，不创建任何 `Order` 行、不调用 `freeze()`——位置刻意选在
这里：放在幂等查找之前会连累"欠款之前就已经成功、现在只是被重新提交"的旧订单一起
被拒；放在建单/冻结之后又晚了，白白留下一个本可以避免的 `Order` 行。错误信息/机制
特意跟"这一笔订单金额超过可用余额"（`freeze()` 返回 `false` 那条路径，落一个
`failed` 状态的 `Order` 行）区分开——欠款是账户级别的暂停，跟单笔订单金额无关，
商户需要能分清楚"充哪笔更便宜的商品都没用，先把欠款还上"和"换一笔小一点的订单
再试"这两种不同的处理方式。充值/正数调账让余额回到 ≥0 后自动恢复下单
（`isSuspended()` 下一次调用自然读到非负余额），不需要另外的"恢复"动作/管理后台
按钮，已用 `adjust()` 扣负 -> 下单被拒 -> `recharge()` 补足 -> 下单成功的完整链路
测过（`test/Cases/Service/Order/RechargeOrderPlacementServiceTest.php::
testMerchantRecoversAfterRechargeAndCanPlaceOrderAgain()`）。**范围外，留给未来
任务**：4.5"欠款预警线"对财务的告警通道（这个代码库没有邮件/短信/IM 之类能通知
内部人员的渠道）和商户后台"醒目提示尽快充值"的前端页面——`BalanceService::
isOverDebtWarningThreshold()` 只提供判断本身，不含任何告警动作，商户门户前端
也还没有一页读余额状态。**路由**：按 `supplier_products.priority` 升序迭代，只路由到
`status=active` 且（`stock` 为空或 `stock>0`）、所属 `Supplier.status=active` 的映射行；
只有 `DefiniteFailure` 才换下一个供应商，`Success`/`Processing`/`Unknown` 一律停止路由
（requirements.md 6.2）。**judgment call**：`orders.cost_price` 建单时占位 `'0.00'`，
真的调用了某个供应商才更新；余额不足时订单行仍然持久化并标记 `failed`（建单必须先于
`freeze()`，不存在"从不落一个没机会的订单"这个选项）；`order_no` 格式
`R` + 14 位时间戳 + 6 位随机数字，撞了 `orders_order_no_unique` 有限次重试；
`suppliers.config` 对 `kasushou` 驱动的 JSON 形状 `{"base_url","user_id","api_key"}`
是本次任务定下的事实约定；`supplier_products.param_mapping` 形如
`{"recharge_account": "<供应商侧字段名>"}`，缺失时 fallback 用 `recharge_account`
本身；供应商回调地址（`buildSupplierNotifyUrl()`）是占位实现，真正的 `/notify/{code}`
接收路由不在本次范围。**范围外**：商户业务线开通校验（4.2，跟③同样的限制）、
`merchant_rebates` 真正入账（5.4，`RebateCalculator` 只用来算快照存
`order_recharges.rebate_amount`）、`Processing`/`Unknown` 结果的异步推进（等回调接收
路由或定时查询任务）、卡券/电影票/快递下单（参数不同，单独设计）、熔断（6.6）。
测试见 `test/Cases/Service/Order/RechargeOrderPlacementServiceTest.php`（编排细节，
含幂等重复提交只 freeze 一次的关键断言）+
`test/Cases/OpenApi/RechargeOrderControllerTest.php`（真实 HTTP + 中间件栈）。
**"卡券下单（二期）"任务落地时的回顾性重构**：本行"genuinely business-line-agnostic"
的部分（幂等重放、订单号生成/建单竞态、冻结、路由失败切换循环、尝试记录、结果收尾）
已被整段抽到新的 `App\Service\Order\AbstractOrderPlacementService`，
`RechargeOrderPlacementService` 现在只剩话费专属的商品校验和 `order_recharges` 建行，
`RechargeOrderPlacementServiceTest` 全套既有用例原样通过（未修改任何断言），
证明这次抽取没有改变本行的行为，详见⑤。

⑤ `卡券下单`（`POST /open-api/orders/card`，requirements.md 8.1「下单」的卡券一侧，
原本标注在"二期"，本次任务提前做掉；话费参数不同、单独设计，见④）复用④抽出来的
`App\Service\Order\AbstractOrderPlacementService`（路由/失败切换/尝试记录/结果收尾
完全共享同一份实现，不是两份可能漂移的拷贝），新增
`App\Service\Order\CardOrderPlacementService`（`businessLine()` 返回 `'card'`，
`orderNoPrefix()` 返回 `'C'`，`isCardProduct()` 返回 `true`，每次驱动调用都会用到）、
`App\Controller\OpenApi\CardOrderController`。**卡券专属的业务规则**：
`products.card_type` 只有 `direct`（直充，需要目标账号）、`card_secret`（卡密，
kasushou.md"下单参数"一行原文"卡密商品不传"）两种取值——直充类必须传
`recharge_account`（跟话费复用同一个请求字段名，保持两条业务线参数命名一致），
卡密类禁止传这个参数（传了直接 `HttpException(422)`，不是静默忽略，因为这意味着
调用方对商品类型的理解有误）；这条校验在 `CardOrderPlacementService::
validateRechargeAccountForCardType()`。**卡密落库，共享收益**：`App\Supplier\
DriverResult::$cardList` 存在的目的就是让驱动带出卡号卡密，但此前
`OrderResultApplier::applySuccess()` 从未使用过这个字段——本次任务把"取
`cardList` 第一条（`requirements.md` 7.1"一单一个"）、用 `App\Crypto\Encryptor`
加密后写入 `order_recharges.card_no`/`card_pwd`"这段逻辑补进共享的
`OrderResultApplier`，不是 `CardOrderPlacementService` 私有逻辑，`App\Service\Order\
SupplierCallbackService` 异步回调路径自动获得同样的能力。`cardList` 缺失/为空
（理论上卡类商品在 `KasushouStatusMapper` 的映射规则下不应该出现"Success 但没
card_list"）时防御性地保留 `card_no`/`card_pwd` 为 `null`，不额外记日志（这个类
目前没有引入 `LoggerFactory`），不让订单成功流程崩溃。**返佣**：`App\Service\
Product\RebateCalculator` 对 `business_line = 'card'` 未经改动即可正确工作（只是
按 `products.business_line` 查 `merchant_level_business_rates`），用一条真实建了
`(level_id, business_line='card')` 比例记录的测试确认，不是假设。**范围外**：跟④
同样的限制（商户业务线开通校验、异步推进、熔断），以及"卡券商品列表（二期）"
（本节单独一行，仍是 ⬜，不在本次任务范围）。测试见
`test/Cases/Service/Order/CardOrderPlacementServiceTest.php`（直充/卡密两种
`card_type` 的必填/禁止校验、卡密加密落库并解密还原、失败切换/余额不足/欠款暂停/
幂等重放各留一条代表性用例、拒绝非卡券商品）+
`test/Cases/OpenApi/CardOrderControllerTest.php`（真实 HTTP + 中间件栈）。

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
| 充值：提交申请 / 查看记录 | ✅ `App\Controller\Merchant\RechargeRequestController` | ✅ `App\Service\Merchant\RechargeRequestService` | ✅ |
| 资金流水：查询与导出 | ✅ `App\Controller\Merchant\BalanceLogController` | ✅ `App\Service\Merchant\BalanceLogService` | ✅ 只做"查询"（`GET /merchant/balance-logs`，支持 `?type=` 筛选、分页，见第 8 节"充值与调账"行下方的说明），"导出"不在本次任务范围内 |
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
| 商户管理：资金流水查看（客服/审计，只读） | ✅ `App\Controller\Admin\MerchantController` | ✅ `App\Service\Admin\MerchantAdminService` | ✅ `GET /admin/merchants/{id}/balance-logs`，跟商户自己看到的资金流水同一份数据，用现有 `merchant.view` 权限（看流水跟看详情是同一档权限，没有单独开一档），见第 8 节"充值与调账"行下方的说明 |
| 商户管理：启用禁用 / 调整等级（针对已 active 商户的后续变更）/ 限流设置 | ⬜ | ⬜ | ⬜ |
| 服务开通审核 | ⬜ | ⬜ | ⬜ |
| 充值与调账：充值审核 / 手动调账 | ✅ `App\Controller\Admin\RechargeRequestController`（充值审核）/ ✅ `App\Controller\Admin\MerchantController`（手动调账） | ✅ `App\Service\Admin\RechargeRequestAdminService`（充值审核）/ ✅ `App\Service\Admin\MerchantAdminService`（手动调账） | ✅ 充值申请审核（此前完成）+ 手动加扣余额（调账，`POST /admin/merchants/{id}/balance-adjustments`，`merchant_balance_logs.type = 'adjustment'`，"必填原因，直接生效，不需要二次审核"，独立权限编码 `merchant.balance_adjust`）两半都已完成，见第 1 节"商户余额冻结/扣款/解冻/返佣结算"行 `adjust()` 部分的说明 |
| 本地商品库：CRUD | ⬜ | ⬜ | ⬜ |
| 供应商管理：配置 CRUD（新建/列表/详情/修改/启用禁用，requirements.md 6.3） | ✅ `App\Controller\Admin\SupplierController` | ✅ `App\Service\Admin\SupplierAdminService` | ✅ |
| 供应商管理：商品映射（新建/列表/改价（必留痕）/优先级/启停，requirements.md 6.4） | ✅ `App\Controller\Admin\ProductMappingController` | ✅ `App\Service\Admin\ProductMappingAdminService` | ✅ |
| 供应商管理：商品同步接入 / 余额监控 / 熔断状态 / 调用日志 / 统计 | ⬜ | ⬜ | ⬜ |
| 商户等级：CRUD / 各业务线比例设置 | ✅ `App\Controller\Admin\MerchantLevelController` | ✅ `App\Service\Admin\MerchantLevelAdminService` | ✅ `GET/POST /admin/merchant-levels`、`GET/PUT /admin/merchant-levels/{id}`、`PUT /admin/merchant-levels/{id}/rates/{businessLine}`；权限 `merchant_level.view` / `merchant_level.manage`（已加进 `AdminBootstrapService::KNOWN_PERMISSIONS`）。列表全量不分页（等级是少量配置行）；详情 `rates` 固定含 recharge/card/movie/express 四个 key，`null` = 未设置、`'0.0000'` = 明确设为 0%；比例设置用 `MerchantLevelBusinessRateDao::upsertRate()`（数据库原生 upsert，按 `(level_id, business_line)` 唯一索引原地更新），接受非负、最多 4 位小数、不超过列上限 99.9999 的值，超过 1（100%）照样保存不拒绝（5.5 只要求提示，前端未建）。没有删除接口；不含调整商户所属等级。测试 `test/Cases/Admin/MerchantLevelControllerTest.php`，含写入后 `RebateCalculator` 读到新比例的联调用例。**仍未做**：商品单独覆盖某等级比例（`product_level_rebates` 的后台接口），单独的后续任务 |
| 价格设置：电影票 / 快递加价规则 / 价格预览 | ⬜ | ⬜ | ⬜ |
| 返佣管理：固定期限设置 / 商户返佣明细 / 供应商返佣明细 | ⬜ | ⬜ | ⬜（生成+结算的业务逻辑已在第 1 节"返佣待到账生成 + 到期结算"完成，这行剩下的是后台管理 UI——`rebate_due_period_days` 目前只能直接改 `system_settings` 表、没有编辑接口，也没有"商户返佣明细/供应商返佣明细"的查询列表） |
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

> 「充值与调账：充值申请审核」：requirements.md 4.3，只支持线下打款，暂不做线上
> 支付。新增 `App\Model\MerchantRechargeRequest`（对应 `merchant_recharge_requests`）+
> `App\Dao\MerchantRechargeRequestDao`；商户后台一侧 `App\Controller\Merchant\RechargeRequestController` +
> `App\Service\Merchant\RechargeRequestService`（`POST/GET /merchant/recharge-requests`，
> 方法级 `#[Middleware(MerchantAuthMiddleware::class)]`，列表严格按认证商户自己的
> id 过滤，不接受商户传参覆盖）；管理后台一侧 `App\Controller\Admin\RechargeRequestController` +
> `App\Service\Admin\RechargeRequestAdminService`（`GET /admin/recharge-requests`，
> 支持 `?status=` 过滤；`POST .../{id}/approve`、`POST .../{id}/reject`），两个权限
> 编码 `recharge.view`（列表）、`recharge.manage`（审核通过/驳回），已同步进
> `App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS`。审核通过时调用
> 新增的 `App\Service\Merchant\BalanceService::recharge()` 给商户可用余额加钱（见
> 第 1 节该行）。**并发安全是这个子任务的核心**：`recharge()` 本身对同一笔调用
> 完全没有幂等保护（`merchant_balance_logs` 的 dedupe 生成列不覆盖 `recharge`
> 类型），所以 `RechargeRequestAdminService::approve()` 必须在自己开的
> `Db::transaction()` 里先 `MerchantRechargeRequestDao::lockForUpdate()` 锁住申请行、
> 重新确认 `status === 'pending'`、原子翻成 `approved`，全部成功后才调用
> `recharge()`——`recharge()` 内部又开了一层 `Db::transaction()`，两层嵌套靠
> `Hyperf\Database\Concerns\ManagesTransactions` 的 SAVEPOINT 机制天然合并成同一个
> 物理事务（只有最外层真正 COMMIT，内层异常会一路向外传播导致外层整体
> ROLLBACK），不需要手写补偿逻辑；对同一条已批准请求的第二次 `approve()` 调用会
> 在锁行之后的状态检查上被拒绝（409），根本不会有机会让 `recharge()` 执行第二次。
> `test/Cases/Admin/RechargeRequestControllerTest.php::testDoubleApprovalOnlyCreditsBalanceOnce()`
> 显式在两次 HTTP 调用之间读余额快照断言相等、并断言 `merchant_balance_logs`
> 只有一条 `recharge` 记录，而不是只看第二次响应的状态码。测试见
> `test/Cases/Merchant/RechargeRequestControllerTest.php` + 上述 Admin 测试。
> **范围之外**：文件上传本身（`proof_image` 只接受字符串 URL，跟
> `merchant_qualifications` 的既有约定一致）。
>
> 「充值与调账：手动调账」：requirements.md 4.3，前半"充值申请审核"的独立
> 另一半，现已补上。`App\Service\Merchant\BalanceService` 新增第六个原语
> `adjust(int $merchantId, string $amount, string $reason, ?int $operatorId): void`
> （见第 1 节该行完整说明），管理后台一侧 `App\Controller\Admin\MerchantController::
> adjustBalance()` + `App\Service\Admin\MerchantAdminService::adjustBalance()`
> （`POST /admin/merchants/{id}/balance-adjustments`，body 为 `amount`/`reason`，
> `operator_id` 从 `admin` request attribute 取），新增独立权限编码
> `merchant.balance_adjust`（不是复用 `merchant.view`/`merchant.review`——财务改
> 余额是有实际资金影响的动作，见 `MerchantAdminService::adjustBalance()` 类注释），
> 已同步进 `App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS`。**判断
> 依据 requirements.md 4.5 原文，不设"扣完是否小于 0"的下限校验**（4.5 把"财务
> 手动扣款调账"列为会让可用余额变负的三个穷举场景之一，"不设下限，必须如实
> 记账"），这一点是本次任务里跟通常直觉/字面任务描述不一致、需要特别记录
> 的判断，完整推理见 `BalanceService::adjust()` 方法文档注释；唯一校验的是
> `amount` 格式合法且非零（`BalanceService::adjust()` 自己的职责）、`reason`
> 非空（`MerchantAdminService::adjustBalance()` 校验，比照 `reject()`）。同时
> 补上「资金流水：查询」的读侧——此前 `freeze`/`deduct`/`unfreeze`/`recharge`/
> `settleRebate` 五个原语一直在写 `merchant_balance_logs`，但没有任何接口能把
> 这张表读出来：`App\Dao\MerchantBalanceLogDao` 新增
> `paginateByMerchantId()`/`countByMerchantId()`（按 `created_at DESC, id DESC`
> 排序——`created_at` 只有秒级精度，同一秒内的多条记录需要 `id DESC` 兜底才能
> 保证"最近发生的排最前面"，可选 `?type=` 筛选，白名单是
> `App\Model\MerchantBalanceLog::TYPES`），商户侧
> `App\Controller\Merchant\BalanceLogController` + `App\Service\Merchant\BalanceLogService`
> （`GET /merchant/balance-logs`，方法级 `#[Middleware(MerchantAuthMiddleware::class)]`，
> 严格按认证商户自己的 id 过滤），管理侧复用 `MerchantController`/
> `MerchantAdminService` 新增 `GET /admin/merchants/{id}/balance-logs`（`merchant.view`
> 权限，见第 8 节商户管理表新增的那一行）。测试见
> `test/Cases/Service/Merchant/BalanceServiceTest.php` 新增的 `adjust()` 用例（加/扣/
> 扣到负数不设下限且如实记账/零金额拒绝且不写库/非法格式拒绝且不写库）+
> `test/Cases/Admin/MerchantControllerTest.php` 新增的调账/资金流水 HTTP 用例
> （含 `merchant.balance_adjust` 独立于 `merchant.view` 生效的验证）+
> `test/Cases/Merchant/BalanceLogControllerTest.php`（新文件：倒序排列、`?type=`
> 筛选、非法 type 拒绝、**商户间隔离**——商户 A 的 token 看不到商户 B 的流水、
> 无 token 401）。

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
| 返佣到期自动入账 | Crontab | ✅ `App\Crontab\RebateSettlementCrontab`，见第 1 节"返佣待到账生成 + 到期结算"（只做到账，不含作废/扣回） |
| 异常单标记 | Crontab | ⬜ |

---

## 10. 进度总览

> 每次更新完各表状态后，手动同步这里的汇总（不做自动计算，避免又要建一个统计脚本）。

| 分类 | 总数 | 已完成 | 开发中 | 未开始 |
|---|---|---|---|---|
| 基础设施与公共能力 | 13 | 8 | 0 | 5 |
| 卡速售 2.0 驱动 | 8 | 1 | 5 | 2 |
| 云洋驱动 | 9 | 0 | 0 | 9 |
| 芒果驱动 | 11 | 0 | 0 | 11 |
| 供应商路由与风控 | 5 | 0 | 0 | 5 |
| 开放 API 接口 | 15 | 6 | 0 | 9 |
| 商户管理后台 | 16 | 6 | 0 | 10 |
| 系统管理后台 | 19 | 7 | 0 | 12 |
| 异步任务与定时任务 | 10 | 1 | 0 | 9 |
| **合计** | **106** | **29** | **5** | **72** |

**建议开发顺序**（按 [10. 分期计划](requirements.md#10-分期计划)）：

1. 第 1、5（部分）、9（部分）节的基础设施 → 第 2 节卡速售驱动 → 第 6/7/8 节里标注一期的话费相关行 → 打通一期闭环
2. 二期：卡券相关行、熔断、告警、对账
3. 三期：第 3、4 节云洋/芒果驱动、快递与电影票相关的第 6/7/8 节行、沙箱环境
