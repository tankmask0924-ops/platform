# 模块设计与开发进度

> 依据：[requirements.md](requirements.md) v1.7、[database-design.md](database-design.md)（2026-09-14）
> 用途：把需求和数据库设计拆成可独立开发的模块/接口/任务，跟踪**开发阶段**的完成度（不是需求或表设计是否确定——那两份文档本身已经"设计已确定"）。
> 更新方式：每完成一层就把对应格子改成 ✅；一行的 Model/Dao/Service/Controller（或对应入口）都 ✅ 且联调通过，整行"状态"才算 ✅ 已完成。
> 第 7、8 节（两个管理后台）另有"期次"和"前端页面"两列：该节的"状态（接口）"只看后端接口，前端页面单独跟踪，页面按接口联调通过才算 ✅。

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
| 供应商回调入口与验签框架 | [requirements.md 6.8](requirements.md#68-回调日志与统计) | `App\Controller\NotifySupplierController`：`POST /notify/{供应商编码}`（2026-09-18 起改为 `/notify/{编码}/{令牌}`，见第 8 节「供应商管理：商品同步接入…」行），第三套独立于开放 API/系统管理后台的路由命名空间，故意不挂任何既有鉴权中间件——验签本身就是唯一的身份校验，由 `App\Service\Order\SupplierCallbackService::handle()` 按 `suppliers.code`（`SupplierDao::findByCode()`）找到供应商、`SupplierDriverFactory::build()` 建驱动、调驱动的 `parseCallback()`（目前只有卡速售一家，验签失败返回 `null`）验签+拿权威结果，再从 `DriverResult::$rawRequest['external_orderno']`（形如 `"{order_no}-{attemptNo}"`，`order_no` 本身不含 `-`，截第一个 `-` 前面即可）反查平台订单（新增 `OrderDao::findByOrderNo()`，全局查询不限定 merchant_id——`orders.order_no` 本身就是唯一列，且回调到达时还不知道订单属于哪个商户）。响应体是纯文本（`Response::raw()`），不是 `{code,message,data}` 信封，成功/幂等重放固定回复卡速售要求的字面字符串 `ok`（HTTP 200）；供应商编码不存在或反查不到订单 → 404；验签失败 → 403，绝不能回 `ok`。**推进订单状态的"成功/明确失败即解冻+失败/处理中不动"逻辑从 `RechargeOrderPlacementService` 抽成了共享的 `App\Service\Order\OrderResultApplier::apply()`**（同步下单路由循环选出最终结果、和这里推进 `processing` 订单共用同一份状态转换+余额+通知代码，失败换供应商的循环逻辑本身没有抽，仍然只属于同步下单路径，因为订单一旦 `processing` 就已经绑死一次供应商尝试，不可能再背着商户换供应商）。**幂等**：`BalanceService::deduct()/unfreeze()` 本身的 `dedupe_order_key` 唯一索引保证同一 `order_id` 的余额变动只生效一次，`SupplierCallbackService` 在此之上再显式检查——订单已经是 `success`/`failed` 终态就直接短路回复 `ok`，不重新调用 `apply()`，避免供应商按 kasushou.md 描述的间隔（5/10/15/20/25 分钟，最多 5 次）重试同一个回调时被重复推进/重复推送商户通知。测试：`test/Cases/Controller/NotifySupplierControllerTest.php`（`test/HttpTestCase.php` 真实路由派发，覆盖成功/明确失败/处理中三种驱动结果、已终态订单重复回调不被重新应用、验签失败、供应商编码不存在、订单号反查不到六条路径）+ `test/Cases/Service/Order/RechargeOrderPlacementServiceTest.php`（抽取 `OrderResultApplier` 后原样保留，验证同步下单行为未变）。**范围外，留给后续任务**：商品变更通知 webhook（`ProductSyncService::applyNotification()` 已有原语但没有路由，是另一个更小的独立任务，不是本行）、卡速售之外的其它驱动接入这个入口、IP 白名单/限流、`#[Crontab]` 定时按 `external_orderno` 重查询兜底真正丢失的回调（本行只处理"回调正常到达"这一条路径） | ✅ |
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
| 撤单（异常单处理用，可选） | ✅ `cancelOrder()`（路径 `/api/v1/order/back` 是推断） | ✅ `OrderAdminService::cancelAtSupplier()` | ✅ | ✅ |
| 提交售后 / 接收售后结果 | ✅ `submitAftersale()` / `parseAftersaleCallback()`（路径 `/api/v1/order/after_sale` 和字段名是推断） | ✅ `App\Service\Admin\DisputeSupplierAftersaleService`（争议里提交，回调 `POST /notify/{code}/{token}/aftersale`） | ✅ `KasushouDriverTest` + `DisputeControllerTest` | ✅ 2026-09-23，见第 8 节「售后处理」说明 |
| 错误码映射表 | ✅ `App\Supplier\Kasushou\KasushouStatusMapper`（对应 kasushou.md 第 2 节状态表 + 第 3 节错误处理表，placeOrder/queryOrder 内部共用） | ➖ | ✅ `KasushouDriverTest` 覆盖各状态码分支 | ✅ |

> 本次新增（下单/查询订单/解析回调/查询余额）：`App\Supplier\UnifiedResult`（4 态枚举）、`App\Supplier\DriverResult`（统一结果 DTO，含超出 6.2 字面字段列表的 `cardList` 扩展字段，见类注释）、`App\Supplier\Kasushou\KasushouSigner`（sha1 签名/验签）、`App\Supplier\Kasushou\KasushouStatusMapper`、`App\Supplier\Kasushou\KasushouDriver`。未引入 `DriverInterface`：目前只有卡速售一个驱动实现，云洋/芒果尚未开工，接口形状还没被第二个实现验证过，判断属于过早抽象，留了代码注释提醒等第二个驱动落地后再抽取。Service 接入留空：订单处理/路由 Service 调用这个驱动尚未建立（依赖第 5 节路由与第 6 节话费下单 API，均未开工），且供应商配置从哪里读取（`suppliers.config`）本身也是单独一期的 ⬜ 行，本次驱动构造函数直接接收 baseUrl/userId/apiKey，跟配置来源解耦。
>
> 本次新增（商品同步）：`App\Model\SupplierProduct` + `App\Dao\SupplierProductDao`（`findBySupplierAndCode()` 按供应商+供应商商品编码反查映射行；`applySync()` 是全项目唯一负责改 `cost_price` 的方法，在同一个事务里做到"改价必留痕"，不需要在每个调用方各自重复判断要不要写历史）、`App\Model\SupplierProductPriceHistory` + `App\Dao\SupplierProductPriceHistoryDao`（写一次不再更新，同 `MerchantNotifyLog`/`OrderRecharge` 模式）、`KasushouSigner::verifyProductChangeNotification()`（第三套签名作用域，只覆盖 id+time，公式是按本类既有签名家族的推断，不是文档直接给出的字符串，见方法注释）、`KasushouDriver::parseProductChangeNotification()`/`queryProductDetail()`/`syncAllProducts()`、`App\Service\Supplier\ProductSyncService`（命名不带 Kasushou，为将来云洋/芒果复用留空间）。核心安全设计：商品变更通知的签名只覆盖 id+time，通知 payload 里即便夹带价格/状态/库存也不受签名保护，可被任意篡改，所以验签通过后只当"触发信号"，权威值一律重新调 `queryProductDetail()` 查询，不直接信任通知内容——跟 `KasushouDriver::parseCallback()` 对 `card_list`/`express_list` 的处理是同一个模式。商品映射行的创建（后台"商品映射"功能）、商品变更通知的 webhook 路由/控制器、每日全量同步的 `#[Crontab]` 定时任务，均不在本次任务范围内。

---

## 3. 供应商驱动 - 云洋（快递）

依据：[requirements.md 7.2](requirements.md#72-快递下单与补差价)、[yunyang.md](suppliers/yunyang.md)；三期功能。

| 能力 | Driver 方法 | Service 接入 | 单测 | 状态 |
|---|---|---|---|---|
| 查价（检测可用渠道） | ✅ `checkChannels()` | ✅ `ExpressQuoteService` | ✅ | ✅ |
| 下单 | ✅ `placeOrder()` | ✅ `ExpressOrderPlacementService` | ✅ | ✅ |
| 查询订单详情 | ✅ `queryOrder()` | ✅ `ExpressOrderSettlementService::refreshFromSupplier()`（定时查询、后台手动查询、取消确认） | ✅ | ✅ |
| 解析回调（无签名，触发查询确认） | ✅ `parseCallback()` / `platformOrderNoFromCallback()` | ✅ `ExpressCallbackService` | ✅ | ✅ |
| 查询余额 | ✅ `queryBalance()` | ✅ `SupplierBalanceService`（按驱动选工厂方法） | ✅ | ✅ |
| 取消 | ✅ `cancelOrder()` | ✅ `App\Service\OpenApi\ExpressOrderService` | ✅ | ✅ |
| 轨迹查询 | ✅ `queryTrace()` | ✅ `App\Service\OpenApi\ExpressOrderService` | ✅ | ✅ |
| 提交售后工单 / 接收工单回调 | ✅ `submitWorkOrder()` / `parseWorkOrderCallback()` | ✅ `App\Service\Admin\ExpressWorkorderAdminService`、`App\Service\Order\ExpressWorkorderCallbackService` | ✅ | ✅ 2026-09-23，见第 8 节「快递工单」说明 |
| 错误码映射表 | ✅ 见下方说明 | ➖ | ✅ | ✅ |

> **Service 接入（2026-09-23，快递下单流程）**：驱动层之外的全部接上了，业务规则见第 6 节脚注 ⑦。驱动本身只改了一处：
> 订单详情的 `expressFees` 多带一个 `platform_order_no`（查询结果里原样带回的 `extendField1`），回调认领订单只信它、
> 不信回调 payload 里的同名字段（回调没签名）。下面这段是驱动层落地时的说明，其中"Service 接入留空"已不再成立。
>
> 本次新增（2026-09-21，驱动层）：`App\Supplier\Yunyang\YunyangSigner`（`md5(appid + requestId + timeStamp + secretKey)`，
> `requestId` 每次新生成）、`App\Supplier\Yunyang\YunyangStatusMapper`、`App\Supplier\Yunyang\YunyangDriver`，
> 以及 `App\Supplier\DriverResult` 上新增的可选字段 `expressFees`（快递的结算依据是一组字段：
> `fee_over`/`type_code`/`total_freight` 和运费、保价费、耗材费、逆向费的拆分，6.2 的统一结果里只有一个"实际成本"
> 装不下，跟当初为卡密加 `cardList` 是同一个理由）。跟卡速售那一版一样，**Service 接入留空**：
> 快递的订单流程（第 6/7/8 节快递相关行）、`order_expresses` 等三期表都还没建，没有调用方。
> 驱动也还**没有接进 `SupplierDriverFactory`**——接进来意味着 `build()` 的返回类型要放宽，而现有六个调用方
> 全是话费/卡券流程，只会用卡速售的方法，那是为一个还不存在的流程提前改形状（理由写在工厂类注释里）。
>
> 几个跟话费不一样、接业务流程时不能照抄的点：
> - **统一入口 + `serviceCode`**：所有能力都 POST `/api/wuliu/openService`，不是一个能力一个路径。
> - **成功码不统一**：下单/取消/查询/检测渠道是 `"1"`，余额这类账户接口是 `"200"`。每个方法显式传自己的成功码，
>   不做"哪个都算成功"的兜底——那样会把失败当成功。这就是这家的"错误码映射表"：云洋没有细分错误码，
>   只有成功码 + 自由文案的 `message`，能分类的只有"传输失败（超时/500/解析不了）"和"业务失败（code 不对）"两类，
>   前者结果未知、后者明确失败，实现在 `send()` 里。
> - **只允许 https**：签名不覆盖请求内容 `content`，传输层是唯一防篡改手段，所以构造时就拒绝 http 地址。
> - **下单没有防重复单号**：超时/500/解析不了一律"结果未知"，调用方**绝不能重试**（重试就是重复下单重复扣钱）；
>   平台订单号放 `extendField1`，回调原样带回，`platformOrderNoFromCallback()` 取回来认领订单。
> - **判成功看 `feeOver` 不看物流状态**：`feeOver=1` 才是结算点=订单成功；拒收退回（`typeCode=4`）**不是失败**
>   （件发出去了，逆向费还要补扣，判失败会让平台去退款）；只有"已取消且未扣费"才是明确失败；
>   "已扣费又报取消"转人工。
> - **回调没有签名**：`parseCallback()` 只取单号，然后调带签名的订单详情拿权威数据，回调里的状态和运费一概不信
>   （单测里用伪造的"已取消 + 运费 0.01"回调验过，最终以查询结果为准）。回调地址是账户级配置的，下单不传 url。
> - **余额取 `keyong`（可用）不取 `yue`（总额）**：冻结部分已被在途订单占用，用总额会在真正不够用时还显示充足。
>
> 【serviceCode 和响应字段名是推断】文档给了能力清单和字段含义，但没有可核对的报文样例，`serviceCode` 的确切取值、
> `result` 里字段的确切拼写都是按行文拼出来的猜测，集中在常量区和几个 parse 方法里，联调对不上只改这几处
> （同卡速售驱动的处理方式）。`timeStamp` 取 10 位秒级同理，写在 `YunyangSigner` 类注释里。
>
> 测试 `test/Cases/Supplier/Yunyang/`：`YunyangDriverTest`（20 个，含 http 地址被拒、签名信封每次新 requestId、
> 下单超时是未知不是失败、业务失败才是明确失败、结算字段拆分、伪造回调不被采信、查价归一化、余额成功码 `"200"`
> 且取 `keyong`、调用日志动作名）、`YunyangSignerTest`（6 个）、`YunyangStatusMapperTest`（5 个，逐行核对第 2 节的表）。

---

## 4. 供应商驱动 - 芒果（电影票）

依据：[requirements.md 7.3](requirements.md#73-电影票下单)、[mango.md](suppliers/mango.md)；三期功能。

| 能力 | Driver 方法 | Service 接入 | 单测 | 状态 |
|---|---|---|---|---|
| 城市 / 行政区查询（含批量拉取） | ✅ `queryCities()` / `queryRegions()` | ✅ `MovieBaseDataSyncService`（缓存）+ `MovieQueryService` | ✅ | ✅ |
| 影院查询（含批量拉取 + 更新回调增量同步） | ✅ `queryCinemas()` / `batchCinemas()` / `parseCinemaUpdate()` | ✅ 同上；增量走 `MovieCallbackService::handleCinemaUpdate()`（批量拉取要商务权限，现在按城市逐页拉） | ✅ | ✅ |
| 影片 / 场次查询（视批量权限，可能只做实时转发） | ✅ `queryFilms()` / `queryShows()` / `batchShows()` | ✅ `MovieQueryService`（实时转发，场次价格换成售价） | ✅ | ✅ |
| 座位查询（不缓存，始终实时） | ✅ `querySeats()` | ✅ `MovieQueryService` | ✅ | ✅ |
| 锁座下单（含分区/情侣座/隔空选座/单笔限座校验） | ✅ `lockSeats()` + `App\Movie\SeatSelectionValidator` | ✅ `MovieOrderPlacementService` | ✅ | ✅ |
| 确认下单 | ✅ `confirmOrder()` | ✅ `App\Service\OpenApi\MovieOrderService::confirm()` | ✅ | ✅ |
| 释放座位 | ✅ `releaseSeats()` | ✅ `MovieOrderService::release()` / `expireLocks()` | ✅ | ✅ |
| 查询订单详情 | ✅ `queryOrder()` | ✅ `MovieOrderSettlementService::refreshFromSupplier()`（定时查询、后台手动查询、确认后立刻查） | ✅ | ✅ |
| 解析回调（无签名，触发查询确认；出票后改票根幂等处理） | ✅ `parseCallback()` | ✅ `MovieCallbackService` | ✅ | ✅ |
| 查询余额 | ✅ `queryBalance()` | ✅ `SupplierBalanceService` | ✅ | ✅ |
| 错误码映射表 | ✅ `MangoStatusMapper` | ➖ | ✅ | ✅ |

> **Service 接入（2026-09-23，电影票流程）**：业务规则见第 6 节脚注 ⑧。驱动本身这次只加了城市/区县/影院/影片的归一化
> （跟场次一样，推断的字段名集中在 `normalize*` 方法里），下面驱动层说明里"Service 接入留空"已不再成立。
>
> 本次新增（2026-09-23，驱动层）：`App\Supplier\Mango\MangoSigner`（key 字典序 + key/value 直接拼接 + token 取 md5，
> 数组按 JSON 拼、公共参数参与签名这两点是推断）、`MangoStatusMapper`、`MangoDriver`、`MangoRateLimitedException`，
> `SupplierDriverFactory::buildMango()`（配置 `{"base_url","agent_id","app_id","token","tel"}`，`tel` 只用来查余额），
> `DriverResult` 新增可选字段 `movieDetails`（`handle_step`、取票码、实际座位、是否订单溢价，同 `expressFees` 的理由），
> 以及跟供应商无关的锁座前置校验 `App\Movie\SeatSelectionValidator`。跟云洋那次一样，**Service 接入留空**：电影票的开放接口、
> 订单流程、城市影院缓存表都还没建（第 6 节电影票四行、第 9 节两个同步任务），只有余额监控已经接上。
>
> 接电影票业务流程时不能照抄话费/快递的点：
> - **请求体 gzip**（`Content-Encoding: gzip`），统一 POST JSON。签名覆盖全部参数，所以不像云洋那样强制 https。
> - **成本只有一个 `cost`**：不分区取 `settle_price`、分区取该区 `user_price`；`net_price`/`price`/`supplier_price`/`agent_rebate`/
>   `limit_price` 在归一化时就丢掉，`attributes` 里也剥掉，业务层不会顺手透传出去。
> - **场次挂在查询时传入的影院/影片 ID 下**，记录自带的 `cinemaid`/`film_id` 丢掉；批量拉场次时影片 ID 只能用记录自带的，
>   放在 `reference_film_id`，业务层要按影片接口的 ID 重新对应。
> - **锁座**：每个座位条目必须带 `area_id` 键（不分区传 null），少了直接抛异常；`auto_check_seat`、`fast_buy` 固定 0；平台单号放
>   `attach`（查询订单详情带回，回调认领用）。只有 10040/10036"订单溢价"是明确失败，其它失败码和超时一律结果未知、不能重试。
>   锁座有效期固定 10 分钟（`LOCK_TTL_SECONDS`）。
> - **确认下单**受理成功是处理中，被拒也按结果未知——真实原因（比如锁座已超时 `handle_step=-1`）以查询订单详情为准。
> - **查询订单详情**：`handle_step` 3/4 成功、-1/-2 明确失败、0/1/2 处理中，其余未知；**查不到这个单也是结果未知**，不能据此解冻。
>   返佣只在出票成功后取 `total_rebate`。
> - **回调**只取 `order_number` 去查（有伪造"出票成功"回调不被采信的用例），成功回复 `MangoDriver::CALLBACK_REPLY`（`{"code":1}`）。
>   出票后改票根会再来一次 `000`，由订单流程按状态幂等处理。影院更新回调 `parseCinemaUpdate()` 只给影院 ID，不用回复。
> - **列表类查询失败直接抛异常**，限流单独抛 `MangoRateLimitedException`（批量同步任务据此歇一会儿再拉）。
> - **锁座前置校验**（`SeatSelectionValidator`）：1~4 座、不重复、都可售、不跨区、情侣座成对相邻、隔空选座（被过道隔开的一段
>   超过 5 个有效座位时，所选座位两侧不能只剩 1 个空座，已售座位算占用）。输入是 `querySeats()` 的归一化座位图，
>   `row`/`col` 是格子坐标、过道处跳号。
>
> 【接口路径、响应信封 `{code, message, data}`、成功码 `200`、座位图坐标/可售字段（`GraphRow`/`GraphCol`/`Status`）是推断】
> mango.md 隐藏了域名，也没有报文样例，这些集中在 `MangoDriver` 的常量区和 `normalize*` 方法里，联调对不上只改这几处。
> 文档里写明的字段照文档拼写。
>
> **顺手补上的两处云洋遗漏**（快递下单那次没发现）：
> - 后台建不出云洋供应商：`SupplierAdminService` 的驱动白名单只有卡速售，前端配置表单也只有卡速售的字段。现在白名单改成
>   「驱动 → 可用业务线」（卡速售：话费/卡券，云洋：快递，芒果：电影票），建和改都校验两者对得上；后台新增供应商时驱动
>   下拉只列当前业务线能用的驱动，云洋/芒果的配置项已加上。
> - 调用日志只认卡速售：动作筛选白名单没有云洋/芒果的动作名，下单日志也关联不到订单（只认 `external_orderno`）。现在还认
>   云洋 `content` 里的 `extendField1` 和芒果的 `attach`，后台动作名都有中文。
>
> 测试 `test/Cases/Supplier/Mango/`：`MangoDriverTest`（17 个：gzip + 签名、成本不外泄 + 场次挂查询 ID、座位归一化、锁座带分区 +
> 固定开关 + attach、缺 area_id 键直接拒、不分区不带 area_id、锁座失败分类、确认下单、订单详情映射 + 返佣时机 + 查不到是未知、
> 回调只作触发、影院更新回调、余额取 credit、限流异常、列表失败抛错、调用日志动作名、缺配置拒绝）、`MangoSignerTest`（4 个）、
> `MangoStatusMapperTest`（3 个）；`test/Cases/Movie/SeatSelectionValidatorTest.php`（8 个，座位图用字符串画）；
> `SupplierBalanceServiceTest` 加了芒果一例、`SupplierControllerTest::testDriverMustFitBusinessLine`、
> `SupplierMonitorControllerTest::testCallLogsLinkOrdersForYunyangAndMango`。

---

## 5. 供应商路由与风控

不属于任何一个驱动，是驱动之上的公共调度逻辑。

| 模块 | 设计依据 | 状态 |
|---|---|---|
| 固定优先级路由与失败切换 | [requirements.md 6.5](requirements.md#65-路由与失败切换) | ✅ |
| 切换时长限制 | [requirements.md 6.5](requirements.md#65-路由与失败切换) | ✅ |
| 熔断判定与自动恢复（二期） | [requirements.md 6.6](requirements.md#66-熔断) | ✅ `App\Service\Supplier\CircuitBreakerService` + `App\Crontab\CircuitBreakerRecoveryCrontab`，路由筛选见下方「熔断」说明 |
| 供应商商品成本价同步任务（卡速售自动，其余人工） | [requirements.md 6.4](requirements.md#64-商品映射与成本价) | ✅ 自动：见第 9 节"供应商商品同步"；人工：后台商品映射改价（第 8 节） |
| 结果未知 / 明确失败归类的统一处理框架 | [requirements.md 6.2](requirements.md#62-对接驱动的统一能力) | ✅ 已由各部分拼齐，不另建框架（2026-09-23 核对）：统一结果 `App\Supplier\UnifiedResult` + `DriverResult`；三家驱动各自的映射表 `KasushouStatusMapper` / `YunyangStatusMapper` / `MangoStatusMapper`，表外、传输失败、解析不了一律结果未知；明确失败才切换或解冻（`SupplierRouter`、快递/电影票结算），结果未知保持处理中由 `SupplierResultPollingService` 定时查询，超时由 `AbnormalOrderService` 转异常单人工处理 |

**路由与失败切换（6.5）**：`App\Service\Order\SupplierRouter`，话费、卡券共用；同步下单
（`AbstractOrderPlacementService::routeAndFinalize()`）和供应商异步回调
（`SupplierCallbackService`）都走它。
- 筛选：映射行非 active、库存为 0、供应商停用、供应商余额已知且低于成本价的、正在熔断中的去掉。按 `priority` 升序，每家最多一次（`order_attempts` 里出现过的供应商不再尝试）。
- 下单前预检：没有可用供应商时直接返回 42006「商品暂不可售」，不建单、不冻结。预检之后到路由之间供应商状态变了、一家都没试成，仍按旧逻辑失败解冻（43002）。
- 只有明确失败才换下一家；受理后异步回调给出明确失败也换，平台订单号不变，供应商侧单号用 `{order_no}-{attempt_no}` 区分。处理中/结果未知停在当前供应商。
- 切换时长：`system_settings.switch_duration_minutes`（默认 30），从 `orders.created_at` 起算；超时后明确失败不再换，订单失败、解冻。只影响"换不换"，不影响等结果的订单。后台配置入口还没做，目前改表生效。
- 并发：调用供应商前先 `OrderAttemptDao::claim()` 插入 `processing` 行占住下一个 `attempt_no`（唯一索引），重复并发的失败回调只有一个能真正去下单。旧尝试的迟到/重推回调按 `attempt_no` + 供应商跟最新尝试对不上识别，只回 `ok` 不应用。
- 卡速售回调需要 `isCardProduct`：验签前用回调里原始单号找订单只用来选解析方式，验签后权威单号必须指向同一笔订单。
- `order_attempts.fail_reason` 写入时截断到 255（驱动构造失败的异常信息可能超长，此前会让下单请求 500）。
- 测试：`test/Cases/Service/Order/SupplierRouterTest.php`（异步失败切换、超出切换时长、最后一家失败、过期回调、序号占位），`RechargeOrderPlacementServiceTest` 的筛选/无供应商用例，两个下单 Controller 测试。

**熔断（6.6，2026-09-21）**：`App\Service\Supplier\CircuitBreakerService` + `supplier_circuit_breakers` 表
（新迁移）+ `App\Model\SupplierCircuitBreaker` / `App\Dao\SupplierCircuitBreakerDao`。
- **判定**：一次尝试拿到**明确失败**后同步触发，重算近 `circuit_breaker_window_minutes` 分钟的失败率：
  出结果的尝试达到 `circuit_breaker_min_orders` 次、且失败率超过 `circuit_breaker_fail_rate_percent`，
  就暂停 `circuit_breaker_pause_minutes` 分钟。四个阈值都在「系统设置 - 系统参数」里配。
  分母只算已出结果的（success + failed），跟供应商统计同一套口径：把处理中的放进分母，会在下单高峰
  （处理中的多）把失败率稀释掉，正好是最需要熔断的时候失灵。
- **两个作用域都判**：先判"这家供应商的这个商品"，再判"整个供应商"。先判商品是有意的——一个商品出问题
  只切那个商品，不牵连同一家的其他商品（6.6 原文）。两个作用域共用同一组阈值，没有再拆一套商品级阈值：
  需求只给了一组值，多一组配置就是多一处要运营理解和调的东西。
- **同步判定不进队列**：一条聚合查询 + 可能一次 upsert，比一次供应商 HTTP 调用便宜得多；放进队列等于
  "已经知道这家在连续失败，还要再放几笔过去"，正好抵消熔断的意义。整个判定包在 try/catch 里——
  熔断是保护机制，它坏了最多是没保护到，不能反过来把正常订单搞挂。
- **到期恢复不依赖定时任务**：`isPaused()` 直接看 `paused_until` 过没过（`SupplierCircuitBreaker::isPausedNow()`），
  暂停 5 分钟就是 5 分钟，不会因为定时任务一分钟一跑而多停几十秒。`CircuitBreakerRecoveryCrontab`
  只做把过期行写回 `normal` 的收尾，让后台列表和日志如实反映状态；它整个没跑，路由行为也完全正确。
- **手动暂停/恢复**（6.6「运营也可以手动暂停/恢复」）：`POST /admin/suppliers/{id}/circuit-breakers/pause`
  （`product_id` 留空=整家、`minutes` 留空=无限期、`remark` 必填）、`.../resume`，权限 `supplier.manage`；
  查看 `GET .../circuit-breakers`，权限 `supplier.view`。手动暂停时长上限 24 小时：更长的"暂停"实质是
  "停用这家供应商"，该走供应商启停或停掉商品映射，而不是挂一个几天后到期、谁也不记得的熔断。
  只能对**已经映射**到这家供应商的商品单独熔断——给没映射的组合建行，路由根本不会走到，只会留下一条
  永远不生效的记录。
- **熔断行不删**：恢复只把 `status` 改回 `normal`，保留这一行才能在后台看到"这家曾经熔断过、上次什么原因"。
- **告警未接**：6.6 要求熔断时告警，但 `alerts` 表和告警模块同属二期、还没建（第 8 节「告警」行）。
  现在先记 error 日志（`supplier` 渠道，`supplier circuit broken`），告警模块落地时把
  `CircuitBreakerService::alert()` 里的 TODO 换成写 `alerts` 行即可，调用点不用动。
- 前端：供应商详情页新增「熔断状态」卡片（当前阈值说明 + 记录表 + 手动暂停弹窗 + 恢复），已联调。
- 测试：`test/Cases/Service/Supplier/CircuitBreakerServiceTest.php`（最小订单数下限、阈值边界"等于不熔断"、
  窗口外的旧尝试不计、处理中不进分母、商品级熔断不牵连同供应商其他商品、整家熔断覆盖所有商品、
  到期即恢复、无限期手动暂停不被定时任务碰、手动恢复能提前解除自动熔断、阈值读系统参数）、
  `test/Cases/Admin/CircuitBreakerControllerTest.php`、`SupplierRouterTest` 三个路由筛选用例。

---

## 6. 开放 API 接口

依据：[requirements.md 8.1](requirements.md#81-开放-api)

> 路由前缀约定：本节所有开放 API 接口统一挂在 `/open-api` 前缀下（`查询余额`是第一个落地的接口，由它定的这个约定），后续新增接口请沿用，不要另起前缀。

| 接口 | Controller | Service | 单测 | 状态 |
|---|---|---|---|---|
| 查询余额（可用/冻结/待到账返佣） | ✅ | ✅ | ✅ | ✅ |
| 订单查询（平台单号或商户单号，二选一） | ✅ | ✅ | ✅ | ✅ |<sup>①</sup>
| 结果回调（平台 → 商户，含重试） | ➖ | ✅ | ✅ | ✅ |<sup>②</sup>
| 商品列表（话费、卡券共用一个接口） | ✅ | ✅ | ✅ | ✅ |<sup>③</sup>
| 话费下单 | ✅ | ✅ | ✅ | ✅ |<sup>④</sup>
| 卡券商品列表（二期） | ✅ | ✅ | ✅ | ✅ |<sup>③</sup>
| 卡券下单（二期） | ✅ | ✅ | ✅ | ✅ |<sup>⑤</sup>
| 电影票城市 / 影院 / 影片 / 场次 / 座位查询（三期） | ✅ `App\Controller\OpenApi\MovieController` | ✅ `App\Service\OpenApi\MovieQueryService` | ✅ | ✅<sup>⑧</sup> |
| 电影票锁座（三期） | ✅ 同上 | ✅ `App\Service\Order\MovieOrderPlacementService` | ✅ | ✅<sup>⑧</sup> |
| 电影票确认出票（三期） | ✅ 同上 | ✅ `App\Service\OpenApi\MovieOrderService` + `MovieOrderSettlementService` | ✅ | ✅<sup>⑧</sup> |
| 电影票释放座位（三期） | ✅ 同上 | ✅ `App\Service\OpenApi\MovieOrderService` | ✅ | ✅<sup>⑧</sup> |
| 快递查价（三期） | ✅ `App\Controller\OpenApi\ExpressController` | ✅ `App\Service\OpenApi\ExpressQuoteService` | ✅ | ✅<sup>⑥</sup> |
| 快递下单（三期） | ✅ `App\Controller\OpenApi\ExpressController` | ✅ `App\Service\Order\ExpressOrderPlacementService` + `ExpressOrderSettlementService` | ✅ | ✅<sup>⑦</sup> |
| 快递取消（三期） | ✅ 同上 | ✅ `App\Service\OpenApi\ExpressOrderService` | ✅ | ✅<sup>⑦</sup> |
| 快递轨迹查询（三期） | ✅ 同上 | ✅ `App\Service\OpenApi\ExpressOrderService` | ✅ | ✅<sup>⑦</sup> |

① `订单查询`目前只覆盖一期业务线（`recharge`/`card`）：`App\Model\Order`/`OrderRecharge`、
`App\Dao\OrderDao`（`findByOrderNoForMerchant`/`findByMerchantOrderNoForMerchant` 都带
`merchant_id` 条件，防止商户越权查到别人的订单和卡密，有专门的跨商户隔离测试覆盖）、
`App\Dao\OrderRechargeDao`、`App\Service\OpenApi\OrderQueryService`（卡密类订单查
`order_recharges` 并用 `Encryptor` 解密返回明文卡号卡密）、
`App\Controller\OpenApi\OrderController`（`GET /open-api/order`）。
`requirements.md 8.1` 的"快递订单返回费用明细"2026-09-23 随快递下单补上：快递订单多一个 `express`
明细（见脚注 ⑦）。同时新增了
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

③ `商品列表`（`GET /open-api/products`）：`App\Model\Product`/`ProductLevelRebate`/
`MerchantLevelBusinessRate`、对应的 `App\Dao\ProductDao`（`listOnShelfByBusinessLine`）/
`ProductLevelRebateDao`/`MerchantLevelBusinessRateDao`、`App\Service\Product\RebateCalculator`
（requirements.md 5.3 返佣公式：返佣基数 × 等级比例、向下取整到分，全程用 `bcmath` 字符串运算，
不用 float，`bcmul` 对 scale 是截断不是四舍五入，非负数场景下截断等价于向下取整）、
`App\Service\OpenApi\ProductListService`、`App\Controller\OpenApi\ProductController`。
没开通该业务线返回 42007（不返回空列表，否则商户会误以为平台没有商品）；商户没开通时不暴露商品。
每项返回商品 `id`（下单要传的 `product_id`）。

**话费、卡券共用这一个接口**（2026-09-21 补上卡券，二期第一项）：requirements.md 8.1 的接口表里
"商品列表"本来就是「话费、卡券」一行，不是两行，所以没有另开 `/card-products` 之类的路由，而是让
`business_line` 接受 `recharge`/`card` 两个值；电影票、快递没有本地商品库（成本和返佣每次从供应商
实时取，6.1），传进来照样用 `fail()` 返回 41002，不静默返回空列表。
- **两条业务线返回同一组字段，用不上的给 null**，跟商户后台「商品价格」页
  `App\Service\Merchant\ProductPriceService::list()` 的字段完全一致——商户在后台页面上看到的
  和从开放 API 拿到的是同一份数据，不用对着两份字段表做映射。给话费也加上 `card_type: null`
  是纯新增字段，老调用方忽略未知字段即可，不破坏已上线的话费列表契约。
- **`card_type` 对卡券是必要字段不是锦上添花**：`direct`（直充）下单必须传 `recharge_account`，
  `card_secret`（卡密）必须不传，传了直接拒绝（见 `CardOrderPlacementService` 类注释）。
  商品列表不给这个字段，商户就没有任何办法知道该不该传充值账号。
- 返佣按商品自己的 `business_line` 取等级比例（卡券的比例不会串到话费那一行），测试里用
  话费 60% / 卡券 40% 两个不同比例守着这一点。
- 商户后台「接口文档」页同步改成「商品列表（话费 / 卡券）」，补了 `card_type` 字段说明、
  卡券返回示例和"先看 card_type 再决定传不传充值账号"的提示。
- 测试 `test/Cases/OpenApi/ProductControllerTest.php`（卡券正向列表、话费仍带 `card_type: null`、
  未开通卡券返回 42007、movie/express/非法值返回 41002）。

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


⑥ `快递查价`（requirements.md 7.2 第一步，2026-09-21）：`POST /open-api/express/quote`，
第一个真正调用云洋驱动的调用方。`App\Supplier\SupplierDriverFactory` 也因此加了
`buildYunyang()`（跟卡速售的 `build()` 分成两个方法，不合成联合类型，理由见工厂类注释）。
- **只对运费加价**，保价费、耗材费按成本原样转给商户（5.1、7.2）：只有 `freight` 进
  `PricingRuleService::salePriceFor()`。没配加价规则时 `salePriceFor()` 抛错、接口报系统错误，
  **绝不把成本价当售价返回**（有专门的用例钉住"响应里不能出现成本数字"）。
- **不暴露供应商渠道 ID**：换成平台渠道编号 `App\Express\ExpressChannelCodec`——
  HMAC-SHA256（从 `APP_ENCRYPTION_KEY` 派生子密钥）取前 16 位十六进制，前缀 `EX`。
  **没有建映射表**：渠道是供应商侧的动态数据，建表就要同步、要过期；而下单本来就必须
  重新查价（7.2），所以把编号做成确定性单向编码，下单时重新查、逐个编码比对就换回了渠道 ID。
  编号在不同商户、不同次查价之间稳定，商户可以存下来直接下单——代价是它不含"报价有效期"，
  价格永远以下单时重新查到的为准，这跟云洋没有报价单号的现实一致。
- **商户传了保价金额就只返回支持保价的渠道**（`allowInsured=1`），否则他会选中一个下单时
  才发现不能保价的渠道。
- **"查回来 0 个渠道"和"一家都没查成"必须分开**：前者是正常答案（这个地址和重量没有可用渠道），
  返回空列表；后者是平台侧问题（没有启用中的快递供应商、驱动建不起来、全部异常），返回新增的
  `42008 ExpressChannelUnavailable`。混在一起商户会去改地址。多家供应商时单家失败只记日志，不影响整体。
- **参数是扁平的标量字段**（`sender_province` 这种），不是嵌套对象：开放 API 的签名是
  "除 sign 外按 key 排序拼 k=v" （8.1），嵌套数组没法参与拼接。用 POST 不用 GET：地址是个人信息，
  不该进 URL 和访问日志。
- 商户接口文档页（`web/merchant` 的「接口文档」）已同步加上这个接口。
- ~~快递业务线暂时还不对商户开放申请~~：2026-09-23 随下单接口开放（见 ⑦）。
- 测试 `test/Cases/OpenApi/ExpressQuoteControllerTest.php`（9 个：加价只加运费 + 其余按成本 +
  编号不泄露渠道 ID、按总价排序、保价过滤、空列表 vs 42008、单家供应商失败不影响整体、
  未开通 42007、各类参数 41001、没配加价规则不按成本卖）、
  `test/Cases/Express/ExpressChannelCodecTest.php`（4 个：编号稳定、不同渠道不同编号、不泄露、乱码不匹配）。

⑦ `快递下单 / 取消 / 轨迹`（requirements.md 7.2、8.1，2026-09-23）：`POST /open-api/express/order`、
`POST /open-api/express/cancel`、`GET /open-api/express/trace`。快递的资金流程跟话费完全不同，
所有供应商结果（同步下单、回调、定时查询）都交给同一个 `App\Service\Order\ExpressOrderSettlementService::apply()`：
- **下单重新查价**：商户只传查价拿到的 `channel_code`，`ExpressQuoteService::resolveChannel()` 用同样的参数
  重新查一遍、把编号换回云洋渠道；售价 = 运费成本加价 + 保价费 + 耗材费（按成本），冻结金额 = 售价。
  编号对不上（渠道下线、地址/重量变了、要保价但不支持）新增错误码 `42009 ExpressChannelNotFound`，不建单不冻结。
  下单参数比查价多寄收件人姓名/电话、详细地址、`item_name`，可选 `appointment_time`（必须是查价返回的时间段之一，
  传给云洋的字段名 `appointmentTime` 是推断）。
- **只下一次，不重试**：云洋没有防重复单号，超时是结果未知、保持处理中；下单前先把 `supplier_id` 记到订单上。
  这种订单没有云洋单号查不了，定时查询把它们排除在外（`OrderAttemptDao::listDueForQuery()`），只能等回调认领或异常单转人工。
- **冻结调整**：云洋受理后按它返回的冻结运费重算预估售价，多退少补冻结金额，新增流水类型 `freeze_adjust`
  （`amount` 带符号）。可用余额不够就能冻多少冻多少，差额留到结算补扣。只调一次（以 `order_expresses.frozen_freight` 为准）。
- **结算点 `feeOver=1`**：按实际费用算应收（运费加价，其余按成本），冻结的先扣，多了解冻、少了补扣（`supplement_deduct`，
  可以扣成负余额）；订单 `success`，`sale_price`/`deducted_amount` 改成实际应收、`cost_price` 改成实际成本（财务报表毛利
  直接用这两列）。商户实际被收的运费存 `order_expresses.freight_sale_price`（新增列，加价规则改了之后也能对上当时收的钱）。
- **结算后的费用调整**：逐项比上次处理过的实际费用，差额记 `order_express_fee_adjustments` 并补扣（`supplement_deduct`）
  或退回（`refund`），回调商户；运费按售价差额，其余按成本。回调重推、定时查询重复到达不会重复处理——所有比较和动钱
  都在锁住 `order_expresses` 行的事务里（`OrderExpressDao::lockForUpdate()`）。
- **取消**：只有待揽收、已有云洋单号的能取消；云洋说取消成功后再查一次订单详情，以"已取消且未扣费"为准全额解冻、
  订单 `cancelled`（跟云洋主动取消后回调走同一段代码）。云洋拒绝取消统一返回新增的 `42010 OrderNotCancellable`，
  不透传云洋文案。查询说"查无此单"不当取消处理，只记日志。
- **回调**（`SupplierCallbackService` 按 `suppliers.driver` 分派到 `ExpressCallbackService`）：只用带签名查询的权威结果推进；
  认领订单先按云洋单号，没有云洋单号的按**查询结果里**带回的 `extendField1`，不信 payload 里的（有专门的伪造回调用例）。
  成功回复 `{"code":1,"message":"推送成功"}`；查询本身失败就不回成功，让云洋重推（结算后的费用调整只有回调能知道）。
- **完成时间**：签收时间；拒收/不签收的由新增的 `App\Crontab\ExpressCompletionCrontab` 在扣费后超过兜底天数补上
  （系统参数 `express_complete_fallback_days`，默认 15，已加进后台「系统设置」）。快递暂无返佣，不生成返佣记录。
- **异常单**：快递待揽收可能超过异常单时长被标成异常单，之后云洋查询说已扣费照常结算。后台「人工处理」对快递只允许
  置失败（云洋确认没有这一单时全额解冻），不能置成功（会按预估价扣款），扣费结果用「查询供应商」同步。
- **对商户的展示**：下单/取消/订单查询多一个 `express` 明细（`App\Service\Order\ExpressOrderPresenter`：运单号、物流状态、
  费用明细和费用调整记录，运费给的是加价后的售价，不给成本）；商户回调多带 `waybill_no`/`logistics_status`/`deducted_amount`
  和四项费用（签名只能拼标量，调整记录商户用订单查询看）。云洋渠道 ID 和云洋单号都不外泄。
- **快递业务线对商户开放申请**（`SubscriptionService::OPEN_BUSINESS_LINES` 加上 express）；商户后台「接口文档」已加上三个接口、
  回调字段和 `freeze_adjust` 流水类型的中文名。
- **没做的**：两个后台的订单详情页还没有快递明细（寄收件信息、费用明细、调整记录），第 7、8 节订单相关行的页面待补；
  快递工单（第 3 节最后一行、第 8 节售后处理）2026-09-23 已完成。
- 测试：`test/Cases/OpenApi/ExpressOrderControllerTest.php`（11 个：重新查价冻结 + 冻结调整 + 参数透传 + 不泄露、拒单解冻、
  超时不重试、幂等、42009、参数校验、取消解冻 / 已揽收不能取消 / 云洋拒绝不透传文案、轨迹 + 跨商户隔离、订单查询带明细）、
  `test/Cases/Service/Order/ExpressOrderSettlementServiceTest.php`（14 个：冻结调整只一次 / 调低 / 余额不足封顶、7.2 结算举例两种、
  结算后费用调整 + 重复推送不重复处理、逆向费扣成负余额、取消解冻、查无此单不解冻、签收完成时间、兜底完成、
  回调按权威单号认领、伪造回调认领不了、定时查询按云洋单号查且排除没有单号的）、
  `SupplierBalanceServiceTest::testYunyangSupplierIsQueriedWithItsOwnDriver`。

⑧ `电影票`（requirements.md 7.3、5.3、5.4，2026-09-23）：查询 `GET /open-api/movie/cities|regions|cinemas|films|shows|seats`，
下单 `POST /open-api/movie/lock|confirm|release`。
- **新表**：`order_movies`（比设计多了 `unit_price`/`unit_cost` 每张售价/成本快照、`mobile` 取票手机号、`confirmed_at` 确认出票时间），
  `movie_cities`/`movie_regions`/`movie_cinemas`。可选的 `movie_showtime_caches` 没建（批量拉场次要商务权限，场次全部实时转发）。
- **城市/区县/影院读缓存**：`App\Service\Movie\MovieBaseDataSyncService` 全量同步（每天 04:30 `MovieBaseDataSyncCrontab`，
  后台供应商详情页原「同步商品」按钮对芒果供应商变成「同步城市影院」），按唯一键 upsert、只在拉成功的城市里删过期记录。
  影院更新回调 `POST /notify/{code}/{token}/cinema` 重拉该影院所在城市（后台详情页显示这个地址，要去芒果后台配置）。
  批量拉取影院接口要商务权限，现在用逐城市的影院列表接口。
- **影片、场次、座位实时转发**，查询失败统一 `42011 MovieUnavailable`（让商户 30 秒后重试）。场次价格换成每张售价
  （电影票加价规则，分区场次按区给价），成本不出现在响应里；缺成本的场次/分区不返回。没配加价规则报系统错误，不按成本卖。
- **锁座**：重新查场次核价（不采用商户传的价格），重新拉座位图做前置校验（不合法 41001，不建单不冻结不调芒果），
  冻结 = 每张售价 × 张数；场次找不到或所选分区缺成本 `42012 MovieShowNotFound`。座位参数 `seat_codes` 是逗号分隔字符串
  （签名只能拼标量）。锁座只调一次不重试；"订单溢价"失败、全额解冻、失败码 `43004 MoviePriceChanged`。
- **确认出票**：锁内写 `confirmed_at` 后调芒果确认（只调一次，重复确认返回当前状态），随即查一次订单详情推进；锁座已过期
  `42013 MovieLockExpired`。**释放座位**：锁内确认未确认出票 → 订单 `cancelled`、全额解冻 → 再通知芒果释放（失败只记日志）。
  **超时释放**：`MovieLockExpiryCrontab` 每分钟把到期 30 秒还没确认的订单置失败（`43005 MovieLockTimeout`）、解冻、通知芒果释放；
  锁座结果未知、没有芒果单号的也一样（商户确认不了，芒果那边锁座自己会失效）。释放和确认的竞态靠明细行锁里检查 `confirmed_at`。
- **结算**（`MovieOrderSettlementService`，锁座结果、回调、定时查询、确认后的查询都走它）：出票成功冻结金额全额扣款，
  完成时间 = 出票时间，写取票码；**返佣基数 = 供应商返佣 `total_rebate`**，比例取等级在电影票业务线的设置
  （`RebateCalculator::calculateForSupplierRebate()`，基数来源 `supplier`、比例来源 `level`）。返佣晚到的之后再生成，
  改票根再回调一次商户，都不重复扣款/返佣。芒果说出票失败/支付超时：失败、解冻。
- **回调**（`SupplierCallbackService` 按驱动分派到 `MovieCallbackService`）：只信带签名查询结果；没有芒果单号的订单按查询结果里
  带回的 `attach` 认领（有伪造回调用例）；查询失败不回成功。成功回复 `{"code":1}`。
- **定时查询 / 后台**：定时查询按芒果单号查，没有单号的不进待查列表；后台「人工处理」对电影票只能置失败（成功必须带取票码），
  「查询供应商」可以用。
- **对商户的展示**：锁座/确认/释放/订单查询多一个 `movie` 明细（`MovieOrderPresenter`：场次、座位、每张售价、锁座有效期、
  确认时间、取票码，不给成本和供应商返佣）；商户回调多带 `ticket_codes`（JSON 字符串）。电影票业务线对商户开放申请，
  四条业务线全部开放。商户后台「接口文档」已加上电影票接口和选座规则。
- **没做的**：两个后台的订单详情页还没有电影票明细（同快递）；场次批量预拉取缓存（等商务权限）；`limit_price` 用途待芒果确认。
- 测试：`test/Cases/OpenApi/MovieControllerTest.php`（12 个：城市影院读缓存、场次加价不露成本、查询失败 42011、锁座冻结 = 售价 × 张数 +
  座位带分区 + attach、分区按区价、选座不合法/场次不存在不建单、订单溢价 43004、确认只调一次并立即出票、锁座过期不能确认、
  释放取消解冻且不能再释放、订单查询带明细 + 跨商户隔离、未开通）、
  `test/Cases/Service/Order/MovieOrderSettlementServiceTest.php`（10 个：出票扣款 + 供应商返佣 × 等级比例、改票根和返佣晚到幂等、
  芒果超时失败原因、超时释放只动到期未确认的、回调按权威 attach 认领 / 伪造认领不了 / 查询失败不回成功、定时查询按芒果单号、
  全量同步 upsert + 失败城市不删、影院更新回调）、`MangoDriverTest::testBaseDataIsNormalized`。

---

## 7. 商户管理后台（web/merchant）

依据：[requirements.md 8.2](requirements.md#82-商户管理后台webmerchant)

| 模块 | 期次 | Controller | Service | 前端页面 | 状态（接口） |
|---|---|---|---|---|---|
| 账户：注册（企业/个人） | 一期 | ✅ | ✅ | ✅ `views/RegisterView.vue`（证件照片上传未开放） | ✅ |
| 账户：登录 | 一期 | ✅ | ✅ | ✅ `web/merchant/src/views/LoginView.vue` | ✅ |
| 账户：找回密码 | 一期 | ✅ `App\Controller\Merchant\AuthController` | ✅ `App\Service\Merchant\PasswordService` | ✅ `views/ForgotPasswordView.vue` | ✅ 只支持短信：`POST /merchant/auth/password/reset-code`（`phone`）发 6 位验证码（10 分钟有效、输错 5 次作废、同一手机号 60 秒冷却，手机号没注册也返回同样结果），`POST /merchant/auth/password/reset`（`phone`/`code`/`new_password`）；短信走阿里云（`App\Notify\Sms\AliyunSmsSender`，`.env` 配 `ALIYUN_SMS_*`，没配时验证码只写 `runtime/logs`）；只填邮箱的商户不能自助找回。测试 `test/Cases/Merchant/PasswordControllerTest.php`、`test/Cases/Notify/AliyunSmsSenderTest.php` |
| 账户：修改密码 | 一期 | ✅ | ✅ `App\Service\Merchant\PasswordService` | ✅ 右上角"修改密码"（`web/shared` 的 `ChangePasswordDialog`） | ✅ `PUT /merchant/auth/password`，返回新 token；token 里带密码版本（`pv`），改密码/找回密码后旧登录全部失效（两个后台都是） |
| 账户：资质提交与审核状态查看 | 一期 | ✅ `App\Controller\Merchant\QualificationController` | ✅ `App\Service\Merchant\QualificationService` | `views/QualificationView.vue` ✅（首页驳回提示链到这里；已在浏览器看过已启用商户的页面，驳回后重新提交的表单跟注册页共用 `components/QualificationFields.vue`） | ✅ `GET /merchant/qualification`：账户状态、类型、当前等级名、最新一次提交的资料（身份证号只返回后 4 位 `id_card_no_masked`）、历次提交和审核结果；`POST /merchant/qualification`：只有 `rejected` 商户能重新提交（待审核、已通过都是 409），锁商户行后新插一条待审核记录、商户回到 `pending`，可以换企业/个人类型（`merchants.type` 一起改）。字段校验和落库跟注册共用 `QualificationService::validate()/createPending()`，并补了按列长度的上限校验。审核通过后资质不能在线修改（没有已启用商户重新审核的流程），变更找平台。系统后台商户详情改成取最新一次提交（原来 `findByMerchantId()` 不排序，有多条时可能取到旧的驳回记录），并返回 `qualification_history`。测试 `test/Cases/Merchant/QualificationControllerTest.php`（注册 → 驳回 → 换类型重新提交 → 审核通过整条链路） |
| 首页：统计数据展示 | 一期 | ✅ `App\Controller\Merchant\DashboardController` | ✅ `App\Service\Merchant\DashboardService` | `views/DashboardView.vue` ✅ 已联调（2026-09-18）：余额/冻结/待到账返佣、今日订单数/消费/成功率、近 7 天消费柱状图（`components/TrendChart.vue`，内联 SVG，没引图表库；柱高是消费金额，订单数在悬浮提示里）；审核状态和欠款提示仍来自 `/merchant/auth/me` | ✅ `GET /merchant/dashboard`：`pending_rebate`、`today`（订单数、消费金额、成功/已出结果笔数、成功率）、`trend`（近 7 天含今天，最早在前，没单的日子补 0）。口径都按下单日期：订单数含处理中；消费 = 实扣 − 已退款；成功率 = 成功 ÷（成功 + 失败 + 已退款），处理中（含异常单）和商户取消的不计入，当天没有出结果的订单时为 `null`。一条 `OrderDao::dailySummaryForMerchant()` 按日期+状态分组查出，走 `(merchant_id, status, created_at)` 索引的前缀。测试 `test/Cases/Merchant/DashboardControllerTest.php` |
| 开发设置：生成 / 重置 AppKey 与 AppSecret | 一期 | ✅ | ✅ | `views/DevSettingsView.vue` ✅ 已联调（2026-09-18） | ✅ |
| 开发设置：IP 白名单配置 | 一期 | ✅ | ✅ | `views/DevSettingsView.vue` ✅ 已联调（2026-09-18） | ✅ |
| 服务开通：查看可开通业务线 / 提交申请 / 查看状态 | 一期 | ✅ `App\Controller\Merchant\SubscriptionController` | ✅ `App\Service\Merchant\SubscriptionService` | `views/ServiceView.vue` ✅ 已在浏览器看过列表（申请动作靠接口测试覆盖） | ✅ `GET /merchant/subscriptions`（四条业务线及开通状态，电影票/快递 `available=false` 暂未开放）、`POST /merchant/subscriptions`（`business_line`）：只有 active 商户能申请；每个商户每条业务线一行（表上 `(merchant_id, business_line)` 唯一），驳回后重新申请是把这一行改回 pending；审核中/已开通重复申请 409。**开放 API 门槛**：`SubscriptionService::isSubscribed()`，没开通的业务线商品列表和下单都返回新错误码 42007「未开通该业务线」（下单时放在幂等重放之后、欠款拦截之前，已下成功的单重提仍原样返回）。测试 `test/Cases/Merchant/SubscriptionControllerTest.php`，开放 API 侧见 `ProductControllerTest`/`RechargeOrderPlacementServiceTest` 新增用例（其余下单测试的商户 fixture 都补了已开通的话费/卡券） |
| 商品价格：售价与自己等级的返佣展示 | 一期 | ✅ `App\Controller\Merchant\ProductController` | ✅ `App\Service\Merchant\ProductPriceService` | `views/ProductPriceView.vue` ✅（按业务线分页签；在浏览器看过未开通/暂未开放两种状态，有商品的表格靠接口测试覆盖） | ✅ `GET /merchant/products?business_line=`：`available`（平台是否开放）、`subscribed`（本商户是否开通）、`level_rate`（本等级在该业务线的默认比例，电影票/快递就靠它展示）、`data`（只有已开通的话费/卡券才返回在架商品：面值、售价、每单返佣，返佣跟开放 API 同一个 `RebateCalculator`，含商品单独覆盖）。不返回返佣基数和比例来源。商品枚举文案（运营商/到账速度/卡券类型）从 admin 挪到 `web/shared` 共用。测试 `test/Cases/Merchant/ProductControllerTest.php` |
| 充值：提交申请 / 查看记录 | 一期 | ✅ `App\Controller\Merchant\RechargeRequestController` | ✅ `App\Service\Merchant\RechargeRequestService` | `views/finance/RechargeView.vue` ✅ 已联调（2026-09-18）（凭证只能填图片链接，上传接口未做） | ✅ |
| 资金流水：查询与导出 | 一期 | ✅ `App\Controller\Merchant\BalanceLogController` | ✅ `App\Service\Merchant\BalanceLogService` | `views/finance/BalanceLogView.vue` ✅ 已联调（2026-09-18；导出 2026-09-21） | ✅ 查询 `GET /merchant/balance-logs`（支持 `?type=` 筛选、分页，见第 8 节"充值与调账"行下方的说明）；导出 `GET /merchant/balance-logs/export`（同一套筛选、不分页），见下方「三处导出」说明。2026-09-23：每行带关联订单（`order_no` / `merchant_order_no` / `business_line`，冻结、扣款、解冻、补扣、退款、返佣入账扣回、快递理赔调账都挂订单），支持 `?order_no=`（平台单号或商户单号，只在自己的订单里找，找不到返回空）；页面加「关联订单」列（点进订单列表并打开详情）、单号筛选，导出多三列。系统后台商户详情的流水共用 `BalanceLogService::present()`，同样带单号、可按单号筛。`BalanceService::adjust()` 多一个可选 `orderId`，快递理赔调账传订单 |
| 返佣：明细查询与导出 | 一期 | ✅ `App\Controller\Merchant\RebateController` | ✅ `App\Service\Product\RebateQueryService` | `views/finance/RebateView.vue` ✅ 已联调（2026-09-18；导出 2026-09-21） | ✅ 查询：`GET /merchant/rebates`（status / business_line / order_no / created_from / created_to 筛选，最新在前），返回订单、业务线、订单金额、等级比例、返佣金额、状态、订单完成时间、预计到账和到账/作废/扣回时间，外加按当前筛选条件的分状态汇总 `summary`（笔数 + 金额，不分页）；返佣基数及来源、比例来源、等级属于平台内部数据，不返回。导出 `GET /merchant/rebates/export`（同一套筛选、不分页、不带 `summary`），见下方「三处导出」说明。测试 `test/Cases/Merchant/RebateControllerTest.php` |
| 订单管理：列表 / 详情 / 回调记录与手动重推 / 导出 | 一期 | ✅ `App\Controller\Merchant\OrderController` | ✅ `App\Service\Merchant\OrderService` | `views/order/OrderListView.vue` ✅ 已联调（2026-09-18；导出 2026-09-21）（列表可直接申请售后；2026-09-23 详情抽屉加快递/电影票明细，只过了类型检查，没有真实快递/电影票订单可在浏览器里看） | ✅ `GET /merchant/orders`（status / business_line / order_no / merchant_order_no / created_from / created_to 筛选，最新在前）、`GET /merchant/orders/{orderNo}`（字段同开放 API 订单查询，含明文卡密、快递 `express` / 电影票 `movie` 明细 + 回调记录）、`POST /merchant/orders/{orderNo}/renotify`（只允许已有最终结果的订单，60 秒内有过回调记录返回 429）；异常单显示为处理中，按 `status=processing` 筛选时包含异常单，不接受 `status=abnormal`；不含供应商和成本价；导出 `GET /merchant/orders/export`（同一套筛选、不分页，列同列表），见下方「三处导出」说明。测试 `test/Cases/Merchant/OrderControllerTest.php` |
| 售后：未到账争议提交与查看 | 一期 | ✅ `App\Controller\Merchant\DisputeController` | ✅ `App\Service\Merchant\DisputeService` | `views/order/DisputeListView.vue` ✅ 已联调（2026-09-18） | ✅ `POST /merchant/disputes`（`order_no`）、`GET /merchant/disputes`（`status` 筛选）、`GET /merchant/disputes/{id}`；只接受话费、卡券成功订单，订单成功后 `dispute_deadline_days`（默认 7）天内，一笔订单只能提交一次（被驳回后不能再提）；处理结果、说明和凭证商户可见。表里没有商户描述字段，提交时只选订单。见第 8 节"售后处理"说明，测试 `test/Cases/Merchant/DisputeControllerTest.php` |
| 接口文档：在线查看 / 下载签名示例 | 一期 | ✅ `App\Controller\Merchant\ApiDocController` | ➖ | `views/ApiDocView.vue` ✅ 已在浏览器看过各页签、错误码表和下载链接 | ✅ 文档正文在前端：接入流程、接口地址（默认当前域名 + `VITE_API_BASE_URL` + `/open-api`，开放 API 另有域名时配 `VITE_OPEN_API_BASE_URL`）、公共参数与签名规则（带固定参数的示例签名）、已上线的 5 个接口（余额、话费商品列表、话费下单、卡券下单、订单查询）的参数和返回、结果回调（字段、验签、`success` 应答、重试间隔）。错误码表由 `GET /merchant/api-docs/error-codes` 直接读 `ErrorCode::catalog()`，新增错误码不用改文档。签名示例放在 `web/merchant/public/sign-examples/`（PHP、Java、Python、Node.js，含签名、回调验签和查余额调用），四种语言对固定参数的输出、以及对服务端 `SignatureSigner` 签出的回调（值里带 `&`、`=`）的验签都已实际跑过、结果一致；Go 等其它语言没有运行环境验证，暂不提供。电影票、快递接口上线时要同步补文档。测试 `test/Cases/Merchant/ApiDocControllerTest.php` |


> 「三处导出」（requirements.md 7.2 资金流水 / 返佣、8.2 订单，2026-09-21）：`GET /merchant/balance-logs/export`、
> `GET /merchant/rebates/export`、`GET /merchant/orders/export`，筛选参数跟各自的列表接口完全一样，只是不接受
> `page`/`per_page`，一次返回 `{data, total}` 全量。
> - **导出接口返回 JSON，CSV 在前端拼**（`web/shared/src/csv.ts` 的 `downloadCsv`）：枚举值的中文名（充值/冻结/待到账……）
>   只在 `web/shared/src/labels.ts` 存一份，后端不用再抄一份中文标签跟着前端一起漂，导出的列和页面上看到的列因此天然一致。
>   代价是前端要把全部行拿进内存，由行数上限兜底。CSV 带 UTF-8 BOM（否则 Excel 按 GBK 猜编码，中文全是乱码），
>   `=`/`+`/`-`/`@` 开头的单元格前面补单引号（Excel 会把它们当公式执行，导出的内容里有商户自己填的备注）。
> - **行数上限 `App\Export\ExportLimit::MAX_ROWS` = 10000，超了返回 422 而不是截断**：导出接口不分页，Swoole worker
>   常驻内存、多个请求共用进程，无上限的全量导出最容易把 worker 拖垮；而截断会安静地少给数据，商户拿去对账时发现不了，
>   比导不出来严重得多。要导更多应该走离线任务。
> - 返佣导出复用 `RebateQueryService`，本次把行格式化从 `list()` 抽成 `formatRows()` 两边共用——导出不能走 `list()`，
>   那里的 `per_page` 会被 `MAX_PER_PAGE`(100) 夹住。系统后台的返佣导出没做（8.3 没要求，只有 8.2 商户后台明确要求）。
> - `/merchant/orders/export` 跟 `/merchant/orders/{orderNo}` 同前缀，靠 FastRoute 静态段优先于变量段匹配，
>   有专门的用例守着（`testExportRouteIsNotShadowedByOrderNo`）。
> - 测试 `test/Cases/Merchant/ExportControllerTest.php`；三个按钮已在浏览器点过（2026-09-21），核对过导出的
>   CSV 字节：开头是 UTF-8 BOM（EF BB BF）、行数 = 表头 + 全部数据行（资金流水 15 行、订单 8 行，都超过或等于一页）、
>   枚举列是中文（冻结/扣款/话费/处理中）、没有记录时不下载空文件只提示。

---

## 8. 系统管理后台（web/admin）

依据：[requirements.md 8.3](requirements.md#83-系统管理后台webadmin)

| 模块 | 期次 | Controller | Service | 前端页面 | 状态（接口） |
|---|---|---|---|---|---|
| 商户管理：列表 | 一期 | ✅ `App\Controller\Admin\MerchantController` | ✅ `App\Service\Admin\MerchantAdminService` | `views/merchant/MerchantListView.vue` ✅ 已联调（2026-09-18） | ✅ |
| 商户管理：入驻审核（通过 + 分配等级 / 驳回）/ 详情 | 一期 | ✅ `App\Controller\Admin\MerchantController` | ✅ `App\Service\Admin\MerchantAdminService` | `views/merchant/MerchantDetailView.vue` ✅ 已联调（2026-09-18） | ✅ |
| 商户管理：资金流水查看（客服/审计，只读） | 一期 | ✅ `App\Controller\Admin\MerchantController` | ✅ `App\Service\Admin\MerchantAdminService` | `views/merchant/MerchantDetailView.vue` ✅ 已联调（2026-09-18） | ✅ `GET /admin/merchants/{id}/balance-logs`，跟商户自己看到的资金流水同一份数据，用现有 `merchant.view` 权限（看流水跟看详情是同一档权限，没有单独开一档），见第 8 节"充值与调账"行下方的说明 |
| 商户管理：启用禁用 / 调整等级（针对已 active 商户的后续变更）/ 限流设置 | 一期 | ✅ `App\Controller\Admin\MerchantController` | ✅ `App\Service\Admin\MerchantAdminService` | `views/merchant/MerchantDetailView.vue` ✅ 已联调（2026-09-18） | ✅ `POST /admin/merchants/{id}/status`、`PUT /admin/merchants/{id}/level`、`PUT/DELETE /admin/merchants/{id}/rate-limit`，权限 `merchant.manage`，详见下方说明 |
| 服务开通审核 | 一期 | ✅ `App\Controller\Admin\SubscriptionController` | ✅ `App\Service\Admin\SubscriptionAdminService` | `views/merchant/SubscriptionReviewView.vue`（菜单「开通审核」；商户详情显示各业务线状态并可跳转） ✅ 已在浏览器看过 | ✅ `GET /admin/subscriptions`（status / business_line / merchant_id 筛选，待审核按申请时间先到先审，带商户名称/联系方式/状态）、`POST /admin/subscriptions/{id}/approve`、`POST /admin/subscriptions/{id}/reject`（`reason` 必填）；事务里锁行、只能审 pending（409）。权限 `subscription.view` / `subscription.review`（预置给运营；已有的运营角色不会自动补，需要在角色权限里勾）。操作日志由 `AdminOperationLogAspect` 自动记。商户详情接口新增 `subscriptions`。测试 `test/Cases/Admin/SubscriptionControllerTest.php` |
| 充值与调账：充值审核 / 手动调账 | 一期 | ✅ `App\Controller\Admin\RechargeRequestController`（充值审核）/ ✅ `App\Controller\Admin\MerchantController`（手动调账） | ✅ `App\Service\Admin\RechargeRequestAdminService`（充值审核）/ ✅ `App\Service\Admin\MerchantAdminService`（手动调账） | `views/merchant/RechargeReviewView.vue`、调账在商户详情 ✅ 已联调（2026-09-18） | ✅ 充值申请审核（此前完成）+ 手动加扣余额（调账，`POST /admin/merchants/{id}/balance-adjustments`，`merchant_balance_logs.type = 'adjustment'`，"必填原因，直接生效，不需要二次审核"，独立权限编码 `merchant.balance_adjust`）两半都已完成，见第 1 节"商户余额冻结/扣款/解冻/返佣结算"行 `adjust()` 部分的说明 |
| 本地商品库：CRUD | 一期 | ✅ `App\Controller\Admin\ProductController` | ✅ `App\Service\Admin\ProductAdminService` | `views/product/ProductListView.vue`、`ProductDetailView.vue`（含等级比例覆盖、5.5 保护提示前端计算） ✅ 已联调（2026-09-18） | ✅ `GET/POST /admin/products`、`GET/PUT /admin/products/{id}`、`POST /admin/products/{id}/status`、`PUT/DELETE /admin/products/{id}/level-rebates/{levelId}`；权限 `product.view` / `product.manage`（已加进 `AdminBootstrapService::KNOWN_PERMISSIONS`）。这是 `App\Model\Product`/`App\Dao\ProductDao`（commit 233d5d9）此前一直缺失的写入侧——那次提交只建了模型和开放 API 用的只读查询，商品行此前只能靠测试直接用 Dao 插入。新建商品默认下架（`status` 默认 `off_shelf`：避免刚建好、字段可能还没配置齐全的商品被意外立即上架，需运营显式上架）；`business_line` 只接受 recharge/card（这个代码库目前只有这两条业务线建了下单路由基础设施，即便数据库列本身不限制取值也主动拒绝其它值）；按 recharge/card 分别要求 `operator`/`card_type` 必填，供错业务线的字段（如给 recharge 商品传 `card_type`）直接拒绝，清晰 4xx 而非静默接受；详情接口带出该商品全部 `ProductLevelRebate` 覆盖（联表 `level_name`，同 `ProductMappingAdminService` 联表供应商名称的做法）。等级比例覆盖的写入/删除结构跟商户等级任务（commit 4de61e8）一致：设置用 `ProductLevelRebateDao::upsertRate()`（数据库原生 upsert，按 `(product_id, level_id)` 唯一索引原地更新，跟 `MerchantLevelBusinessRateDao::upsertRate()` 同一技术）；新增 `DELETE /admin/products/{id}/level-rebates/{levelId}`（商户等级任务没有的接口——删除有实际业务含义：回退到该等级在该业务线的默认比例，对不存在的覆盖行删除返回 404 而非静默成功），已用真实联调测试验证：设置覆盖后 `RebateCalculator` 读到 `rateSource='product_level'`，删除覆盖后回退读到 `rateSource='level'`。测试 `test/Cases/Admin/ProductControllerTest.php`。**范围之外**：5.5 的价格/返佣保护提示（低于成本价、毛利为负、返佣比例超 100%）与操作日志记录均未建（没有后台前端展示 / 没有操作日志基础设施，见 `ProductAdminService` 类注释）；不含 `supplier_products` 商品映射（已是独立功能，`ProductMappingController`） |
| 供应商管理：配置 CRUD（新建/列表/详情/修改/启用禁用，requirements.md 6.3） | 一期 | ✅ `App\Controller\Admin\SupplierController` | ✅ `App\Service\Admin\SupplierAdminService` | `views/supplier/SupplierListView.vue` ✅ 已联调（2026-09-18） | ✅ |
| 供应商管理：商品映射（新建/列表/改价（必留痕）/优先级/启停，requirements.md 6.4） | 一期 | ✅ `App\Controller\Admin\ProductMappingController` | ✅ `App\Service\Admin\ProductMappingAdminService` | `views/product/ProductDetailView.vue` ✅ 已联调（2026-09-18） | ✅ |
| 供应商管理：商品同步接入 / 余额监控 / 熔断状态 / 调用日志 / 统计 | 一期（熔断二期，均已完成） | ✅ `App\Controller\Admin\SupplierController` | ✅ `App\Service\Supplier\SupplierCallLogService` / `SupplierNotifyAddressService`、`App\Service\Admin\SupplierAdminService` / `SupplierStatsService` | `views/supplier/SupplierDetailView.vue`（列表点名称进入）✅ 已在浏览器点过刷新余额、同步商品、调用日志；统计卡片已联调（2026-09-21，按天/按商品都用真实 order_attempts 数据核对过） | ✅ 回调地址、余额监控、商品同步、调用日志、统计、熔断状态都已完成。熔断见第 5 节「熔断」说明，其余见下方说明 |
| 商户等级：CRUD / 各业务线比例设置 | 一期 | ✅ `App\Controller\Admin\MerchantLevelController` | ✅ `App\Service\Admin\MerchantLevelAdminService` | `views/merchant/MerchantLevelView.vue` ✅ 已联调（2026-09-18） | ✅ `GET/POST /admin/merchant-levels`、`GET/PUT /admin/merchant-levels/{id}`、`PUT /admin/merchant-levels/{id}/rates/{businessLine}`；权限 `merchant_level.view` / `merchant_level.manage`（已加进 `AdminBootstrapService::KNOWN_PERMISSIONS`）。列表全量不分页（等级是少量配置行）；详情 `rates` 固定含 recharge/card/movie/express 四个 key，`null` = 未设置、`'0.0000'` = 明确设为 0%；比例设置用 `MerchantLevelBusinessRateDao::upsertRate()`（数据库原生 upsert，按 `(level_id, business_line)` 唯一索引原地更新），接受非负、最多 4 位小数、不超过列上限 99.9999 的值，超过 1（100%）照样保存不拒绝（5.5 只要求提示，前端未建）。没有删除接口；不含调整商户所属等级。测试 `test/Cases/Admin/MerchantLevelControllerTest.php`，含写入后 `RebateCalculator` 读到新比例的联调用例。商品单独覆盖某等级比例（`product_level_rebates`）已在「本地商品库」行完成 |
| 价格设置：电影票 / 快递加价规则 / 价格预览 | 三期 | ✅ `App\Controller\Admin\PricingRuleController` | ✅ `App\Service\Product\PricingRuleService` | `views/product/PricingRuleView.vue`（菜单在商品与供应商下）✅ 已联调（2026-09-21） | ✅ 见下方「价格设置」说明 |
| 返佣管理：固定期限设置 / 商户返佣明细 / 供应商返佣明细 | 一期（供应商返佣明细三期） | ✅ `App\Controller\Admin\RebateController` | ✅ `App\Service\Product\RebateQueryService` / `App\Service\Admin\SupplierRebateAdminService` | `views/merchant/RebateListView.vue`（菜单「商户返佣」，商户详情可跳转按商户筛选）✅ 已联调（2026-09-18）；`views/merchant/SupplierRebateListView.vue`（菜单「供应商返佣」，2026-09-23，只过了类型检查） | ✅ 供应商返佣明细 2026-09-23 补齐，见下方「供应商返佣」说明。固定期限设置在系统参数 `rebate_due_period_days`（见「系统设置」行）；商户返佣明细 `GET /admin/rebates`，权限 `rebate.view`（预置给财务），筛选同商户后台另加 `merchant_id`，多返回商户手机号/邮箱、等级名、返佣基数及来源、比例来源，以及当前返佣期限 `due_period_days`；与商户后台共用 `RebateQueryService` 和 `MerchantRebateDao::paginateFiltered()/countFiltered()/summarizeByStatus()`。测试 `test/Cases/Admin/RebateControllerTest.php` |
| 订单管理：全部订单查询 / 详情 / 异常单处理 / 部分退款处理 / 手动查询供应商 / 手动重推商户回调 | 一期 | ✅ `App\Controller\Admin\OrderController` | ✅ `App\Service\Admin\OrderAdminService` | `views/order/OrderListView.vue`、`OrderDetailView.vue` ✅ 已联调（2026-09-18；2026-09-23 详情加快递/电影票明细卡片、撤单和部分退款按钮，只过了类型检查） | ✅ 全部完成，见下方说明（部分退款、发起供应商撤单 2026-09-23 补齐）。2026-09-23：详情接口多 `express`（寄收件人、预估/冻结/实际运费成本和向商户收的运费、其它三项实际费用、费用调整含原因）和 `movie`（场次、座位、每张售价和成本、供应商返佣、取票码）两段；两个后台共用 `web/shared` 的 `ExpressDetailInfo` / `MovieDetailInfo` 组件（后台版多出的成本字段有就显示）。快递、电影票异常单只能人工置失败（测试 `testExpressAndMovieAbnormalOrdersCannotBeResolvedAsSuccess`） |
| 售后处理：话费卡券争议处理 / 快递工单代提交与跟踪 | 一期（快递工单三期） | ✅ `App\Controller\Admin\DisputeController`、`ExpressWorkorderController` | ✅ `App\Service\Admin\DisputeAdminService`、`ExpressWorkorderAdminService` | `views/order/DisputeListView.vue` ✅ 已联调（2026-09-18）；`views/order/ExpressWorkorderListView.vue` + 订单详情「快递工单」卡片（2026-09-23，只过了类型检查） | ✅ 话费卡券争议处理见下方说明；快递工单 2026-09-23 完成，见下方「快递工单」说明 |
| 财务报表 | 二期 | ✅ `App\Controller\Admin\ReportController` | ✅ `App\Service\Admin\FinanceReportService` | `views/FinanceReportView.vue`（顶层菜单「财务报表」）✅ 已联调（2026-09-21） | ✅ 见下方「财务报表」说明 |
| 对账：订单对账 / 返佣对账 / 差异标记处理 | 二期 | ✅ `App\Controller\Admin\ReconciliationController` | ✅ `App\Service\Admin\ReconciliationAdminService`（后台）/ `App\Service\Reconciliation\ReconciliationService`（产生） | `views/ReconciliationListView.vue`（顶层菜单「对账」）✅ 已联调（2026-09-21） | ✅ 订单对账 + 差异标记处理（2026-09-21）；返佣对账 2026-09-23 随电影票补齐（快递暂不对账），见下方「对账」和「供应商返佣」说明 |
| 告警：列表查看 / 标记处理 | 二期 | ✅ `App\Controller\Admin\AlertController` | ✅ `App\Service\Admin\AlertAdminService`（后台）/ `App\Service\Alert\AlertService`（产生） | `views/AlertListView.vue`（顶层菜单「告警」）✅ 已联调（2026-09-21） | ✅ 见下方「告警」说明 |
| 系统设置：管理员账号 / 角色权限 / 系统参数 / 操作日志 | 一期 | ✅ `AdminUserController` / `RoleController` / `SystemSettingController` / `OperationLogController` | ✅ `AdminUserAdminService` / `RoleAdminService` / `SystemSettingAdminService` / `OperationLogAdminService` | `views/system/*` ✅ 已联调（2026-09-21，四页逐个走过，见下方说明）；菜单按 `/admin/auth/me` 返回的权限显示 | ✅ 管理员：不能禁用自己/改自己角色，只有超管能动超管账号，至少保留一个启用的超管；角色：不能改自己所在角色的权限、只能授出自己有的权限，预置运营/财务/客服（`admin:sync-permissions` 补建）；系统参数：代码里在读的 6 项，带范围校验；返佣期限可以短于争议时限（requirements.md 5.4「扣回」允许，到账后才核实未到账的从余额扣回），原先的互相限制已去掉；操作日志：`App\Aspect\AdminOperationLogAspect` 给所有挂了 `#[RequiresPermission]` 的写操作自动记日志（敏感字段打码），Service 手动记过前后对比的不重复记；管理员修改自己密码 `PUT /admin/auth/password` |

> 「价格设置」（requirements.md 5.1、8.3，2026-09-21）：新建 `pricing_rules` 表（三期表里第一张）+
> `App\Model\PricingRule` / `App\Dao\PricingRuleDao`，规则读写和售价计算都在
> `App\Service\Product\PricingRuleService`。
> - **电影票、快递各一条规则，所有商户统一**（`business_line` 唯一索引，Dao 用原生 upsert 原地更新，
>   两个运营同时保存不会插出两行）。话费、卡券不走这张表——它们的售价是运营给每个商品直接设置的。
> - **快递只对运费加价**：保价费、耗材费、逆向费按成本转给商户（5.1 + 7.2）。服务本身看不出传进来的成本是
>   "一整单"还是"其中一项"，所以这个约定写在类注释里：快递下单流程接上时只把运费传进
>   `salePriceFor()`，别把 `totalFreight` 整个丢进去加价。
> - **百分比四舍五入到分，跟商户返佣的"向下取整"方向相反**（5.1 和 5.3 分别白纸黑字写的，不要互相"统一"）：
>   返佣是平台付出去的钱，向下取整对平台有利；售价是商户付的钱，文档要求四舍五入。bcmath 的 scale 是截断，
>   所以四舍五入靠"先加半分再截断"实现，用例里专门验了 0.10 × 1.05 = 0.105 要进位成 0.11。
> - **没配规则时 `salePriceFor()` 抛错，绝不"按成本卖"**：那是平台白干还倒贴。也因此没有删除接口——
>   "不想加价"应该显式设成加价 0，不是把规则删了让下单直接失败。
> - **加价不能为负**（5.5 要求提示"低于成本价"，负加价一定低于成本，直接拒绝）；固定金额上限 9999.99 元、
>   百分比上限 1000%，纯粹防手滑。
> - 价格预览（8.3 明确列的）：给一个成本看售价和毛利，可以传 `rule_type`/`value` 预览"改成这样会是多少"
>   而**不保存**——运营调参数试算时不该改到线上价格。预览和下单用的是同一个 `apply()`，两处各写一遍迟早会漂。
> - 接口：`GET /admin/pricing-rules`（两条业务线各一行，没配过的返回 null 规则而不是 0，前端要能分清
>   "没设置"和"加价 0 元"）、`PUT /admin/pricing-rules/{businessLine}`、`GET /admin/pricing-rules/preview`。
>   权限 `pricing.view` / `pricing.manage`（已进 `KNOWN_PERMISSIONS`，**部署后执行 `admin:sync-permissions`**；
>   预置给运营两个都有、财务只有 view——加价规则决定毛利，财务要能看，改由运营负责）。
> - 前端：菜单「商品与供应商 → 加价规则」，两条业务线各一张卡片 + 一个价格预览区。百分比在界面上按 `%` 输入、
>   存的是比例（5% ↔ 0.0500），换算只在页面里做一次。
> - 测试 `test/Cases/Admin/PricingRuleControllerTest.php`（11 个：未设置返回 null、固定/百分比保存与就地更新、
>   四舍五入进位、7.2 的"成本 10 → 售价 12"例子、预览未保存规则不落库、没规则时预览 422、
>   非法值和非法业务线 422、只读权限不能改、`salePriceFor()` 没配规则抛错）。

> 「财务报表」（requirements.md 8.3、1.2，2026-09-21）：两个只读聚合接口，不建表
> （database-design.md 6「财务报表走查询/视图，不新增表」）。
> - **订单毛利和返佣收支分开算、最后才相加**（1.2）：每行给 `gross_profit`（售价合计 − 成本合计）、
>   `rebate_balance`（供应商返佣 − 商户返佣）、`total_profit`（两者之和）三个数。混成一个净利会让
>   "哪一头出了问题"看不出来。用 1.2 的例子验过：售价 99.20、成本 98.50、返佣 0.50 → 毛利 0.70、
>   返佣收支 -0.50、合计 0.20。
> - **时间轴是订单完成时间**（`orders.completed_at`）：毛利在订单完成那刻才确定，返佣也从这个时间
>   起算（5.4），两笔收支挂在同一个时间点上，"这一天赚了多少"才是一句能对账的话。
> - **已退款订单不计毛利，但单独成列**（`refunded_count` / `refunded_amount`）：退款后毛利不成立，
>   混进去会虚高；藏起来又会让人以为这天风平浪静。失败、已取消的订单没有收支，不取数。
> - **返佣只算 `pending` + `settled`**：这两个状态是平台欠着或已经付了的钱；`voided`、`clawed_back`
>   等于没付出去。只看返佣状态、不看订单当前状态，跟返佣明细页同一个口径。
> - **按等级分组用商户当前等级**：`orders` 没有等级快照（只有 `merchant_rebates` 有）。两边都用当前
>   等级，行能对上但历史订单会跟着调级走；各用各的，同一行的两半就不是同一批订单。选了前者，
>   单笔订单当时按哪个等级返的佣在返佣明细页查得到。这个取舍写在 `OrderDao::applyReportGrouping()`。
> - **供应商返佣**（2026-09-23 接上）：电影票、快递成功订单明细上的 `supplier_rebate` 之和，已退款的不算；
>   话费、卡券没有供应商返佣。
> - 接口：`GET /admin/reports/profit`（`group_by` = day / merchant / level / business_line / supplier，
>   `from`/`to` 默认最近 30 天、最多跨 92 天，可选 `merchant_id`；按天分组补齐空日期；分组键带可读名称，
>   商户用手机号/邮箱——商户表没有名称列，跟返佣明细页一致）、`GET /admin/reports/balance-flows`
>   （按 `merchant_balance_logs.type` 汇总笔数和金额，`adjustment` 那行是带符号的净额）。
>   两个接口分开而不是并成一个响应：资金流水按发生时间、毛利按订单完成时间，凑一行会让人以为能相减。
>   权限 `report.view`（已进 `KNOWN_PERMISSIONS`，**部署后执行 `admin:sync-permissions`**；
>   预置只给财务——报表把全平台成本价和毛利摊开，不是运营日常要看的东西）。
> - 前端：顶层菜单「财务报表」，顶部四个汇总数 + 维度切换 + 日期区间 + 商户 ID 筛选，
>   下面两张表（利润、资金流水），导出 CSV 用 `web/shared` 已有的 `downloadCsv`（列跟页面一致）。
> - 测试 `test/Cases/Admin/ReportControllerTest.php`（1.2 的例子逐字段核对、已退款单独成列、
>   作废与已扣回不算支出、四种分组维度及可读名称、资金流水按类型汇总且调账为净额、
>   非法参数 422、没有 `report.view` 403）。

> 「对账」（requirements.md 8.3，database-design.md 4.15，2026-09-21）：新建 `reconciliation_diffs` 表 +
> `App\Model\ReconciliationDiff` / `App\Dao\ReconciliationDiffDao`，差异由
> `App\Service\Reconciliation\ReconciliationService` 产生，后台只读和标记状态（同「告警」的取舍）。
> - **一批 = 一天**：`reconciliation_date` 按 4.15 存"跑对账任务的日期"，批次对的是**前一天完成**的订单
>   （09-21 的批次对 09-20 的订单）。手动重跑传的也是批次日期，覆盖窗口跟着往前推一天，
>   这样"重跑 09-21 这批"永远指同一批订单，整批替换（先删该 `(type, date)` 的旧行再写新行，不追加）才有意义。
>   重跑会把上一轮已标记处理的行一起换掉：批次是"这一天对出来的事实"，保留旧标记会出现"差异还在却显示已处理"。
> - **按 `finished_at` 取终态订单**（`OrderDao::listFinishedBetween()`，为此给 `orders.finished_at` 补了索引）。
>   用 `created_at` 会让昨天下单、今天才出结果的订单在昨天的批次里被对成"平台处理中 vs 供应商成功"的假差异。
>   处理中和异常单不参与：前者还没有结论可对，后者本来就在等人工处理（7.4），对账再报一遍是把同一件事说两次。
> - **拿不到供应商记录不算差异**：查询超时/网络错误时驱动返回 `Unknown`，这是"我们没查到"而不是"两边对不上"，
>   只计进 `unreachable` 并记日志，下一天的批次会再对一次。但**供应商确实答了、只是结果本身不确定**
>   （卡速售部分退款，kasushou.md 第 2 节）要报差异——这正是最该被人看见的情况。两者都是 `Unknown`，
>   靠"驱动有没有解析出这笔订单"（`supplierOrderNo` / `actualCost` 至少有一个有值）区分，判断的是结构不是文案。
> - 比两项：`status`（平台终态 vs 供应商状态，成功后被全额退款就落在这里）和 `cost_price`
>   （只在两边都成功时比，差额 = 平台 − 供应商，用 `bcsub` 不转 float）。一边失败时供应商返回的金额要么是 0、
>   要么是退款前的原值，比了只会把 `status` 那条差异重复说一遍。
> - **`rebate` 类型**（2026-09-23）：电影票订单并进同一次对账，一次查询同时出订单差异和返佣差异，见下方「供应商返佣」说明。
> - 接口：`GET /admin/reconciliations`（type / status / field / supplier_id / order_no / date_from / date_to
>   筛选，待处理在前、其次批次倒序，带出订单号和供应商名，额外返回不受筛选影响的 `open_count`）、
>   `POST /admin/reconciliations/run`（`date` 批次日期，默认今天，只允许最近 90 天，**同步执行**，跟供应商商品
>   手动同步一致）、`POST /admin/reconciliations/{id}/resolve`、`.../ignore`（都可带 `remark`，列表里直接显示，
>   避免下一个人重查一遍同一笔订单）。权限 `reconciliation.view` / `reconciliation.handle`（已进
>   `KNOWN_PERMISSIONS`，**部署后执行 `admin:sync-permissions`**；预置角色里财务两个都有——对账是财务的活，
>   运营只有 view）。重跑归到 `handle` 而不是单开第三个编码：它改的是同一批差异记录。
> - 前端：顶层菜单「对账」（跟「告警」一样不塞进某个模块），默认筛"待处理"，表头可以选批次日期重跑并提示
>   会整体替换该批次，订单号和供应商名直接跳对应详情页。2026-09-21 已登录联调：跑批次（供应商配置解密失败的
>   历史数据如实计进"没拿到供应商记录"）、列表渲染、标记已处理带备注、忽略不带备注、按订单号和状态筛选都走过一遍。
> - 测试 `test/Cases/Service/Reconciliation/ReconciliationServiceTest.php`（一致不报差异、状态不一致、供应商
>   仍处理中、金额差额带符号、一边失败不比金额、查不到供应商记录不算差异、答了但结果不确定算差异、单个供应商
>   配置坏了不影响别家、重跑整体替换、只对窗口内的终态订单）+ `test/Cases/Admin/ReconciliationControllerTest.php`
>   （重跑后能按订单号查到、备注与处理人、忽略与重复标记 409、非法筛选和批次日期 422、查不到的订单号返回空列表、
>   只读权限不能标记也不能重跑）。

> 「供应商返佣」（requirements.md 5.4、8.3，2026-09-23）：电影票上线后把"供应商返佣"这条账接完整。
> - **明细**：`GET /admin/rebates/supplier`（权限同商户返佣明细 `rebate.view`），一行一笔电影票/快递的**成功**订单：
>   供应商返佣（`null` = 供应商还没给）、这笔订单的商户返佣及比例和状态、返佣收支（供应商返佣 − 商户返佣，作废/已扣回的
>   商户返佣不减）。筛选 business_line（movie/express）、rebate（returned 已返回 / missing 未返回）、supplier_id、merchant_id、
>   order_no、completed_from/completed_to；汇总按同一筛选给笔数、已返回/未返回笔数、两边金额和返佣收支。已退款订单不列，
>   跟财务报表同一口径（供应商会收回返佣）。快递行目前都是"未返回"：云洋没有返佣字段。实现
>   `App\Service\Admin\SupplierRebateAdminService` + `OrderDao::paginateSupplierRebates()/summarizeSupplierRebates()`。
> - **财务报表、供应商统计**原来固定返回 0.00 的供应商返佣改成真实汇总（口径见上面两段）。
> - **返佣对账**：`ReconciliationService::runOrderReconciliation()` 的取数范围从"所有终态订单"改成话费、卡券、电影票
>   （`BUSINESS_LINES`）。电影票按芒果单号查芒果订单详情，比状态、成本；两边都成功且芒果给了返佣时再比
>   `order_movies.supplier_rebate`（平台没记按 0.00）和芒果 `total_rebate`，对不上写一条 `type=rebate, field=rebate_amount`，
>   差额 = 平台 − 芒果。供应商返佣没有单独的账单接口，查询订单详情给的就是芒果认定的最终值。订单差异和返佣差异两个批次在
>   同一个事务里整体替换；汇总多返回 `rebate_checked` / `rebate_diff_count`，后台重跑的提示里一起显示。
>   芒果的"结果未知"一律算没拿到记录（芒果驱动传输失败也带回单号，卡速售那套"看结构"的判断对它不成立）。
>   **快递不参与对账**：云洋"成功"是扣费完成、之后还有费用调整，平台成本是四项实际费用之和，云洋查询给的是总运费，口径没跟
>   云洋核对过，硬比只会天天报假差异；云洋也没有返佣。**顺带修掉**：原来快递、电影票订单也会进对账，按卡速售驱动去建
>   云洋/芒果供应商的驱动必然失败，每天在日志里报错并计进"没拿到供应商记录"。
> - **补晚到的返佣**：后台订单详情「查询供应商」对电影票成功订单开放（原来只有话费卡券的成功订单能查），查到返佣就写进
>   `order_movies.supplier_rebate` 并按 5.3 生成商户返佣（已生成过的不重复），取票码变了再回调商户；不重复扣款。
>   返佣对账报出"平台 0.00、芒果 x.xx"时客服在这里处理。
> - 测试：`ReconciliationServiceTest::testMovieOrdersReconcileSupplierRebate`（返佣一致不报、没接住晚到返佣报一条、芒果查询
>   失败不算差异、快递不查、重跑不重复）、`RebateControllerTest::testSupplierRebateListShowsMovieRebatesAndBalance`、
>   `ReportControllerTest::testMovieSupplierRebateIsCountedInRebateBalance`（5.3 例 2：3.00 − 2.40 = 0.60）、
>   `OrderControllerTest::testQuerySupplierOnSuccessMovieOrderPicksUpLateRebate`。

> 「告警」（requirements.md 8.3，database-design.md 4.14，2026-09-21）：新建 `alerts` 表 + `App\Model\Alert`
> / `App\Dao\AlertDao`。产生全部走 `App\Service\Alert\AlertService::raise()`（全平台唯一写这张表的地方），
> 后台只读和标记状态。
> - **去重是这个服务存在的主要理由**：同一 `(type, related_type, related_id)` 已有 `open` 记录时不新插，
>   只更新最近触发时间、内容和 `occurrence_count`。供应商余额持续走低时定时任务每 5 分钟命中同一条，
>   没有去重后台一天就会被刷出几百条一样的记录，真正的新问题反而被淹掉。**处理或忽略之后再触发算新的一条**——
>   这正是"这个问题又回来了"该有的信号，不是把已处理的那条偷偷改回未处理。`occurrence_count` 用数据库
>   原地自增，定时任务和下单链路同时触发时不会丢计数。
> - **`raise()` 永不抛**：调用方都是下单链路、定时任务这种"告警只是副作用"的地方，写不进去最多少一条记录，
>   不能反过来把主流程搞挂。每条告警同时记一条 `alert` 渠道日志：后台列表给运营看，日志给排查的人看。
> - **后台不产生也不删除告警**，只有 `resolve`（已处理）/ `ignore`（已忽略）两个动作。告警是系统观察到的事实，
>   运营能做的是"我处理了/这条不用管"，不是把它抹掉。两者行为相同、语义不同，分开记是为了回看历史时能分辨
>   "修过多少次"和"忽略过多少次"。并发标记靠条件更新，后一个拿 409 而不是覆盖前一个的处理人。
> - 接口：`GET /admin/alerts`（status / type / level / related_type / related_id / triggered_from / triggered_to
>   筛选，未处理在前、其次最近触发倒序，额外返回不受筛选影响的 `open_count`）、`POST /admin/alerts/{id}/resolve`、
>   `POST /admin/alerts/{id}/ignore`。权限 `alert.view` / `alert.handle`（已进 `KNOWN_PERMISSIONS`，
>   **部署后执行 `admin:sync-permissions`**；预置角色里运营两个都有、财务只有 view——余额和欠款类告警是财务要盯的，
>   处理与否由运营决定）。
> - **7 个 type 常量一次定义齐全**（取值范围是 8.3 给定的，不是按"现在写了几个触发点"决定），当前的产生方：
>   `supplier_low_balance` ✅（`SupplierBalanceService`：定时刷新后低于预警线报 warning，供应商返回"预存款不足"
>   报 critical——后者说明订单已经在失败了）、`supplier_circuit_broken` ✅ 和 `product_fail_rate_spike` ✅
>   （`CircuitBreakerService`：整家熔断报前者 critical，单商品熔断报后者 warning，两者影响面差一个量级，
>   在列表里要能一眼分开）。`supplier_refund_after_success` ✅（2026-09-23，`SupplierRefundAfterSuccessService`，见「订单管理」说明）。
>   **还没有产生方**：`abnormal_order_backlog`（缺"积压多少算多"的阈值和巡检点）、
>   `rebate_loss`（5.5 的保护提示目前只在前端算）、`merchant_debt_exceeded`（`isOverDebtWarningThreshold()`
>   只是个读取端判断，要告警得在余额变动后或用巡检任务触发）。补检测链路时只需找地方调 `AlertService`，
>   不用回头改枚举、迁移注释和前端中文名三处。
> - 前端：顶层菜单「告警」（不塞进某个模块——7 类告警分别指向供应商、商品、商户、订单，挂在任何一个模块下
>   都会显得只跟那个模块有关），列表默认筛"未处理"，关联对象直接跳对应详情页，重复次数 > 1 标黄。
> - 测试 `test/Cases/Admin/AlertControllerTest.php`（去重累加、不同对象/类型分开、处理后再触发是新的一条、
>   列表排序与筛选、忽略与已处理分开记、重复标记 409、非法筛选 422、只读权限不能标记）。

> 「系统设置」四页联调（2026-09-21）：没有改代码，四页的功能都按设计工作，记录一下核对过哪些点。
> - **管理员账号**：列表带角色/状态筛选，自己那行标「（我）」且没有禁用按钮；新建弹窗的角色下拉走
>   `GET /admin/admin-users/role-options`。
> - **角色权限**：预置角色带「预置」标记且没有删除按钮；超管自己所在的角色整行没有编辑/删除按钮
>   （"不能改自己所在角色的权限"）；编辑弹窗的权限树按模块分组，逐项跟 `AdminBootstrapService::KNOWN_PERMISSIONS`
>   对过，**代码与数据库两边各 29 项、零差异**（`comm` 双向比对），`/admin/auth/me` 返回的也是这 29 项。
> - **系统参数**：6 项都渲染出范围和默认值；超范围保存由后端返回 422、前端弹出「欠款预警线的范围是
>   0.00~10000000.00 元」；保存成功后「默认」标记消失、「最近修改」显示操作人和时间，并多出「恢复默认」按钮；
>   恢复默认带二次确认，确认后删掉 `system_settings` 里那一行（联调用 `debt_warning_threshold`
>   1000 → 1500 → 恢复默认走了一遍，表回到 0 行，数据已还原）。
> - **操作日志**：`moduleLabels` 覆盖了库里出现的全部 module 值（merchant / merchant_level / product /
>   product_mapping / recharge / supplier / system）；详情展开能看到修改前后对比（上面那次改参数显示
>   `1000.00 → 1500.00`）；**操作人账号被删掉的历史日志降级显示 `#id` 而不是整条消失**，说明联表是 LEFT JOIN，
>   这是对的——审计日志不该因为账号注销而丢。
> - 顺带发现两处测试残留脏数据（不是功能问题）：角色列表里有一个测试中断留下的 `role_<uniqid>` 角色；
>   操作日志里大量测试产生的记录操作人显示成 `#id`。

> 「供应商管理：商品同步接入 / 余额监控 / 调用日志」（requirements.md 6.3 / 6.7 / 6.8，2026-09-18）：
> - **供应商回调地址**：原来下单传给卡速售的回调地址是写死的占位 `https://platform.example.com/notify/{code}`，生产上供应商回调根本到不了。
>   改成 `{SUPPLIER_NOTIFY_BASE_URL}/notify/{编码}/{令牌}`（商品变更通知加 `/goods`），令牌存 `suppliers.notify_token`（新迁移，已有供应商补生成，
>   新建时 `Supplier::creating()` 生成）。令牌不对和编码不存在一样返回 404，旧的无令牌地址不再有路由。`SUPPLIER_NOTIFY_BASE_URL` 没配时用本机地址
>   （下单照常，回调收不到，只靠定时查询），供应商详情页会提示。**上线要做**：配 `SUPPLIER_NOTIFY_BASE_URL`、跑迁移、把供应商详情页上的商品变更通知地址配到卡速售后台。
> - **余额监控**：详情页显示余额、预警线、查询时间，`POST /admin/suppliers/{id}/balance/refresh` 立即查一次（失败 422，看调用日志）。
>   顺带修了 `Supplier` 模型没把 `balance_synced_at` 转成时间，余额一旦查到过，供应商列表和详情接口就会 500。
> - **商品同步**：`POST /admin/suppliers/{id}/product-sync` 推 `App\Job\SyncSupplierProductsJob` 异步跑一次全量同步（跟每日校准同一套），停用的供应商 422。
> - **调用日志**：驱动每发一次 HTTP 请求都通过构造时注入的回调写 `supplier_call_logs`（动作、请求路径和参数、响应状态和响应体、耗时；不记请求头，
>   密钥不落库），按请求里的 `external_orderno` 关联订单。`GET /admin/suppliers/{id}/call-logs`（action / order_no / created_from / created_to 筛选，最新在前）。
>   写日志失败只记 error 日志不影响下单。收到的供应商回调没记（`supplier_notify_logs` 还没接）；日志表没有清理任务，量大了再定归档。
> - **统计**（2026-09-21）：`GET /admin/suppliers/{id}/stats`（`group_by=day|product`、`created_from`/`created_to` 只收 `YYYY-MM-DD`，
>   不传按最近 7 天，跨度上限 92 天），权限 `supplier.view`。返回 `summary` + `data`（按天分组补齐没有订单的日期），每行给
>   订单量、成功/失败/处理中笔数、成功率、平均到账时长、成本总额、供应商返佣总额。**口径按 `order_attempts` 而不是
>   `orders.supplier_id`**：订单只记最后一次尝试的供应商，A 家失败切到 B 家成功的单在 `orders` 上只看得到 B，A 的失败会消失、
>   成功率虚高，而成功率正是这份统计要用来调优先级的核心指标；成功率分母只算已出结果的（success + failed），处理中/结果未知不计入；
>   平均到账时长 = 该次尝试从占号到写下最终结果的秒数，只算成功的尝试（同步就成功的是 0 秒，秒级精度）；成本总额只累计成功的尝试，
>   用 `orders.cost_price` 快照（成功时写的就是这家的成本）。按商品分组联 `order_recharges`（话费、卡券的商品在这张表），
>   没有商品行的订单归到"未知商品"。**供应商返佣总额**（2026-09-23 接上）：成功的尝试、且订单现在仍成功时，累计电影票/快递明细上的
>   `supplier_rebate`；话费、卡券没有供应商返佣。
>   测试 `test/Cases/Admin/SupplierStatsControllerTest.php`。
> - **卡密打码**：新增 `App\Supplier\CardSecretMasker`，JSON 字符串形式的响应体也会解开打码。之前 `order_attempts` 快照里的原始响应体是 JSON 字符串，
>   后台原来的打码只认数组键，卡号卡密实际是明文落库、明文展示的；现在落库前打码，后台展示时对历史数据再打一次（库里已有的旧快照没有回刷）。
> - 权限：刷新余额、同步商品用 `supplier.manage`，调用日志用 `supplier.view`。测试 `test/Cases/Admin/SupplierMonitorControllerTest.php`、
>   `test/Cases/Supplier/CardSecretMaskerTest.php`、`KasushouDriverTest`（调用日志回调）、`NotifySupplierControllerTest`（令牌）、`SupplierRouterTest`（下单带真实回调地址）。

> 「售后处理：话费卡券争议处理」（requirements.md 7.7）：`GET /admin/disputes`（status / merchant_id 筛选）、
> `GET /admin/disputes/{id}`（含订单和返佣状态）、`POST /admin/disputes/{id}/reject`（确认已到账：`remark` + 必填
> `evidence` 字符串列表，最多 20 项）、`POST /admin/disputes/{id}/confirm`（确认未到账：`remark`，`evidence` 可选）。
> 权限 `aftersale.view` / `aftersale.handle`（已进 `KNOWN_PERMISSIONS`，部署后执行 `admin:sync-permissions`），
> 两个动作都写 `admin_operation_logs`（module = aftersale），只能处理"处理中"的争议（否则 409）。
> - **确认未到账**走新的 `App\Service\Order\OrderRefundService::refundUndelivered()`，跟争议状态在同一个事务里：订单
>   `success → refunded`（条件更新，只退一次）、`refunded_amount` = 已扣款、`BalanceService::refundOrder()` 退回可用余额
>   记 `refund` 流水（带操作人和原因）；返佣待到账的作废（`MerchantRebateDao::voidIfPending()`），已到账的
>   `BalanceService::clawbackRebate()` 从可用余额扣回记 `rebate_clawback` 流水（可能扣成负数，按 4.5 负余额处理）；提交后通知商户。
>   "供应商主动全额退款"也应该走这个服务，检测链路（成功订单的供应商状态变化）还没建。
> - **争议期间返佣暂停到账**：`MerchantRebateDao::findDuePending()` 排除有处理中争议的订单，驳回后恢复。
>   `BalanceService::settleRebate()` 改成锁住返佣行后重新确认仍是 pending 才入账（锁顺序：商户 → 返佣，跟退款/扣回一致），
>   避免结算任务手上的旧数据把已作废的返佣发出去——此前只靠流水去重，拦不住这种情况。
> - **提交卡速售售后**（2026-09-23，requirements.md 7.7「供应商支持售后接口时，客服可直接通过接口提交给供应商并接收处理结果」）：
>   `POST /admin/disputes/{id}/supplier-aftersale`（`content` ≤ 500 字 + 可选 `images` 最多 5 个 http(s) 链接，`aftersale.handle`），
>   争议详情抽屉里「提交卡速售售后」。按订单最新一次尝试的 `订单号-尝试序号` 提交给那家卡速售站点，结果回调地址
>   `/notify/{code}/{token}/aftersale`（逐单传给卡速售，需要配了 `SUPPLIER_NOTIFY_BASE_URL`）。只对处理中的争议；供应商售后处理中时
>   不能重复提交，终止/处理完成后可以补材料再提。卡速售拒绝 422、结果未知（超时、500）502，都不记为已提交。
>   `aftersale_disputes` 加了 `supplier_id`、`supplier_aftersale_no/status/reply/submitted_at/updated_at` 六列，争议列表/详情多一个
>   `supplier_aftersale` 对象。**回调有签名**（同订单回调），验签通过的状态（处理中/处理完成/终止）和说明直接记到争议上，
>   按售后单号认领，没有单号时按 `external_orderno` 反查订单再找提交给这家供应商的争议；验签失败 403、认不出 404。
>   **不自动结案**："处理完成"不说明到账与否，客服看说明后仍在争议里驳回或确认未到账，退款只有 `confirm()` 一个出口；
>   卡速售若因售后把订单退款，走"成功后被供应商退款"链路自动处理。调用日志动作 `submit_aftersale`。
> - 测试：`test/Cases/Admin/DisputeControllerTest.php`、`test/Cases/Service/Order/OrderRefundServiceTest.php`。

> 「快递工单」（requirements.md 7.2、8.3，yunyang.md 第 1、5 节，2026-09-23）：快递售后不对商户开放，客服在后台代提交云洋工单。
> 新建 `express_workorders` 表（database-design.md 4.9，比设计多了 `supplier_id`、`content`、`supplier_reply` /
> `supplier_amount` / `supplier_replied_at`、`resolved_by`、`updated_at`）+ `App\Model\ExpressWorkorder` / `App\Dao\ExpressWorkorderDao`。
> - **提交**：`POST /admin/orders/{id}/workorders`（`type` 七选一 + `content` ≤ 500 字，权限 `aftersale.handle`），订单详情「快递工单」
>   卡片里提交。只接受已有云洋单号、没有失败/取消/退款的快递订单；同一订单同类型已有处理中的工单 409。云洋明确拒绝 422 带原因、
>   **不建工单**；网络失败/解析不了 502、不建工单，提示先去云洋后台确认——工单也没有防重复参数，不能盲目重提。
> - **驱动**：`YunyangDriver::submitWorkOrder()` 走单独路径 `/api/wuliu/submitWorkOrder`（不带 serviceCode），类型换成云洋编号
>   （1 重量核实、2 理赔、6 取消、8/9/10 催取件/物流/派送、11 现结到付），成功码 `"200"`；调用日志动作 `submit_workorder`。
>   **请求字段、响应里的工单号字段、回调字段名都是推断**（文档没有报文样例），集中在驱动常量和两个方法里，联调时对不上只改那里。
> - **回调**：跟订单回调推到同一个账户级地址，`ExpressCallbackService` 先看有没有工单号，有就交给
>   `ExpressWorkorderCallbackService`。**回调没签名、工单也没有可复核的查询接口**：回复和金额只记在工单上（列表显示
>   「云洋回复（未验证）」），**不改状态、不动钱**；顺带做一次带签名的订单查询交给快递结算——重量核实、状态异常退回的运费
>   体现在订单运费里，按费用调整自动退给商户（requirements.md 7.2）。认不出的工单号 404，云洋会重推。
> - **结单**：`GET /admin/express-workorders`（status / type / order_no / replied 筛选，处理中且有回复的在前，带 `processing_count`，
>   `aftersale.view`）、`POST .../{id}/complete`（必填 `result_remark`；理赔工单可填 `claim_amount`，按 `BalanceService::adjust()`
>   调账加到商户可用余额，流水原因带订单号和工单号）、`POST .../{id}/reject`（必填 `result_remark`），都要 `aftersale.handle`、
>   写操作日志（module = aftersale）。带状态条件更新，两个客服同时结单后一个 409，不会调两次账；非理赔工单填金额 422
>   （重量核实退回的运费走费用调整，不能在这里再给一次）。
> - 订单详情接口多一个 `workorders`（快递订单才有内容）。菜单「订单 → 快递工单」。
> - 测试：`test/Cases/Admin/ExpressWorkorderControllerTest.php`（理赔提交 → 重复提交 409 → 详情和列表可见 → 结单调账 → 重复结单 409
>   且不重复调账、云洋拒绝和结果未知都不建工单、非法类型/已失败订单/无权限、驳回和非理赔不能填金额、回调只记录不动钱并触发订单查询）、
>   `YunyangDriverTest`（工单路径和类型编号、成功码、超时算结果未知、工单回调识别）。

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
> - **发起供应商撤单**（2026-09-23，`POST /admin/orders/{id}/cancel-supplier`，`order.resolve`）：只对话费卡券异常单，
>   按最新尝试的 `external_orderno` 调 `KasushouDriver::cancelOrder()`（带订单回调地址收撤单结果）。**只发起、不改订单**：
>   受理不等于撤单成功，客服之后「查询供应商」确认已撤单（状态 4/5 退款），再「人工处理 → 置失败」解冻——异常单的自动推进
>   本来就只记录不改订单。受理结果和卡速售给的原因返回给页面并记操作日志。
> - **成功后被供应商退款**（2026-09-23，requirements.md 7.1 / 7.7，`App\Service\Order\SupplierRefundAfterSuccessService`）：
>   卡速售对已成功订单再推回调时（`SupplierCallbackService` 以前直接忽略），全额退款（状态 4/5 且退款 = 订单金额）自动走
>   `OrderRefundService::refundUndelivered()`（订单已退款、退回商户、返佣作废或扣回），部分退款不动钱；两种都报
>   `supplier_refund_after_success` 告警（挂在订单上）。后台「查询供应商」现在也能查话费卡券的**成功**订单，走同一套判断，
>   返回 `refund_check`（refunded / partial / none）。定时查询只查处理中的订单，不会主动发现这类变化。
> - **部分退款**（2026-09-23，`POST /admin/orders/{id}/partial-refund`，`amount` + `remark`，`order.resolve`）：只对话费卡券的
>   成功订单，金额不能超过「已扣款 − 已退」；订单仍成功、`refunded_amount` 累加、退回商户可用余额（`refund` 流水）、回调商户
>   （回调新增 `refunded_amount` 字段，退过款才带）。退满剩余金额就按全额退款处理。**部分退款不动返佣**——需求没规定，
>   先按"订单仍成功、返佣照常"，需要时客服另外调账（待业务确认）。
>   顺带修了 `refundUndelivered()`：之前部分退过的再全额退款会按已扣款全额再退一次，现在只退剩下的，
>   条件更新也带上读到的已退金额，跟部分退款并发时不会多退。
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
> **config 解不开时详情接口降级**（2026-09-21）：库里有早期测试/调试留下的供应商行，`config` 是用别的
> `APP_ENCRYPTION_KEY` 加密的（轮换密钥后也会出现同样的行），当前密钥解不开。原来 `format()` 无条件
> `decrypt()`，一条坏行让整个详情接口 500——页面上的名称、状态、余额、回调地址全打不开，而运营要做的恰恰是
> 进这个页面把配置重新填一遍，等于被坏数据锁在门外（余额刷新那条路径早就是捕获降级的，只有详情接口没有）。
> 现在走 `SupplierAdminService::readConfig()`：解不开（或解开后不是 JSON 对象）时 `config` 返回 `null`、
> 多返回 `config_unreadable: true`，并记一条 error 日志（`supplier config decrypt failed`，只记 supplier_id
> 和异常消息，不记密文）。前端供应商详情页顶部红色提示「接口配置无法解密，请重新填写」，列表页的编辑弹窗
> 隐藏"当前配置"、强制打开重填开关。测试见 `SupplierControllerTest::testDetailDegradesGracefullyWhenConfigCannotBeDecrypted`
> 等三个用例。
>
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
| 供应商下单异步执行 | 队列 Job | ✅ `App\Job\PlaceSupplierOrderJob` → `App\Service\Order\SupplierOrderDispatcher`，兜底 `App\Crontab\SupplierDispatchRecoveryCrontab`，见下方说明 |
| 供应商结果查询轮询 | Crontab | ✅ `App\Crontab\SupplierResultQueryCrontab` → `App\Service\Order\SupplierResultPollingService`，见下方说明 |
| 商户回调重试（1/5/15/60/120/360 分钟） | 队列 Job（延迟） | ✅ `App\Job\NotifyMerchantJob` 失败后按间隔自己重新入队（第 6 节"结果回调"时已实现，此前本表漏更新） |
| 供应商余额监控 | Crontab | ✅ `App\Crontab\SupplierBalanceCrontab` → `App\Service\Supplier\SupplierBalanceService`，见下方说明 |
| 供应商商品同步（每日全量校准） | Crontab | ✅ `App\Crontab\SupplierProductSyncCrontab` → `App\Service\Supplier\ProductSyncService`，见下方说明 |
| 熔断自动恢复（二期） | Crontab | ✅ `App\Crontab\CircuitBreakerRecoveryCrontab`，每分钟把到期的熔断行写回 `normal`；**不是恢复机制本身**，路由按 `paused_until` 实时判断，见第 5 节「熔断」说明 |
| 城市 / 影院数据批量同步（三期） | Crontab | ✅ `App\Crontab\MovieBaseDataSyncCrontab` → `MovieBaseDataSyncService::syncAll()`，每天 04:30，见第 6 节脚注 ⑧ |
| 场次数据批量同步（三期，视权限） | Crontab | ⬜ 没有批量拉取权限，场次实时转发；权限批下来再建 `movie_showtime_caches` |
| 电影票锁座超时释放（三期） | Crontab | ✅ `App\Crontab\MovieLockExpiryCrontab` → `MovieOrderService::expireLocks()`，每分钟，见第 6 节脚注 ⑧ |
| 返佣到期自动入账 | Crontab | ✅ `App\Crontab\RebateSettlementCrontab`，见第 1 节"返佣待到账生成 + 到期结算"（只做到账，不含作废/扣回） |
| 异常单标记 | Crontab | ✅ `App\Crontab\AbnormalOrderCrontab` → `App\Service\Order\AbnormalOrderService`，见下方说明 |
| 每日订单对账（二期） | Crontab | ✅ `App\Crontab\ReconciliationCrontab` → `App\Service\Reconciliation\ReconciliationService`，每天 05:00 对前一天完成的订单，见第 8 节「对账」说明 |
| 快递完成兜底（三期） | Crontab | ✅ `App\Crontab\ExpressCompletionCrontab` → `ExpressOrderSettlementService::completeOverdue()`，每小时一次，见第 6 节脚注 ⑦ |

> 「供应商下单异步执行」（requirements.md 9「调用供应商下单……走异步队列，不阻塞商户下单请求」，2026-09-23）：
> - 话费、卡券下单请求里只做幂等、校验、建单、冻结，然后 `SupplierOrderDispatcher::enqueue()` 推一个 `PlaceSupplierOrderJob`（只带订单 id），
>   立即返回"处理中"；消费者里 `dispatch()` 读 `order_recharges` 拿商品和充值账号，调 `SupplierRouter::routeNewOrder()`，路由、切换、
>   尝试记录都不变。商户接口文档的下单说明补了一句"接口不等待充值结果，一般返回 processing"。
> - **幂等**：只处理"仍在处理中、一次尝试都没有"的订单；两个消费者同时拿到同一笔时，`order_attempts` 的唯一索引让后到的退出，不会重复下单。
>   Job 不靠队列重试（`maxAttempts = 0`），重试交给兜底任务。
> - **兜底**：入队失败（Redis 不可用）只记日志、不让下单请求报错（钱已冻结、订单已建，报错商户会重下一笔）。`SupplierDispatchRecoveryCrontab`
>   每分钟把处理中、没有任何尝试、建单超过 1 分钟的话费卡券订单重新入队（`OrderDao::listUndispatchedIds()`）。已经被标成异常单的不再自动下单，
>   交给人工。
> - **开关**：`config/autoload/supplier.php` 的 `dispatch_async`（环境变量 `SUPPLIER_DISPATCH_ASYNC`，默认 true，已加进 `.env.example`）。关掉就是
>   改造前的同步路由。`phpunit.xml.dist` 里关掉：已有的下单、路由、返佣用例按同步结果断言，继续覆盖路由本身；异步分派另有用例。
> - **电影票锁座、快递下单不走队列**：锁座结果（锁没锁上、锁到几点）是商户下一步确认出票的前提；快递要把云洋的冻结运费同步算进冻结金额返回给商户，
>   而且云洋没有防重复单号，消费者超时重跑就是重复下单。两者都只调一次供应商、不做多家切换，同步等一次的代价有限。
> - 测试：`test/Cases/Service/Order/SupplierOrderDispatcherTest.php`（入队不路由、入队失败不报错、只路由没分派过的处理中订单、兜底只捡超过 1 分钟且没有尝试的
>   处理中订单、同步模式直接路由且没有兜底、Job 委托给分派服务）；已有的 `RechargeOrderControllerTest`、`CardOrderControllerTest`、
>   `RechargeOrderPlacementServiceTest`、`CardOrderPlacementServiceTest`、`SupplierRouterTest`、`OrderResultApplierRebateTest` 在同步模式下全部通过。

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
| 卡速售 2.0 驱动 | 8 | 8 | 0 | 0 |
| 云洋驱动 | 9 | 9 | 0 | 0 |
| 芒果驱动 | 11 | 11 | 0 | 0 |
| 供应商路由与风控 | 5 | 5 | 0 | 0 |
| 开放 API 接口 | 15 | 15 | 0 | 0 |
| 商户管理后台 | 16 | 16 | 0 | 0 |
| 系统管理后台 | 19 | 19 | 0 | 0 |
| 异步任务与定时任务 | 13 | 12 | 0 | 1 |
| **合计** | **109** | **108** | **0** | **1** |

上表"商户管理后台""系统管理后台"两行只统计后端接口。前端页面单独统计（第 7、8 节"前端页面"列）：

| 前端 | 总数 | 接口已就绪（✅/🔨） | 页面已完成 | 页面待补（接口已就绪） |
|---|---|---|---|---|
| 商户管理后台（web/merchant） | 16 | 16 | 16 | 0 |
| 系统管理后台（web/admin） | 19 | 19 | 19 | 0 |

> **前端进度（2026-09-18）**：已就绪接口的页面全部写完并登录联调过（两个后台逐页走过一遍）。联调时修掉的问题：
> model-cache 用 Redis hash 存储会把 NULL 读成 ''（已改 `RedisStringHandler`）、商户提交的图片链接只接受 http(s)（防管理端 XSS）、
> IP 白名单去重、商户订单详情补充值账号、重推回调后延迟刷新记录。
> 造联调数据：`docker exec pf php bin/hyperf.php dev:mock-orders --merchant=<id>`，给商户造一批各种状态的话费模拟订单
> （余额走真实冻结/扣款，不调用供应商）。共用部分在 `web/shared`：`usePagedList`（分页列表）、`labels.ts`（枚举中文名）、
> `StatusTag`、`format.ts`、`copyText`。系统后台 `/admin/auth/me` 不返回权限列表，前端没按权限隐藏按钮，无权限时靠接口 403 提示。
> 发现的接口缺口：文件上传（充值凭证、证件照片只能填链接）、商户列表没有状态筛选、后台没有商户资金/返佣汇总。

**建议开发顺序**（按 [10. 分期计划](requirements.md#10-分期计划)，前后端同步进行，不等接口全部做完再做页面）：

1. ~~第 1、5（部分）、9（部分）节的基础设施 → 第 2 节卡速售驱动 → 话费下单闭环~~（已基本完成）
2. ~~给已就绪的一期接口补页面并登录联调~~（2026-09-18 已完成）
   - 商户后台：注册、开发设置（密钥 + IP 白名单）、充值申请、资金流水、订单、售后争议
   - 系统后台：商户列表/审核/详情/流水/启停/等级/限流、充值审核与调账、商品库（含等级比例覆盖）、供应商配置、商品映射、商户等级、订单与异常单、争议处理
3. **边做页面边补一期还缺的接口**，接口和页面一起完成：
   - 商户后台：~~找回密码、修改密码、资质提交与审核状态~~（已完成）、~~首页统计、服务开通、商品价格展示~~（已完成）、~~返佣明细~~（已完成）、~~接口文档~~（已完成）
   - 系统后台：~~服务开通审核~~（已完成）、~~系统设置~~（已完成，页面待联调）、~~返佣管理（商户返佣明细）~~（已完成；供应商返佣明细 2026-09-23 已完成）、~~商品同步接入与余额监控、调用日志~~（已完成）、~~供应商统计~~（已完成）
   - ~~商品库/商户等级页面上的 5.5 价格与返佣保护提示（低于成本价、毛利为负、返佣比例超 100%），可以只在前端算~~（已完成，前端计算）
   - ~~商户后台三处导出（资金流水、返佣明细、订单）~~（2026-09-21 已完成）
   - ~~系统后台「系统设置」页面登录联调~~（2026-09-21 已完成）
   - ~~一期只剩：后台订单的部分退款与发起供应商撤单~~（2026-09-23 已完成，**一期到此全部完成**）
4. 二期：~~卡券商品列表~~（2026-09-21 已完成，卡券下单此前已完成，卡券相关行到此结束）→ ~~熔断~~（2026-09-21 已完成）→ ~~告警~~（2026-09-21 已完成，7 类里 3 类已有产生方）→ ~~对账~~（2026-09-21 已完成订单对账；返佣对账 2026-09-23 随电影票完成）→ ~~财务报表~~（2026-09-21 已完成）。**二期到此全部完成**
5. 三期：~~第 3 节云洋驱动层~~（2026-09-21 已完成，Service 接入和工单未做）→ ~~价格设置（加价规则 + 价格预览）~~（2026-09-21 已完成，电影票/快递共用）→ ~~快递查价~~（2026-09-21 已完成）→ ~~快递下单流程（三期建表 + 冻结/结算 + 取消/轨迹）~~（2026-09-23 已完成，见第 6 节脚注 ⑦；两个后台订单详情的快递明细、快递工单未做）→ ~~第 4 节芒果驱动层~~（2026-09-23 已完成，Service 接入未做）→ ~~电影票流程（城市/影院缓存表 + 查询转发加价 + 锁座/确认/释放 + 回调）~~（2026-09-23 已完成，见第 6 节脚注 ⑧）→ ~~两个后台订单详情补快递/电影票明细~~（2026-09-23 已完成）→ ~~供应商返佣明细 + 返佣对账~~（2026-09-23 已完成）→ ~~快递工单~~（2026-09-23 已完成）→ 沙箱环境
