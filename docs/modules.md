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
| 开放 API 签名鉴权中间件 | [requirements.md 8.1](requirements.md#81-开放-api) | 验 `app_key`/`timestamp`/`nonce`/`sign`；`nonce` 防重放放 Redis（见 [database-design.md 5.6](database-design.md#56-哪些东西故意不放进这份-mysql-设计)）。`App\Middleware\OpenApiSignatureMiddleware`（PSR-15）：按 `app_key` 查商户（`MerchantDao::findByAppKey`）、校验 `status === active`、用 `Encryptor` 解密 `app_secret`、`SignatureSigner::verify` 验签、校验 `timestamp` ±5 分钟窗口、`NonceGuard::consume` 防重放；验签通过后把 Merchant 通过 `$request->withAttribute('merchant', $merchant)` 传给下一个 handler；失败时直接返回 `{code, message, data}` 形状的 JSON（错误码见本节「统一返回格式与错误码」行，400xx 段）。**未注册进 `config/autoload/middlewares.php` 全局列表**（会连带拦住 `/`、`/users`），在开放 API Controller 上用 `#[Middleware(OpenApiSignatureMiddleware::class)]` 类级注解挂载（**不能**用 `#[Controller(options: ['middleware' => [...]])]`——这个 Hyperf 版本的 `DispatcherFactory::handleController()` 会把 Controller 注解 options 里的 middleware 键整个覆盖掉，只认 `#[Middleware]`/`#[Middlewares]` 注解，那样中间件会静默不生效，真机测试过才发现）。已通过第 6 节"查询余额"接口做了真实 HTTP 派发的端到端联调（`test/Cases/OpenApi/BalanceControllerTest.php`，用 `test/HttpTestCase.php` 走真实路由 + 中间件栈，覆盖正常签名和错误签名两条路径），证明中间件真的挂上了、Merchant 属性真的传下去了，不再是仅靠 mock handler 的单测。中间件本身连同它依赖的 Merchant Model/Dao（见下一行）已经完整可用 | ✅ |
| Merchant / MerchantLevel Model 与 Dao 基础设施 | [database-design.md](database-design.md) merchants / merchant_levels 表 | `App\Model\Merchant`（`hyperf/model-cache`，`ip_whitelist` 转 `array`）、`App\Model\MerchantLevel`；`App\Dao\MerchantDao`（`find()` 重写走 `findFromCache`，新增 `findByAppKey()` 不走缓存、新增 `lockForUpdate()` 加行锁不走缓存）、`App\Dao\MerchantLevelDao`。加密/解密逻辑不放在 Model，留在 Dao/Service/中间件层。单测覆盖按 id（含缓存）、按 app_key 查询、行锁查询（含验证编译出的 SQL 真的带 `for update`），真实 DB 建行后 teardown 清理 | ✅ |
| 商户余额冻结/扣款/解冻/返佣结算 | [requirements.md 4.4](requirements.md#44-账户余额)、[4.5](requirements.md#45-负余额)、[5.4](requirements.md#54-返佣到账) | `App\Model\MerchantBalanceLog`（对应 `merchant_balance_logs`，`$timestamps = false`，写一次不改，`dedupe_order_key`/`dedupe_rebate_key` 两个生成列不进 `$fillable`）+ `App\Dao\MerchantBalanceLogDao`；`App\Service\Merchant\BalanceService::freeze()/deduct()/unfreeze()/settleRebate()/recharge()` 五个余额变动原语：`freeze()` 在 `Db::transaction()` 里 `MerchantDao::lockForUpdate()` 锁行、检查可用余额≥金额，够则冻结（可用减、冻结加）写 `freeze` 日志返回 `true`，不够直接返回 `false`（事务内无任何写操作，等效回滚，不记日志）；`deduct()`/`unfreeze()` 结构对称：先按 `dedupe_order_key`（`"{order_id}-{type}"`）唯一索引尝试插日志，插入成功才改余额列，插入因唯一索引撞车抛 `QueryException` 时捕获当幂等 no-op（余额列不会被碰到），不依赖应用层查了再写的预检查。全程 bcmath 字符串运算，不用 float。金额、bcmath 全程 scale=2。`freeze()` 本身没有同订单重复调用的 DB 级防护（`dedupe_order_key` 的生成列 CASE 只覆盖 `deduct`/`unfreeze`，不含 `freeze`）——按下单时序（先冻结、冻结成功才建订单行）没有现成的订单级唯一键可用，这道防线留给下一个任务（下单流程）在调用 `freeze()` 之前用请求幂等键/订单表约束解决。`settleRebate(MerchantRebate $rebate): bool` 是后加的第四个原语（本次"返佣到账"任务）：只加可用余额、不动冻结余额（返佣从没被冻结过），幂等靠同一张表的 `dedupe_rebate_key`（`"{rebate_id}-{type}"`，只覆盖 `rebate_settle`/`rebate_clawback`）唯一索引，同一套"先插日志、插入成功才改余额列"套路；额外在同一个事务里把 `merchant_rebates.status` 改成 `settled`、写 `settled_at`，返回值 `true`/`false` 区分"这次真的结算了"还是"幂等 no-op"，供调用方统计。`recharge(int $merchantId, string $amount, ?string $reason = null): void` 是"充值与调账"任务加的第五个原语：只加可用余额、不动冻结余额，**没有任何幂等保护**——`dedupe_order_key`/`dedupe_rebate_key` 两个生成列的 CASE 表达式都不覆盖 `recharge` 类型，数据库层面拦不住重复调用。这是有意的分工：真正要防的"同一条充值申请只能被批准一次"是请求行自身的状态机问题（`merchant_recharge_requests.status` 从 `pending` 单向翻到 `approved`），不是能塞进生成列的订单/返佣外键，所以幂等责任整体交给调用方 `App\Service\Admin\RechargeRequestAdminService::approve()`（见第 8 节"充值与调账"）：在事务里锁请求行、重新检查 `pending`、翻转状态之后才调用 `recharge()`。`adjust(int $merchantId, string $amount, string $reason, ?int $operatorId): void` 是"手动调账"任务加的第六个原语（requirements.md 4.3）：`amount` 可正可负（正数加、负数扣），格式校验（非零、最多两位小数，允许前导负号）是本方法自己的职责，`reason`/`operatorId` 的合法性校验交给调用方；只加/减可用余额、不动冻结余额；提交即生效，没有二次审核这一层，调用方（`App\Service\Admin\MerchantAdminService::adjustBalance()`）校验完 `reason` 非空之后直接调用，中间不像 `recharge()` 那样有一个请求状态机。**关键判断（写在这里防止以后被任务描述文字带偏）**：requirements.md 4.5「负余额」原文列出的、会让可用余额变负的场景**有三个**——快递补扣、返佣扣回、**财务手动扣款调账**（原文列表第三项，通篇 requirements.md 只有 4.3/4.4/4.5 三处提到"手动调账"，指的是同一个功能，"财务手动扣款调账"就是 `adjust()` 在 `amount` 为负时的这个分支），处理规则"不设下限，必须如实记账"同样适用——所以 `adjust()` **不**校验"扣完是否小于 0"，扣多少扣多少，如实记账，这一点跟"必须拒绝会让余额变负的调账"这种描述是矛盾的，实现以 requirements.md 原文为准（完整推理见 `BalanceService::adjust()` 方法文档注释）。**`merchants.debt_since` 的维护/恢复（本次"负余额暂停下单"任务补上，此前一直是有意留白的半成品）**：六个方法最终改余额列的代码统一收口到新增的私有方法 `BalanceService::persistBalance(Merchant $merchant, string $newAvailable, string $newFrozen): void`，`available_balance`/`frozen_balance`/`debt_since` 在同一次 `save()` 里一起落盘；`debt_since` 只在**真正跨越 0 这条线**时才动——写入前 ≥0、写入后 <0 记下起始时间，写入前 <0、写入后 ≥0 清空，停留在同一侧（含欠款状态下继续被扣得更负）完全不碰这一列，不会把已有的欠款起始时间刷新掉。用 `Carbon::now()` 而不是本类别处一律用的 `date('Y-m-d H:i:s')`，是为了让测试能用 `Carbon::setTestNow()` 精确控制"进入欠款"和"欠款期间再扣一次"两次调用之间的时间差，可靠断言时间戳没被重置，不依赖两次调用凑巧落在系统时钟的不同秒上。本类只负责让 `debt_since` 本身随时正确，**不是**暂停下单判断的真实来源——本次任务新增的 `BalanceService::isSuspended(Merchant $merchant): bool` 才是（直接对 `available_balance` 做一次新鲜 `bccomp < 0`，不读 `debt_since`：后者是派生缓存，正确性依赖每条改余额路径都记得同步，前者永远权威、不可能过期），当前唯一调用方是 `App\Service\Order\RechargeOrderPlacementService::place()`（见下单相关行），未来卡券/电影票/快递下单流程应该复用同一个方法，不要各自重新实现判断逻辑或直接读 `debt_since`。充值/正数调账让余额回到 ≥0 时自动清空 `debt_since`（且 `isSuspended()` 下一次自然读到非负余额），下一次下单请求到达时就已经是"不再欠款"，不需要另外一个"恢复"动作——已用 `adjust()` 扣负 -> 下单被拒 -> `recharge()` 补足 -> 下单成功的完整链路测过，见下单相关行。**欠款预警线（4.5"后台设置统一的欠款预警线"）的最小 scaffolding**：新增 `SystemSettingDao` key `debt_warning_threshold`（零配置时代码级默认值 `1000.00` 元，跟 `rebate_due_period_days` 同一套惯例）+ `BalanceService::isOverDebtWarningThreshold(Merchant $merchant): bool`（欠款绝对值是否超过这条线），**不含任何真正的告警动作**——这个代码库没有邮件/短信/IM 之类能通知内部财务人员的通道，告警本身和商户后台的"醒目提示"UI 都还没建，属于另一个未来任务，这里只是不让那个任务落地时才回来定义"超线"是什么意思。**范围内**：`freeze`/`deduct`/`unfreeze`/`rebate_settle`/`recharge`/`adjustment` 六种流水类型 + `merchants.debt_since` 的维护/恢复 + `isSuspended()` 暂停下单闸门 + 欠款预警线判断（不含告警动作）；**范围外**：`supplement_deduct`/`refund`/`rebate_clawback` 对应的售后补扣/退款、返佣扣回流程（这两个也是会让余额变负的场景，业务流程本身都还没建），欠款预警线的真正告警通道和商户后台"醒目提示"UI（这个代码库没有邮件/短信/IM 之类能通知内部财务人员的通道，商户门户前端也还没有一页读余额状态）。测试见 `test/Cases/Service/Merchant/BalanceServiceTest.php`（含 `deduct`/`unfreeze`/`settleRebate` 各自的重复调用幂等测试 + `adjust()` 的加/扣/扣到负数不设下限/零金额拒绝各用例 + `isSuspended()` 与实时 `bccomp` 双向一致的用例（含 `debt_since` 故意摆成不一致中间态、证明只看余额不看该列）+ 新增的 `debt_since` 穿越 0 用例：`adjust()` 从非负变负、`recharge()` 从负变非负、已欠款时再扣一次不重置起始时间三条）+ `test/Cases/Dao/MerchantDaoTest.php` 新增的行锁相关用例，`recharge()` 本身的行为通过第 7/8 节的充值审核 HTTP 测试间接覆盖（含关键的双重批准只加一次款用例，见第 8 节），`adjust()` 本身通过第 7/8 节的资金流水/手动调账 HTTP 测试间接覆盖 | ✅ |
| 返佣待到账生成 + 到期结算 | [requirements.md 5.4](requirements.md#54-返佣到账) | **生成**：`App\Service\Product\RebateCalculator` 新增 `calculateDetailed()`（`calculate()` 改造成它的薄包装，共用同一份 3 步比例取法，不分叉两套实现），返回 `App\Service\Product\RebateCalculationResult{rate, rateSource: 'product_level'\|'level'\|null, amount}`——比 `calculate()` 只给最终金额多了"用的哪个比例、从哪一层取的"，供生成待到账记录时按 5.4 原文快照"比例及来源"。`App\Model\MerchantRebate` 补上 `$dates`（`order_completed_at`/`due_at`/`settled_at`/`voided_at`/`clawed_back_at`，原来漏加，读出来一直是裸字符串不是 Carbon）；`App\Dao\MerchantRebateDao` 新增 `findByOrderId()`/`findDuePending()`。真正生成的逻辑加在 `App\Service\Order\OrderResultApplier::applySuccess()`（`BalanceService::deduct()` + 通知之后）：按"下单成功这一刻"重新查一次商户等级（不是下单发起时的旧快照）算出返佣，金额 > 0 才插入一条 `merchant_rebates`（`status = pending`，`due_at = order_completed_at + SystemSettingDao::getValue('rebate_due_period_days', 默认 7)`），金额为 0 时**不插入任何记录**；`apply()` 新增可选的 `?Product $product` 参数——`RechargeOrderPlacementService::finalizeOrder()` 把下单时已经查过的 `Product` 直接透传（不重复查询），`SupplierCallbackService::handle()` 处理异步回调时手上没有 `Product`，传 `null`，由 `OrderResultApplier` 按 `order_id` 反查 `order_recharges.product_id` 再查一次；插入撞上 `merchant_rebates.order_id` 唯一索引时捕获 `QueryException` 当幂等 no-op。**系统参数**：新增 `App\Model\SystemSetting` + `App\Dao\SystemSettingDao::getValue()`（`system_settings` 主键是字符串 `key`、只有 `updated_at` 没有 `created_at`，Model 用 `CREATED_AT = null` 覆盖常量处理；`getValue()` 通用解码——先 `json_decode()`，失败就原样返回字符串，具体类型由调用方按 key 约定自己转型），零配置行时代码级默认值兜底（`rebate_due_period_days` 默认 7），无编辑用的后台 UI（本次任务不含，见第 8 节"返佣管理"行）。**结算**：`App\Crontab\RebateSettlementCrontab`（`#[Crontab(rule: '*\/5 * * * *', onOneServer: true)]`，5 分钟一次，需求没规定具体频率）扫 `MerchantRebateDao::findDuePending()`（`status=pending AND due_at<=now()`），逐条调 `BalanceService::settleRebate()`，每条记录各自独立事务、外层 try/catch 包住，一条结算异常不连累同批次其它记录；`settleDueRebates()` 单独暴露成公开方法给测试直接调用，不用等真实 cron tick。**明确不做**（本次任务范围说明里点名排除）：`merchant_rebates.status` 到 `voided`/`clawed_back` 的转换——触发它们的售后争议处理、人工改判订单状态在这个代码库里都还不存在，没有任何代码路径会产生这两种状态；争议期间暂停到账（5.4"争议暂停"一节）同样依赖不存在的争议处理流程；供应商返佣晚到重算（电影票/快递专属，本次只覆盖话费）；返佣固定期限的后台编辑 UI。测试：`test/Cases/Service/Product/RebateCalculatorTest.php`（`calculateDetailed()` 的比例/来源断言，复用 5.3 例 1 的三档 fixture）+ `test/Cases/Service/Order/OrderResultApplierRebateTest.php`（生成：一条记录/零返佣不生成/等级快照/默认与覆盖到账期限/无现成 Product 时的反查 fallback）+ `test/Cases/Service/Merchant/BalanceServiceTest.php` 新增 `settleRebate()` 用例（含双调用幂等）+ `test/Cases/Crontab/RebateSettlementCrontabTest.php`（到期/未到期过滤、重复扫描不重复入账） | ✅ |
| 开放 API IP 白名单校验 | [requirements.md 8.1](requirements.md#81-开放-api) | 读 `merchants.ip_whitelist`（json 数组，商户后台 `DevSettingsService::updateIpWhitelist()` 写入，只接受单个 IP）。**没有另挂中间件**，直接并进 `App\Middleware\OpenApiSignatureMiddleware`：所有开放 API Controller 都已挂它，不会漏挂，也不用按 app_key 再查一次商户。顺序按 requirements.md 7.1 时序图「验签 → IP 白名单 → 防重放」：验签通过后才查白名单（没有 AppSecret 探测不到白名单配置），放在 timestamp/nonce 之前（被拒请求不消耗 nonce）；拒绝时返回 HTTP 403 + `{code: 40008, message: "来源 IP 不在白名单内", data: null}`（错误码见「统一返回格式与错误码」行），响应不回显 IP/白名单，另写一条 `open_api` 渠道 warning 日志。判定规则在 `App\Network\IpWhitelist`（需求没写清、本任务自定的行为）：**null/空数组 = 未配置，放行**；**只做精确 IP 匹配，不支持 CIDR**（与写入端一致，`10.0.0.0/8` 视为非法条目）；IPv6 规范化后比较、IPv4-mapped IPv6 等同于对应 IPv4；**格式异常失败即拒绝**——字段不是数组直接拒绝，数组里非法条目忽略、其余照常匹配，非空但无合法条目等于全部拒绝；客户端 IP 无法确定时拒绝。客户端 IP 由 `App\Network\ClientIpResolver` 解析：**默认不信任任何转发头**，只取 Swoole 的 `remote_addr`，伪造 `X-Forwarded-For`/`X-Real-IP` 无效；部署在反向代理后需配置 `.env` 的 `OPEN_API_TRUSTED_PROXIES`（逗号分隔 IP/CIDR），仅当 `remote_addr` 是可信代理时才从右往左解析 `X-Forwarded-For`，取第一个非可信代理地址（链中有非法条目 → 无法确定 → 拒绝），不认 `X-Real-IP`/`Forwarded`。测试：`test/Cases/Network/IpWhitelistTest.php`、`ClientIpResolverTest.php`（纯单测）+ `test/Cases/Middleware/OpenApiSignatureMiddlewareTest.php`（放行/拒绝且不消耗 nonce/空与 null/格式异常/伪造转发头/可信代理链/验签先于白名单）+ `test/Cases/OpenApi/IpWhitelistTest.php`（真实路由派发端到端） | ✅ |
| 按商户限流中间件 | [requirements.md 8.1](requirements.md#81-开放-api) | 配置读 `merchant_rate_limits`/`system_settings.default_rate_limit_per_second`，计数器走 Redis。跟 IP 白名单一样**没有另挂中间件**，并进 `App\Middleware\OpenApiSignatureMiddleware`（所有开放 API Controller 都挂了它，不会漏挂，也不用再查一次商户），放在防重放之后、按 requirements.md 7.1 时序图「验签 / IP 白名单 / 防重放 / 限流」的顺序：只有通过全部鉴权的请求才计入配额，伪造签名、重放请求耗不掉正常商户的额度。超限返回 HTTP 429 + `{code: 40009, message: "请求过于频繁，请稍后再试", data: null}`（错误码见「统一返回格式与错误码」行）。**限流值**由新增的 `App\Service\Merchant\RateLimitSettingService::effectiveLimit()` 决定：有单独配置用单独配置，否则用全局默认，全局默认缺失或不是正整数时兜底 50；后台商户详情（`MerchantAdminService`）也改成复用它，两处不再各写一份。**计数器** `App\Signature\MerchantRateLimiter`：按自然秒的固定窗口，key `open_api:rate_limit:{merchant_id}:{unix秒}`，`INCR` + 首次 `EXPIRE 2` 放在一段 Lua 里原子执行，每次请求一次 Redis 调用；固定窗口在秒交界处最多放过 2 倍限额的突发，作为保护性限流可以接受。**Redis/配置读取出错时放行**并写 `open_api` 渠道 error 日志（限流是保护措施，资金安全靠冻结和幂等，不值得因 Redis 故障让全部商户下单失败）。每次请求会按主键读一次 `merchant_rate_limits`，没有单独配置时再读一次 `system_settings`，暂未加缓存。测试：`test/Cases/Signature/MerchantRateLimiterTest.php`（真实 Redis：限额内放行/超限拒绝、商户互不影响、下一秒重置、key 过期）、`test/Cases/Service/Merchant/RateLimitSettingServiceTest.php`（单独配置优先、全局默认、非法默认兜底）、`test/Cases/Middleware/OpenApiSignatureMiddlewareTest.php`（超限 429、验签失败/时间戳过期不占配额、限流器异常放行） | ✅ |
| 统一返回格式与错误码 | [requirements.md 8.1](requirements.md#81-开放-api) | `{code, message, data}`；平台统一错误码，不透传供应商原始信息。**错误码表**集中在 `App\OpenApi\ErrorCode`（枚举，码 + 平台文案 + HTTP 状态），此前散落在中间件和各 Controller 里的占位编号全部收编：400xx 鉴权与安全（40001–40009 保持原编号）、410xx 请求参数（41001 参数缺失/格式错误，收编原 40010/40020/40021；41002 不支持的业务线，原 40011）、420xx 业务校验（42001 商品不存在、42002 未上架、42003 商品不属于该业务线、42004 商户欠款暂停下单、42005 订单不存在，原 40404；42006 商品暂不可售——没有任何可用供应商时下单直接拒绝，见第 5 节）、430xx 订单失败原因、490xx 通用（49001 接口不存在、49002 方法不对、49003 其它请求错误）、50000 系统错误。文案统一改成中文平台文案。**HTTP 状态码约定**：鉴权与安全类 401/403/429，参数和业务类一律 200（以 `code` 为准），接口不存在 404、方法不对 405、系统错误 500；无论哪种 body 都是信封。**信封出口**：成功/Controller 业务失败走 `AbstractOpenApiController::success()/fail(ErrorCode)`；中间件用 `App\OpenApi\ApiResponse::error()`；下单 Service 的商品/欠款/参数校验从 `HttpException(404/422)`（此前返回纯文本，不是信封）改为抛 `App\Exception\OpenApiException`；新增 `App\Exception\Handler\OpenApiExceptionHandler`（`#[ExceptionHandler(priority: 100)]`，只处理 `/open-api` 前缀）兜住这个前缀下所有异常：`OpenApiException` 按自带码返回，框架 `HttpException` 映射到 490xx 且不回显原文，其它异常写 `open_api` error 日志后返回 50000，不回显任何内部信息。**订单失败原因不透传**：`orders.fail_reason` 只写平台文案（`ErrorCode::InsufficientBalance`/`NoSupplierAvailable`/`OrderFailed` 的 message），此前 `OrderResultApplier` 会把驱动给的原始原因（如 `kasushou: order status 4`）直接写进去并经下单返回、订单查询、商户回调三个出口暴露；现在原始原因只留在 `order_attempts.fail_reason`（同步下单和异步回调都会写到对应那次尝试上）。三个出口统一用 `ErrorCode::presentOrderFailure()` 输出 `fail_code` + `fail_reason`，按文案反查码，不认识的旧值一律输出 43003「订单处理失败」，保证永远只出平台文案。测试：`test/Cases/OpenApi/ErrorCodeTest.php`（分段、文案唯一、HTTP 状态、失败原因映射与不透传）、`test/Cases/OpenApi/ErrorEnvelopeTest.php`（未知接口 404/方法不对 405 信封、内部异常 500 不泄露、只处理 `/open-api` 前缀）、`test/Cases/OpenApi/OrderControllerTest.php` 新增库里存着供应商原文时订单查询只输出平台码和文案；其余开放 API/下单 Service 测试按新编号更新 | ✅ |
| 供应商回调入口与验签框架 | [requirements.md 6.8](requirements.md#68-回调日志与统计) | `App\Controller\NotifySupplierController`：`POST /notify/{供应商编码}`，第三套独立于开放 API/系统管理后台的路由命名空间，故意不挂任何既有鉴权中间件——验签本身就是唯一的身份校验，由 `App\Service\Order\SupplierCallbackService::handle()` 按 `suppliers.code`（`SupplierDao::findByCode()`）找到供应商、`SupplierDriverFactory::build()` 建驱动、调驱动的 `parseCallback()`（目前只有卡速售一家，验签失败返回 `null`）验签+拿权威结果，再从 `DriverResult::$rawRequest['external_orderno']`（形如 `"{order_no}-{attemptNo}"`，`order_no` 本身不含 `-`，截第一个 `-` 前面即可）反查平台订单（新增 `OrderDao::findByOrderNo()`，全局查询不限定 merchant_id——`orders.order_no` 本身就是唯一列，且回调到达时还不知道订单属于哪个商户）。响应体是纯文本（`Response::raw()`），不是 `{code,message,data}` 信封，成功/幂等重放固定回复卡速售要求的字面字符串 `ok`（HTTP 200）；供应商编码不存在或反查不到订单 → 404；验签失败 → 403，绝不能回 `ok`。**推进订单状态的"成功/明确失败即解冻+失败/处理中不动"逻辑从 `RechargeOrderPlacementService` 抽成了共享的 `App\Service\Order\OrderResultApplier::apply()`**（同步下单路由循环选出最终结果、和这里推进 `processing` 订单共用同一份状态转换+余额+通知代码，失败换供应商的循环逻辑本身没有抽，仍然只属于同步下单路径，因为订单一旦 `processing` 就已经绑死一次供应商尝试，不可能再背着商户换供应商）。**幂等**：`BalanceService::deduct()/unfreeze()` 本身的 `dedupe_order_key` 唯一索引保证同一 `order_id` 的余额变动只生效一次，`SupplierCallbackService` 在此之上再显式检查——订单已经是 `success`/`failed` 终态就直接短路回复 `ok`，不重新调用 `apply()`，避免供应商按 kasushou.md 描述的间隔（5/10/15/20/25 分钟，最多 5 次）重试同一个回调时被重复推进/重复推送商户通知。测试：`test/Cases/Controller/NotifySupplierControllerTest.php`（`test/HttpTestCase.php` 真实路由派发，覆盖成功/明确失败/处理中三种驱动结果、已终态订单重复回调不被重新应用、验签失败、供应商编码不存在、订单号反查不到六条路径）+ `test/Cases/Service/Order/RechargeOrderPlacementServiceTest.php`（抽取 `OrderResultApplier` 后原样保留，验证同步下单行为未变）。**范围外，留给后续任务**：商品变更通知 webhook（`ProductSyncService::applyNotification()` 已有原语但没有路由，是另一个更小的独立任务，不是本行）、卡速售之外的其它驱动接入这个入口、IP 白名单/限流、`#[Crontab]` 定时按 `external_orderno` 重查询兜底真正丢失的回调（本行只处理"回调正常到达"这一条路径） | ✅ |
| 后台角色权限中间件 | [requirements.md 8.3](requirements.md#83-系统管理后台webadmin) | 系统管理后台（web/admin）第三套独立鉴权体系，跟商户端 JWT（`MERCHANT_JWT_SECRET`）、开放 API HMAC 签名都不共用任何密钥/中间件类。登录态：`App\Auth\AdminJwtGuard`（HS256，独立密钥 `ADMIN_JWT_SECRET`，TTL 8 小时——比商户端 7 天短，管理员权限更高），`App\Middleware\AdminAuthMiddleware` 解出 `AdminUser` 挂 `$request->withAttribute('admin', ...)`。角色权限校验：新增 `App\Annotation\RequiresPermission`（纯 PHP attribute，标在 Controller 方法上声明权限编码）+ `App\Middleware\AdminPermissionMiddleware`（通过 `Dispatched::$handler->callback` 反射读方法上的 `#[RequiresPermission]`，没有则放行，有则查 `App\Dao\AdminRolePermissionDao::roleHasPermission(role_id, code)` 判断，不通过 403）。两个中间件都以方法级 `#[Middleware(...)]` 挂载，`AdminAuthMiddleware` 必须写在 `AdminPermissionMiddleware` 前面（同优先级时 Hyperf 按注解书写顺序 FIFO 执行），已用真实 HTTP 派发验证顺序正确（`test/Cases/Admin/MerchantControllerTest.php::testNoTokenAtAllReturns401NotAPermissionError`：没 token 时 401 而不是拿不到 admin attribute 崩 500）。首个真实落地的受保护接口见第 8 节「商户管理：列表」。**账号开通**：管理员账号非自助注册，此前没有任何 API/工具能创建 `admin_users` 记录，测试只能直接插 Model；现已补上 `App\Command\CreateAdminCommand`（`docker exec pf php bin/hyperf.php admin:create --username= --password= [--real-name=]`，幂等可重复执行）+ `App\Service\Admin\AdminBootstrapService`：find-or-create `super_admin` 角色、find-or-create 当前已知权限（`AdminBootstrapService::KNOWN_PERMISSIONS`，每新增一个 `#[RequiresPermission]` 都要同步进这个数组，否则该权限在 DB 里不存在，所有角色对它的检查都会被判定为无权限）并授权给该角色、创建 `AdminUser`（bcrypt 哈希密码）。**已有的超级管理员不会自动拿到新权限**：每次上线带了新权限编码后执行一次 `php bin/hyperf.php admin:sync-permissions`（`App\Command\SyncAdminPermissionsCommand` → `AdminBootstrapService::syncSuperAdminPermissions()`，补齐缺的权限行和授权，幂等，输出补了几个；还没有 `super_admin` 角色时不建角色，只提示先用 `admin:create`）。测试见 `test/Cases/Service/Admin/AdminBootstrapServiceTest.php` + `test/Cases/Command/CreateAdminCommandTest.php` + `test/Cases/Command/SyncAdminPermissionsCommandTest.php` | ✅ |
| 异步队列消费进程 | [hyperf-conventions](../.claude/skills/hyperf-conventions/SKILL.md) | 继承 `ConsumerProcess` 并 `#[Process]` 注册，别忘了这步——注解本身不会自动生效。`App\Process\QueueConsumerProcess`（`#[Process(name: 'async-queue')]`）其实在最初的 CRUD 模板提交里就已建好，本表一直没同步；已在运行容器里确认 `async-queue.0` 进程存在，`NotifyMerchantJob` 的延迟重推依赖它。配置 `config/autoload/async_queue.php`（Redis 驱动，`brPop` 等待 2 秒，单任务处理超时 10 秒，1 个进程、并发 10）。**顺带修复的稳定性问题**：框架 Redis 连接的 `timeout`/`read_timeout` 默认都是 0（永不超时），2026-09-17 实测一次到 `192.168.1.12` 的 Redis 读错误后，定时任务整整停了 2 小时 5 分钟，正好是容器 `tcp_keepalive_time` 7200 秒——进程卡在一次读上直到内核判定连接死亡。`config/autoload/redis.php` 新增 `timeout`（`REDIS_TIMEOUT`，默认 3 秒）和 `read_timeout`（`REDIS_READ_TIMEOUT`，默认 5 秒，必须大于 `brPop` 的 2 秒，否则空队列的正常等待会被当成读超时），已同步 `.env.example`。测试：`test/Cases/Process/BackgroundProcessRegistrationTest.php`（两个进程的 `#[Process]` 注册与父类、`crontab.enable`、返佣入账 `onOneServer`、Redis 读超时有界且大于队列等待时间；已反向验证读超时为 0 时该用例失败） | ✅ |
| 定时任务调度进程 | [hyperf-conventions](../.claude/skills/hyperf-conventions/SKILL.md) | 继承 `CrontabDispatcherProcess` 并 `#[Process]` 注册，同上。`App\Process\CrontabDispatcherProcess`（`#[Process(name: 'crontab-dispatcher')]`）同样早已建好，`config/autoload/crontab.php` 的 `enable` 为 true；运行容器里确认 `crontab-dispatcher.0` 存在，日志里 `Heartbeat`（每分钟，兼作调度存活信号）和 `RebateSettlement`（每 5 分钟，`onOneServer`）都在按时执行。Redis 超时修复和测试见上一行 | ✅ |

---

## 2. 供应商驱动 - 卡速售 2.0（话费、卡券）

依据：[requirements.md 6.2](requirements.md#62-对接驱动的统一能力)、[kasushou.md](suppliers/kasushou.md)

| 能力 | Driver 方法 | Service 接入 | 单测 | 状态 |
|---|---|---|---|---|
| 下单 | ✅ `App\Supplier\Kasushou\KasushouDriver::placeOrder()` | ✅ `App\Service\Order\SupplierRouter`（同步下单与失败切换，见第 5 节） | ✅ `test/Cases/Supplier/Kasushou/KasushouDriverTest.php` | ✅ |
| 查询订单 | ✅ `KasushouDriver::queryOrder()` | ✅ `App\Service\Order\SupplierResultPollingService`（定时查询，见第 9 节） | ✅ 同上 + `SupplierResultPollingServiceTest` | ✅ |
| 解析回调（含验签） | ✅ `KasushouDriver::parseCallback()` + `App\Supplier\Kasushou\KasushouSigner` | ✅ `App\Service\Order\SupplierCallbackService`（回调入口见第 1 节） | ✅ `KasushouDriverTest` + `KasushouSignerTest` + `NotifySupplierControllerTest` | ✅ |
| 查询余额 | ✅ `KasushouDriver::queryBalance()` | ✅ `App\Service\Supplier\SupplierBalanceService`（余额监控，见第 9 节） | ✅ `KasushouDriverTest` + `SupplierBalanceServiceTest` | ✅ |
| 同步商品（成本价/状态/库存） | ✅ `KasushouDriver::parseProductChangeNotification()`（验签见 `KasushouSigner::verifyProductChangeNotification()`）+ `queryProductDetail()` + `syncAllProducts()` | ✅ `App\Service\Supplier\ProductSyncService`：商品变更通知 `POST /notify/{code}/goods`（`handleNotification()`）+ 每日全量校准（`App\Crontab\SupplierProductSyncCrontab` → `syncAllSuppliers()`），见第 9 节 | ✅ `KasushouSignerTest`（验签，含 id+time 之外字段不参与签名的用例）、`KasushouDriverTest`（三个新方法）、`ProductSyncServiceTest`（映射命中/未命中、价格是否变化触发历史记录）、`SupplierProductDaoTest`（`applySync()` 改价必留痕，Dao 层直接单测）、`NotifySupplierControllerTest`（通知路由 200/403/404） | ✅ |
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
| 固定优先级路由与失败切换 | [requirements.md 6.5](requirements.md#65-路由与失败切换) | ✅ |
| 切换时长限制 | [requirements.md 6.5](requirements.md#65-路由与失败切换) | ✅ |
| 熔断判定与自动恢复（二期） | [requirements.md 6.6](requirements.md#66-熔断) | ⬜ |
| 供应商商品成本价同步任务（卡速售自动，其余人工） | [requirements.md 6.4](requirements.md#64-商品映射与成本价) | ✅ 自动：见第 9 节"供应商商品同步"；人工：后台商品映射改价（第 8 节） |
| 结果未知 / 明确失败归类的统一处理框架 | [requirements.md 6.2](requirements.md#62-对接驱动的统一能力) | ⬜ |

**路由与失败切换（6.5）**：`App\Service\Order\SupplierRouter`，话费、卡券共用；同步下单
（`AbstractOrderPlacementService::routeAndFinalize()`）和供应商异步回调
（`SupplierCallbackService`）都走它。
- 筛选：映射行非 active、库存为 0、供应商停用、供应商余额已知且低于成本价的去掉；熔断是二期，暂不筛。按 `priority` 升序，每家最多一次（`order_attempts` 里出现过的供应商不再尝试）。
- 下单前预检：没有可用供应商时直接返回 42006「商品暂不可售」，不建单、不冻结。预检之后到路由之间供应商状态变了、一家都没试成，仍按旧逻辑失败解冻（43002）。
- 只有明确失败才换下一家；受理后异步回调给出明确失败也换，平台订单号不变，供应商侧单号用 `{order_no}-{attempt_no}` 区分。处理中/结果未知停在当前供应商。
- 切换时长：`system_settings.switch_duration_minutes`（默认 30），从 `orders.created_at` 起算；超时后明确失败不再换，订单失败、解冻。只影响"换不换"，不影响等结果的订单。后台配置入口还没做，目前改表生效。
- 并发：调用供应商前先 `OrderAttemptDao::claim()` 插入 `processing` 行占住下一个 `attempt_no`（唯一索引），重复并发的失败回调只有一个能真正去下单。旧尝试的迟到/重推回调按 `attempt_no` + 供应商跟最新尝试对不上识别，只回 `ok` 不应用。
- 卡速售回调需要 `isCardProduct`：验签前用回调里原始单号找订单只用来选解析方式，验签后权威单号必须指向同一笔订单。
- `order_attempts.fail_reason` 写入时截断到 255（驱动构造失败的异常信息可能超长，此前会让下单请求 500）。
- 测试：`test/Cases/Service/Order/SupplierRouterTest.php`（异步失败切换、超出切换时长、最后一家失败、过期回调、序号占位），`RechargeOrderPlacementServiceTest` 的筛选/无供应商用例，两个下单 Controller 测试。

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
`notify(int $orderId)`，以 delay=0 派发首次尝试）。订单成功/失败时由 `App\Service\Order\OrderResultApplier`
调用 `MerchantNotifyService::notify()`（后来的下单/路由任务接上的）；取消、已退款所在的流程还没建。

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
`OpenApiException(ErrorCode::MerchantSuspended)`（42004）拒绝，不创建任何 `Order` 行、不调用 `freeze()`——位置刻意选在
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
`orderNoPrefix()` 返回 `'C'`；驱动需要的 `isCardProduct` 后来改由 `SupplierRouter` 按
`orders.business_line` 决定，见第 5 节）、
`App\Controller\OpenApi\CardOrderController`。**卡券专属的业务规则**：
`products.card_type` 只有 `direct`（直充，需要目标账号）、`card_secret`（卡密，
kasushou.md"下单参数"一行原文"卡密商品不传"）两种取值——直充类必须传
`recharge_account`（跟话费复用同一个请求字段名，保持两条业务线参数命名一致），
卡密类禁止传这个参数（传了直接返回 41001 参数错误，不是静默忽略，因为这意味着
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
| 商户管理：启用禁用 / 调整等级（针对已 active 商户的后续变更）/ 限流设置 | ✅ `App\Controller\Admin\MerchantController` | ✅ `App\Service\Admin\MerchantAdminService` | ✅ `POST /admin/merchants/{id}/status`、`PUT /admin/merchants/{id}/level`、`PUT/DELETE /admin/merchants/{id}/rate-limit`，权限 `merchant.manage`，详见下方说明 |
| 服务开通审核 | ⬜ | ⬜ | ⬜ |
| 充值与调账：充值审核 / 手动调账 | ✅ `App\Controller\Admin\RechargeRequestController`（充值审核）/ ✅ `App\Controller\Admin\MerchantController`（手动调账） | ✅ `App\Service\Admin\RechargeRequestAdminService`（充值审核）/ ✅ `App\Service\Admin\MerchantAdminService`（手动调账） | ✅ 充值申请审核（此前完成）+ 手动加扣余额（调账，`POST /admin/merchants/{id}/balance-adjustments`，`merchant_balance_logs.type = 'adjustment'`，"必填原因，直接生效，不需要二次审核"，独立权限编码 `merchant.balance_adjust`）两半都已完成，见第 1 节"商户余额冻结/扣款/解冻/返佣结算"行 `adjust()` 部分的说明 |
| 本地商品库：CRUD | ✅ `App\Controller\Admin\ProductController` | ✅ `App\Service\Admin\ProductAdminService` | ✅ `GET/POST /admin/products`、`GET/PUT /admin/products/{id}`、`POST /admin/products/{id}/status`、`PUT/DELETE /admin/products/{id}/level-rebates/{levelId}`；权限 `product.view` / `product.manage`（已加进 `AdminBootstrapService::KNOWN_PERMISSIONS`）。这是 `App\Model\Product`/`App\Dao\ProductDao`（commit 233d5d9）此前一直缺失的写入侧——那次提交只建了模型和开放 API 用的只读查询，商品行此前只能靠测试直接用 Dao 插入。新建商品默认下架（`status` 默认 `off_shelf`：避免刚建好、字段可能还没配置齐全的商品被意外立即上架，需运营显式上架）；`business_line` 只接受 recharge/card（这个代码库目前只有这两条业务线建了下单路由基础设施，即便数据库列本身不限制取值也主动拒绝其它值）；按 recharge/card 分别要求 `operator`/`card_type` 必填，供错业务线的字段（如给 recharge 商品传 `card_type`）直接拒绝，清晰 4xx 而非静默接受；详情接口带出该商品全部 `ProductLevelRebate` 覆盖（联表 `level_name`，同 `ProductMappingAdminService` 联表供应商名称的做法）。等级比例覆盖的写入/删除结构跟商户等级任务（commit 4de61e8）一致：设置用 `ProductLevelRebateDao::upsertRate()`（数据库原生 upsert，按 `(product_id, level_id)` 唯一索引原地更新，跟 `MerchantLevelBusinessRateDao::upsertRate()` 同一技术）；新增 `DELETE /admin/products/{id}/level-rebates/{levelId}`（商户等级任务没有的接口——删除有实际业务含义：回退到该等级在该业务线的默认比例，对不存在的覆盖行删除返回 404 而非静默成功），已用真实联调测试验证：设置覆盖后 `RebateCalculator` 读到 `rateSource='product_level'`，删除覆盖后回退读到 `rateSource='level'`。测试 `test/Cases/Admin/ProductControllerTest.php`。**范围之外**：5.5 的价格/返佣保护提示（低于成本价、毛利为负、返佣比例超 100%）与操作日志记录均未建（没有后台前端展示 / 没有操作日志基础设施，见 `ProductAdminService` 类注释）；不含 `supplier_products` 商品映射（已是独立功能，`ProductMappingController`） |
| 供应商管理：配置 CRUD（新建/列表/详情/修改/启用禁用，requirements.md 6.3） | ✅ `App\Controller\Admin\SupplierController` | ✅ `App\Service\Admin\SupplierAdminService` | ✅ |
| 供应商管理：商品映射（新建/列表/改价（必留痕）/优先级/启停，requirements.md 6.4） | ✅ `App\Controller\Admin\ProductMappingController` | ✅ `App\Service\Admin\ProductMappingAdminService` | ✅ |
| 供应商管理：商品同步接入 / 余额监控 / 熔断状态 / 调用日志 / 统计 | ⬜ | ⬜ | ⬜ |
| 商户等级：CRUD / 各业务线比例设置 | ✅ `App\Controller\Admin\MerchantLevelController` | ✅ `App\Service\Admin\MerchantLevelAdminService` | ✅ `GET/POST /admin/merchant-levels`、`GET/PUT /admin/merchant-levels/{id}`、`PUT /admin/merchant-levels/{id}/rates/{businessLine}`；权限 `merchant_level.view` / `merchant_level.manage`（已加进 `AdminBootstrapService::KNOWN_PERMISSIONS`）。列表全量不分页（等级是少量配置行）；详情 `rates` 固定含 recharge/card/movie/express 四个 key，`null` = 未设置、`'0.0000'` = 明确设为 0%；比例设置用 `MerchantLevelBusinessRateDao::upsertRate()`（数据库原生 upsert，按 `(level_id, business_line)` 唯一索引原地更新），接受非负、最多 4 位小数、不超过列上限 99.9999 的值，超过 1（100%）照样保存不拒绝（5.5 只要求提示，前端未建）。没有删除接口；不含调整商户所属等级。测试 `test/Cases/Admin/MerchantLevelControllerTest.php`，含写入后 `RebateCalculator` 读到新比例的联调用例。**仍未做**：商品单独覆盖某等级比例（`product_level_rebates` 的后台接口），单独的后续任务 |
| 价格设置：电影票 / 快递加价规则 / 价格预览 | ⬜ | ⬜ | ⬜ |
| 返佣管理：固定期限设置 / 商户返佣明细 / 供应商返佣明细 | ⬜ | ⬜ | ⬜（生成+结算的业务逻辑已在第 1 节"返佣待到账生成 + 到期结算"完成，这行剩下的是后台管理 UI——`rebate_due_period_days` 目前只能直接改 `system_settings` 表、没有编辑接口，也没有"商户返佣明细/供应商返佣明细"的查询列表） |
| 订单管理：全部订单查询 / 详情 / 异常单处理 / 部分退款处理 / 手动查询供应商 / 手动重推商户回调 | ✅ `App\Controller\Admin\OrderController` | ✅ `App\Service\Admin\OrderAdminService` | 🔨 除部分退款处理、发起供应商撤单外均已完成，见下方说明 |
| 售后处理：话费卡券争议处理 / 快递工单代提交与跟踪 | ⬜ | ⬜ | ⬜ |
| 财务报表 | ⬜ | ⬜ | ⬜ |
| 对账：订单对账 / 返佣对账 / 差异标记处理 | ⬜ | ⬜ | ⬜ |
| 告警：列表查看 / 标记处理 | ⬜ | ⬜ | ⬜ |
| 系统设置：管理员账号 / 角色权限 / 系统参数 / 操作日志 | ⬜ | ⬜ | ⬜ |

> 「订单管理」：`GET /admin/orders`（按 status / business_line / merchant_id / order_no /
> merchant_order_no / created_from / created_to 筛选，id 倒序，每页最多 100）、`GET /admin/orders/{id}`
> （订单含成本价和供应商、话费卡券明细、供应商尝试记录含请求/响应快照、资金流水、商户回调记录、返佣记录、
> 人工操作记录；卡号卡密不明文展示，快照里的 `card_no`/`card_password`/`card_pwd` 打码）、
> `POST /admin/orders/{id}/resolve`（异常单人工置成功/置失败，`result` + 必填 `remark` + 可选
> `supplier_order_no`）、`POST /admin/orders/{id}/query-supplier`、`POST /admin/orders/{id}/renotify`。
> 三个新权限：`order.view`、`order.manage`（查询供应商、重推回调）、`order.resolve`（异常单处理，会动钱，单独一档），
> 已同步进 `KNOWN_PERMISSIONS`。
> - **异常单人工处理**只允许 `abnormal`（其它 409）。置成功走 `OrderResultApplier` 照常扣款、生成返佣、通知商户，
>   成本价沿用订单上的值；置失败照常解冻、通知商户，不切换供应商。`OrderResultApplier` 的终态条件更新从
>   "必须是 processing"改成"必须还是读到时的状态（processing 或 abnormal）"，`OrderDao::finishIfStatus()`；
>   自动路径（回调、定时查询）对异常单仍由 `SupplierRouter` 拦住。
> - **卡密类卡券置成功**必须当场向供应商查到成功且带卡密，否则 409，避免商户拿到"成功但没有卡密"的订单；查到的卡密照常加密落库。
> - **手动查询供应商**（processing / abnormal）复用定时查询的 `SupplierResultPollingService::queryLatestAttempt()`：
>   处理中订单照常推进，异常单只把结果记到尝试记录上；查询失败返回 502。
> - **重推回调**只允许 success / failed / cancelled / refunded，推一次新的通知任务（失败后照常按间隔重试）。
> - 以上三个动作都写 `admin_operation_logs`（本次新增 `App\Model\AdminOperationLog` + `App\Dao\AdminOperationLogDao`，
>   此前这张表还没有代码写入），异常单处理记录处理前后的订单快照和备注。
> - 未做：部分退款处理（退款流程未建）、发起供应商撤单（驱动未实现撤单，第 2 节"撤单"行）。
> - 测试：`test/Cases/Admin/OrderControllerTest.php`。

> 「商户管理：启用禁用 / 调整等级 / 限流设置」：三个动作共用新权限编码 `merchant.manage`
> （已同步进 `AdminBootstrapService::KNOWN_PERMISSIONS`）。**启用禁用**只在 `active` ↔
> `disabled` 之间切换，`pending`/`rejected` 商户返回 409（不能绕过入驻审核），同状态幂等；
> 禁用不动余额、冻结金额和等级。禁用的拦截点：开放 API（`OpenApiSignatureMiddleware`
> 原本就拒绝非 active）、商户后台登录（`AuthService::login()` 原本就返回 403），以及本次
> 新补的 `MerchantAuthMiddleware`——此前它不查状态，禁用前签发的 token 在 7 天有效期内
> 仍能访问商户后台，现在按实时状态返回 401（前端据此清登录态回到登录页）。**调整等级**
> 只针对 `active`/`disabled` 商户（`pending` 走审核通过时分配），对之后成功的订单立即生效，
> 已生成的返佣记录不回溯。**限流设置**：新增 `App\Model\MerchantRateLimit` +
> `App\Dao\MerchantRateLimitDao`（原生 upsert），`PUT` 设 1–100000 的整数，`DELETE` 删掉单独
> 配置回落到 `system_settings.default_rate_limit_per_second`（零配置兜底 50）；商户详情新增
> `rate_limit: {limit_per_second, is_custom}`。这里只存配置，真正按配置限流的是第 1 节
> 「按商户限流中间件」。**范围外**：操作日志（`admin_operation_logs`，归「系统设置」行）。
> 测试见 `test/Cases/Admin/MerchantManagementControllerTest.php`、
> `test/Cases/Merchant/AuthControllerTest.php::testMeWithTokenIssuedBeforeDisableReturns401`。

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
| 供应商结果查询轮询 | Crontab | ✅ `App\Crontab\SupplierResultQueryCrontab` → `App\Service\Order\SupplierResultPollingService`，见下方说明 |
| 商户回调重试（1/5/15/60/120/360 分钟） | 队列 Job（延迟） | ✅ `App\Job\NotifyMerchantJob` 失败后按间隔自己重新入队（第 6 节"结果回调"时已实现，此前本表漏更新） |
| 供应商余额监控 | Crontab | ✅ `App\Crontab\SupplierBalanceCrontab` → `App\Service\Supplier\SupplierBalanceService`，见下方说明 |
| 供应商商品同步（每日全量校准） | Crontab | ✅ `App\Crontab\SupplierProductSyncCrontab` → `App\Service\Supplier\ProductSyncService`，见下方说明 |
| 熔断自动恢复（二期） | Crontab | ⬜ |
| 城市 / 影院数据批量同步（三期） | Crontab | ⬜ |
| 场次数据批量同步（三期，视权限） | Crontab | ⬜ |
| 返佣到期自动入账 | Crontab | ✅ `App\Crontab\RebateSettlementCrontab`，见第 1 节"返佣待到账生成 + 到期结算"（只做到账，不含作废/扣回） |
| 异常单标记 | Crontab | ✅ `App\Crontab\AbnormalOrderCrontab` → `App\Service\Order\AbnormalOrderService`，见下方说明 |

**供应商结果查询轮询**（requirements.md 6.2 / 7.1）：每分钟一次（`onOneServer` + `singleton`），
取"订单处理中、最新一次尝试仍是处理中/未知、距上次更新超过 60 秒"的尝试，每批最多 100 条、
10 个并发，用 `{order_no}-{attempt_no}` 调驱动 `queryOrder()`，结果跟回调一样交给
`SupplierRouter::applyAttemptResult()`（明确失败——包括查询确认供应商没有这笔订单——按切换
规则换下一家）。
- 查询间隔按 `order_attempts.updated_at` 计，下单、回调、每次查询都会刷新；刚下单的订单至少
  等 60 秒才查，避免供应商还没落库就被查成"没有这笔订单"。查询失败只记 `order` 日志并刷新时间。
- 查询期间订单被回调推进或切到下一家时，放弃这次结果。
- **终态只落一次**：新增 `OrderDao::finishIfProcessing()`（带 `status = processing` 的条件更新），
  `OrderResultApplier` 的成功/失败分支和"没有可用供应商"分支都改用它，回调和定时查询同时推进
  同一笔订单时后到的一方不再扣款/解冻/通知商户（此前只有资金层面的唯一索引兜底，商户会收到两次通知）。
- 订单被标成异常单后不再查询（只查 `processing`）。
- 测试：`test/Cases/Service/Order/SupplierResultPollingServiceTest.php`，调度注册见
  `BackgroundProcessRegistrationTest`。

**异常单标记**（requirements.md 7.1 / 7.4）：每 5 分钟一次（`onOneServer` + `singleton`），把下单
超过 `system_settings.abnormal_order_hours`（默认 24，非正数按默认）仍是 `processing` 的订单
改成 `abnormal`，每批最多 500 笔，逐笔带 `status = processing` 条件更新，被标记的订单 id 记
`order` warning 日志。
- 余额保持冻结，不通知商户（7.6 只在成功/失败/取消/退款时通知）。
- **商户侧仍显示处理中**：`Order::merchantFacingStatus()`，下单幂等重放、订单查询、商户回调签名体
  三个出口都改用它。需求没有规定商户怎么看到异常单，按"对商户仍是没有结果、余额仍冻结"处理，
  不暴露平台内部的转人工状态。
- 标记后不再定时查询；迟到的回调结果只写到对应尝试记录上供人工核实，`SupplierRouter::
  applyAttemptResult()` 对非 `processing` 订单直接返回，不改订单、不扣款、**不切换供应商**
  （此前如果切换时长被配得比异常单时长还长，异常单收到失败回调会去下一家下单）。
- 不含：人工置成功/置失败、发起撤单（第 8 节"订单管理"）、异常单积压告警（第 8 节"告警"）。
- 测试：`test/Cases/Service/Order/AbnormalOrderServiceTest.php`。

**供应商余额监控**（requirements.md 6.7）：每 5 分钟一次（`onOneServer` + `singleton`），对所有启用中的
供应商调驱动 `queryBalance()`（5 个并发），写回 `suppliers.balance`（截到两位小数）和
`balance_synced_at`；路由已有的余额筛选据此跳过余额低于成本价的供应商。停用的供应商不查。
- 查询失败或返回值不是普通十进制数：记 `supplier` error 日志，保留上次余额（不清空，清空会被路由当成"余额未知"继续分单）。
- 低于 `balance_warning_threshold`：记 `supplier` warning 日志。`alerts` 表是二期才建，告警模块落地后改成写告警记录。
- **预存款不足即时刷新**：`DriverResult` 新增 `supplierBalanceInsufficient`，卡速售状态 -1 时为 true。`SupplierRouter` 在同步下单和
  回调/定时查询拿到这种结果时，调 `SupplierBalanceService::reportInsufficient()`：记财务告警日志，并推
  `App\Job\RefreshSupplierBalanceJob` 异步刷新该供应商余额（不拖慢下单请求），订单本身照常按明确失败换下一家。计入熔断统计等熔断（二期）时再接。
- 测试：`test/Cases/Service/Supplier/SupplierBalanceServiceTest.php`（含下单时报预存款不足 → 刷新 → 下一笔不再分给它），
  `KasushouDriverTest` 的状态 -1 用例。

**供应商商品同步**（kasushou.md 第 4 节，requirements.md 6.4）：此前 `ProductSyncService` 只有落库逻辑、没有调用方，这次接上两个触发源。
- **商品变更通知**：`POST /notify/{code}/goods`（`NotifySupplierController::productChanged()`），需要在卡速售后台把商品变更通知地址配成它。
  供应商不存在 404、验签失败 403、成功或商品没配映射 200 回 `ok`（文档没规定回复内容，沿用订单回调）。查商品详情失败走全局异常返回 500，
  漏掉的由全量校准补上。验签通过后仍只把通知当触发、重新查权威值（原有设计）。
- **每日全量校准**：每天 04:00（`onOneServer` + `singleton`，锁 1 小时），逐个启用中的供应商翻页拉商品列表（100 条/页）并 `applySync()`；
  遇到空页、不满一页、**跟上一页完全相同**（接口忽略页码）或满 200 页时停止。单个供应商失败只记 `supplier-product-sync` 日志。
  列表里没出现的映射不动——商品列表接口的字段/分页是猜的，没联调前不据此下架。
- 顺带：`SupplierResultQueryCrontab` 的 singleton 锁有效期从默认 60 秒调到 300 秒（一批查询最坏耗时超过 60 秒，锁先过期会叠加执行）。
- 测试：`ProductSyncServiceTest`（通知入口、翻页的三种停止条件、单个供应商失败不影响其它、停用的不同步）、`NotifySupplierControllerTest`、
  `BackgroundProcessRegistrationTest`。

---

## 10. 进度总览

> 每次更新完各表状态后，手动同步这里的汇总（不做自动计算，避免又要建一个统计脚本）。

| 分类 | 总数 | 已完成 | 开发中 | 未开始 |
|---|---|---|---|---|
| 基础设施与公共能力 | 13 | 13 | 0 | 0 |
| 卡速售 2.0 驱动 | 8 | 6 | 0 | 2 |
| 云洋驱动 | 9 | 0 | 0 | 9 |
| 芒果驱动 | 11 | 0 | 0 | 11 |
| 供应商路由与风控 | 5 | 3 | 0 | 2 |
| 开放 API 接口 | 15 | 6 | 0 | 9 |
| 商户管理后台 | 16 | 6 | 0 | 10 |
| 系统管理后台 | 19 | 9 | 1 | 9 |
| 异步任务与定时任务 | 10 | 6 | 0 | 4 |
| **合计** | **106** | **49** | **1** | **56** |

**建议开发顺序**（按 [10. 分期计划](requirements.md#10-分期计划)）：

1. 第 1、5（部分）、9（部分）节的基础设施 → 第 2 节卡速售驱动 → 第 6/7/8 节里标注一期的话费相关行 → 打通一期闭环
2. 二期：卡券相关行、熔断、告警、对账
3. 三期：第 3、4 节云洋/芒果驱动、快递与电影票相关的第 6/7/8 节行、沙箱环境
