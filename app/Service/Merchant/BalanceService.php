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

namespace App\Service\Merchant;

use App\Dao\MerchantBalanceLogDao;
use App\Dao\MerchantDao;
use App\Model\MerchantRebate;
use App\Service\AbstractService;
use Hyperf\Database\Exception\QueryException;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户余额冻结/扣款/解冻（requirements.md 4.4「账户余额」、4.5「负余额」）。
 *
 * 这是订单生命周期需要的三个余额变动原语，本身不做「什么时候该冻结/扣款/解冻」的
 * 编排（选供应商、调驱动、判断订单成败），那是下一个任务（下单流程）的事，这里
 * 只保证「调用了就一定正确、幂等地改余额并记流水」。
 *
 * 实现 freeze/deduct/unfreeze/rebate_settle/recharge/adjustment 六种 type；
 * supplement_deduct/refund/rebate_clawback 对应售后补扣/退款、返佣扣回，
 * 都是各自独立的、还没建的功能，不在这次任务范围内（触发扣回的售后
 * 争议处理、人工改判订单状态都还没建，`merchant_rebates.status` 到
 * `clawed_back` 的转换完全不在本类职责内；merchants.debt_since 的维护/恢复
 * （4.5「可用余额 < 0 时暂停该商户所有下单，充值补足到 ≥ 0 后自动恢复」）
 * 依赖的是下单流程读到负余额时的拦截逻辑和一个"充值后自动清除"的钩子，两者
 * 都还没建，所以即使 adjust() 现在也能把可用余额调成负数，这里仍然不碰
 * debt_since——单独维护这一列而配套的暂停/恢复逻辑都不存在，是一个没有意义
 * 的半成品，等下单流程/负余额处理任务再一起做）。
 *
 * 金额全程用 bcmath 字符串运算，不用 float，跟 App\Service\Product\RebateCalculator
 * 的既有约定一致：decimal(10,2) 列精确到分，float 的二进制小数没法精确表示十进制分，
 * bcmath 是任意精度十进制运算，没有这个问题。
 *
 * 并发安全（4.5 原文）：「冻结是在数据库事务里先锁住商户账户再检查余额，多笔订单
 * 同时到达时排队依次处理，余额不够的那笔直接拒绝」。三个方法都在 Db::transaction()
 * 里用 MerchantDao::lockForUpdate()（SELECT ... FOR UPDATE）锁住商户这一行，
 * 同一商户的并发调用会在这里排队，逐个拿锁、读最新余额、算完再释放，不会有
 * 两笔并发请求读到同一份「变更前」余额、都通过检查、最终把余额冲成负数的竞态。
 *
 * 幂等责任划分（新增 recharge() 后必须看这一段）：merchant_balance_logs 的
 * dedupe_order_key/dedupe_rebate_key 两个生成列只覆盖 deduct/unfreeze/
 * rebate_settle/rebate_clawback 四种 type（见该表迁移里的 CASE 表达式），
 * recharge 不在其中——数据库层面完全没有能拦住「同一笔充值审核被调用两次」的
 * 唯一约束。这不是遗漏：真正需要防止重复的是「同一条 merchant_recharge_requests
 * 审核请求只能被批准一次」，这是一个状态机问题（pending -> approved 单向不可逆），
 * 天然的唯一键是请求行自身的 id + status，不是 order_id/rebate_id 那种能塞进
 * 生成列表达式的外键，套用 deduct/unfreeze 那套「先插日志幂等」的方式在这里文不对题。
 * 所以幂等防护整体挪给调用方 App\Service\Admin\RechargeRequestAdminService::approve()：
 * 在同一个事务里先锁住 merchant_recharge_requests 行、重新检查
 * status === 'pending'、原子地翻成 'approved'，全部成功之后才调用 recharge()——
 * 对同一个已 approved 请求的第二次调用，会在状态检查这一步就被拒绝，根本不会
 * 走到 recharge()。recharge() 本身只管「调用一次，正确地记一次账」，不重复造一套
 * 跟调用方语义重叠的保护，详见 recharge() 方法自身的文档注释。
 *
 * 幂等（4.4「不能重复处理」）：deduct/unfreeze 各自最多处理一笔订单一次，靠的是
 * merchant_balance_logs 表上 `dedupe_order_key` 生成列的唯一索引（deduct/unfreeze
 * 各自生成 "{order_id}-{type}"），这是数据库真实的约束，不是应用层「先查一遍
 * 有没有记录、没有才写」——那种查了再写的写法本身就有 TOCTOU 竞态（两个并发请求
 * 都查到「没有」，都接着往下写）。所以这里反过来利用约束本身：deduct()/unfreeze()
 * 都是「先插日志，插入成功了才动余额列」，插入撞上唯一索引就捕获
 * Hyperf\Database\Exception\QueryException（跟 AuthService::register() 用的
 * 同一个类、同一个「捕获它当成『已存在，幂等跳过』」的既有套路）当成
 * no-op 直接返回——此时因为日志没插成功，后面改余额列的代码根本不会执行到，
 * 不需要额外的回滚逻辑，也不存在「日志插成功了但余额没改」或反过来的中间态。
 */
class BalanceService extends AbstractService
{
    private const SCALE = 2;

    /**
     * adjust() 自己的金额格式校验：跟 App\Service\Merchant\RechargeRequestService::
     * AMOUNT_PATTERN 同一套「整数部分 + 最多两位小数」的精度要求（对齐
     * decimal(10,2) 列），额外允许一个可选的前导负号——调账允许加也允许扣，
     * RechargeRequestService 的充值金额场景不需要负数，这是两者唯一的差异。
     */
    private const ADJUST_AMOUNT_PATTERN = '/^-?\d+(\.\d{1,2})?$/';

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantBalanceLogDao $balanceLogDao;

    /**
     * 冻结：下单时调用。可用余额减少、冻结余额增加。
     *
     * 返回 true/false 而不是抛异常表示「余额不够」：余额不足在下单场景下是一个
     * 正常的业务分支（该拒就拒，不是系统异常），调用方（下单流程）拿到 false
     * 就知道要终止下单、把错误原因呈现给商户，用异常表达这种预期内的业务结果
     * 反而让调用方多一层 try/catch 才能拿到「不是这次调用坏了，是余额真的不够」
     * 这个区分。商户不存在则视为调用方传参错误，抛 HttpException(404)——
     * 这不该发生在合法的调用链路里（订单必然挂在一个存在的商户上）。
     *
     * 余额不够时直接 return false，不写日志、不改余额列，事务里没有任何写操作，
     * 等效于「回滚」（没有变更可回滚），不需要真的抛异常触发 ROLLBACK。
     *
     * 重复调用保护：freeze() 本身没有幂等保护——它不像 deduct/unfreeze 那样
     * 有 dedupe_order_key 生成列兜底（迁移里那条 CASE 表达式只覆盖
     * 'deduct'/'unfreeze'，freeze 不在其中，多条同订单的 freeze 日志能正常插入，
     * 数据库不会挡）。这是有意不加的：
     *   1. 在应用层加「查一下这个 order_id 是否已经 freeze 过」的预检查，
     *      本身就是上面提到的 TOCTOU 查了再写模式，在没有唯一索引兜底的情况下
     *      这个检查是纯摆设，两个并发的重复调用一样能都通过检查、都冻结一次，
     *      反而给人一种「已经保护了」的假象，比不加更危险。
     *   2. freeze 在业务时序上发生在「订单是否存在」之前（下单请求先冻结、
     *      冻结成功才真正创建订单行），所以没有一个此刻已经存在、可以拿来做
     *      唯一约束的订单标识——deduct/unfreeze 能用 order_id 做唯一键，
     *      是因为它们发生在订单已经存在之后。
     *   3. 真正该防的「同一次下单意外触发两次 freeze」，正确的位置是调用方
     *      （下一个任务的下单流程）：下单请求本身应该有幂等键/唯一约束
     *      （例如订单表按商户+客户端传入的幂等键建唯一索引，或者请求级别的幂等
     *      中间件），在调用 freeze() 之前就把「这是不是同一次下单的重复请求」
     *      解决掉，freeze() 只需要保证「调用一次，正确地冻结一次」。
     * 这里把判断写清楚，留给下一个任务在设计下单流程时对齐。
     */
    public function freeze(int $merchantId, int $orderId, string $amount): bool
    {
        return Db::transaction(function () use ($merchantId, $orderId, $amount) {
            $merchant = $this->merchantDao->lockForUpdate($merchantId);
            if (! $merchant) {
                throw new HttpException(404, '商户不存在');
            }

            if (bccomp($merchant->available_balance, $amount, self::SCALE) < 0) {
                return false;
            }

            $availableBefore = $merchant->available_balance;
            $frozenBefore = $merchant->frozen_balance;
            $availableAfter = bcsub($availableBefore, $amount, self::SCALE);
            $frozenAfter = bcadd($frozenBefore, $amount, self::SCALE);

            $merchant->fill([
                'available_balance' => $availableAfter,
                'frozen_balance' => $frozenAfter,
            ])->save();

            $this->balanceLogDao->create([
                'merchant_id' => $merchantId,
                'type' => 'freeze',
                'amount' => $amount,
                'available_before' => $availableBefore,
                'available_after' => $availableAfter,
                'frozen_before' => $frozenBefore,
                'frozen_after' => $frozenAfter,
                'order_id' => $orderId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        });
    }

    /**
     * 扣款：订单成功时调用，把之前冻结的钱正式扣掉。只改冻结余额，可用余额
     * 在冻结那一步已经减过了，这里不再动它——但流水行仍然记录可用余额的
     * 前后快照（本来就没变，before == after），保持「每条流水都有完整的两个
     * 余额快照」这个约定，不因为某次操作只改一边就少记另一边。
     *
     * 幂等靠 merchant_balance_logs 的 dedupe_order_key 唯一索引：先插日志，
     * 插入成功才改余额列；插入因为唯一索引撞了（同一 order_id 已经 deduct
     * 过一次）而抛 QueryException 时，捕获后直接返回，此时余额列还没碰过，
     * 不存在需要撤销的变更。
     */
    public function deduct(int $merchantId, int $orderId, string $amount): void
    {
        Db::transaction(function () use ($merchantId, $orderId, $amount) {
            $merchant = $this->merchantDao->lockForUpdate($merchantId);
            if (! $merchant) {
                throw new HttpException(404, '商户不存在');
            }

            $availableBefore = $merchant->available_balance;
            $availableAfter = $availableBefore;
            $frozenBefore = $merchant->frozen_balance;
            $frozenAfter = bcsub($frozenBefore, $amount, self::SCALE);

            try {
                $this->balanceLogDao->create([
                    'merchant_id' => $merchantId,
                    'type' => 'deduct',
                    'amount' => $amount,
                    'available_before' => $availableBefore,
                    'available_after' => $availableAfter,
                    'frozen_before' => $frozenBefore,
                    'frozen_after' => $frozenAfter,
                    'order_id' => $orderId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (QueryException $e) {
                // dedupe_order_key 唯一索引命中：这个订单已经 deduct 过了，幂等 no-op。
                // 日志没插成功，下面改余额列的代码不会执行到，余额列保持原样。
                return;
            }

            $merchant->fill(['frozen_balance' => $frozenAfter])->save();
        });
    }

    /**
     * 解冻：订单失败/取消时调用，把冻结的钱退回可用余额。结构、幂等处理跟
     * deduct() 完全对称，方向相反：可用余额增加、冻结余额减少。
     */
    public function unfreeze(int $merchantId, int $orderId, string $amount): void
    {
        Db::transaction(function () use ($merchantId, $orderId, $amount) {
            $merchant = $this->merchantDao->lockForUpdate($merchantId);
            if (! $merchant) {
                throw new HttpException(404, '商户不存在');
            }

            $availableBefore = $merchant->available_balance;
            $availableAfter = bcadd($availableBefore, $amount, self::SCALE);
            $frozenBefore = $merchant->frozen_balance;
            $frozenAfter = bcsub($frozenBefore, $amount, self::SCALE);

            try {
                $this->balanceLogDao->create([
                    'merchant_id' => $merchantId,
                    'type' => 'unfreeze',
                    'amount' => $amount,
                    'available_before' => $availableBefore,
                    'available_after' => $availableAfter,
                    'frozen_before' => $frozenBefore,
                    'frozen_after' => $frozenAfter,
                    'order_id' => $orderId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (QueryException $e) {
                // dedupe_order_key 唯一索引命中：这个订单已经 unfreeze 过了，幂等 no-op。
                return;
            }

            $merchant->fill([
                'available_balance' => $availableAfter,
                'frozen_balance' => $frozenAfter,
            ])->save();
        });
    }

    /**
     * 返佣结算：`App\Crontab\RebateSettlementCrontab` 扫到到期的待到账
     * `merchant_rebates` 记录后逐条调用（requirements.md 5.4"入账"）。只加
     * 可用余额，冻结余额不动——返佣从来没有被冻结过，跟 freeze/deduct/unfreeze
     * 三者操作的是"下单时预先冻结的钱"完全是两回事。
     *
     * 接收整个 `MerchantRebate` 模型而不是拆散成 `int $merchantId, int $rebateId, ...`
     * 若干个标量参数（这点跟 freeze/deduct/unfreeze 的方法形状不同）：调用方
     * （crontab）本来就是从 `MerchantRebateDao::findDuePending()` 查出一批
     * `MerchantRebate` 行来处理，本方法同时要读 `merchant_id`/`order_id`/`amount`
     * 三个字段、还要在结算成功后把这一行本身的 `status`/`settled_at` 改掉，接收
     * 整个模型比拆开传参再让调用方额外传一个"结算之后要不要改这行"的回调更省事，
     * 也避免调用方（crontab）自己去写"改 status/settled_at"这段本该属于
     * BalanceService 的逻辑。
     *
     * 幂等：跟 deduct()/unfreeze() 完全同一套"先插日志、插入成功才动余额列"套路，
     * 唯一索引换成了 `merchant_balance_logs.dedupe_rebate_key`
     * （"{rebate_id}-{type}"，只覆盖 rebate_settle/rebate_clawback 两种 type，
     * 见该表迁移注释），插入撞车说明这条 `rebate_id` 已经 rebate_settle 过一次，
     * 捕获 `QueryException` 当幂等 no-op，此时余额列和 `merchant_rebates` 行都不碰。
     * `merchants.available_balance` 的变更和 `merchant_rebates.status` 的变更在
     * 同一个 `Db::transaction()` 里，要么一起提交、要么（比如中途进程被杀）整个
     * 回滚，不会出现"钱到账了但记录还是 pending"或反过来的中间态；同一批到期记录
     * 里每一条各自单独一个事务（调用方 `RebateSettlementCrontab` 逐条循环调用本
     * 方法），某一条结算抛异常不会连累同批次其它记录被回滚。
     *
     * @return bool 这次调用是否真的完成了结算；`false` 表示幂等 no-op（这条
     *              `rebate_id` 之前已经结算过），调用方据此统计"这次真正新结算了
     *              多少条"，不是简单数"调用了多少次没抛异常"
     */
    public function settleRebate(MerchantRebate $rebate): bool
    {
        return Db::transaction(function () use ($rebate) {
            $merchant = $this->merchantDao->lockForUpdate($rebate->merchant_id);
            if (! $merchant) {
                throw new HttpException(404, '商户不存在');
            }

            $availableBefore = $merchant->available_balance;
            $availableAfter = bcadd($availableBefore, $rebate->amount, self::SCALE);
            $frozenBefore = $merchant->frozen_balance;
            $frozenAfter = $frozenBefore;

            try {
                $this->balanceLogDao->create([
                    'merchant_id' => $rebate->merchant_id,
                    'type' => 'rebate_settle',
                    'amount' => $rebate->amount,
                    'available_before' => $availableBefore,
                    'available_after' => $availableAfter,
                    'frozen_before' => $frozenBefore,
                    'frozen_after' => $frozenAfter,
                    'order_id' => $rebate->order_id,
                    'rebate_id' => $rebate->id,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (QueryException $e) {
                // dedupe_rebate_key 唯一索引命中：这条返佣已经结算过了，幂等 no-op。
                // 日志没插成功，下面改余额列/返佣状态的代码不会执行到。
                return false;
            }

            $merchant->fill(['available_balance' => $availableAfter])->save();

            $rebate->fill([
                'status' => 'settled',
                'settled_at' => date('Y-m-d H:i:s'),
            ])->save();

            return true;
        });
    }

    /**
     * 充值：商户线下打款、管理后台审核通过后调用（requirements.md 4.3「充值与调账」）。
     * 只加可用余额，冻结余额不动——充值的钱直接可用，从没经过冻结环节，跟
     * settleRebate() 只加可用余额的理由一致。流水行仍然记录冻结余额的前后快照
     * （本来就没变，before == after），保持「每条流水都有完整的两个余额快照」的约定。
     *
     * **没有幂等保护，这是有意的**：本类顶部文档注释已经把责任划分讲清楚——
     * merchant_balance_logs 没有能防住 recharge 类型重复写入的唯一约束，真正的
     * 防线在调用方 App\Service\Admin\RechargeRequestAdminService::approve()：
     * 它必须先在事务里锁住 merchant_recharge_requests 行、确认 status === 'pending'、
     * 把它原子地翻成 'approved'，全部成功之后才调用这里；对同一条已批准请求的
     * 第二次批准会在那一步的状态检查上被拒绝，永远不会有机会把 recharge() 调用
     * 第二次。这里如果自己再加一层「查一下是否已经充值过」的预检查，反而是
     * TOCTOU 查了再写的假保护（没有唯一索引兜底，两个并发调用一样都能通过检查），
     * 所以刻意不加，把这件事完全交给调用方的状态机保证「只调用一次」。
     *
     * @param null|string $reason 可选备注，落在流水行的 reason 字段（例如带上审核
     *                            请求的 transfer_no/id，方便对账时追溯来源）
     */
    public function recharge(int $merchantId, string $amount, ?string $reason = null): void
    {
        Db::transaction(function () use ($merchantId, $amount, $reason) {
            $merchant = $this->merchantDao->lockForUpdate($merchantId);
            if (! $merchant) {
                throw new HttpException(404, '商户不存在');
            }

            $availableBefore = $merchant->available_balance;
            $availableAfter = bcadd($availableBefore, $amount, self::SCALE);
            $frozenBefore = $merchant->frozen_balance;
            $frozenAfter = $frozenBefore;

            $merchant->fill(['available_balance' => $availableAfter])->save();

            $this->balanceLogDao->create([
                'merchant_id' => $merchantId,
                'type' => 'recharge',
                'amount' => $amount,
                'available_before' => $availableBefore,
                'available_after' => $availableAfter,
                'frozen_before' => $frozenBefore,
                'frozen_after' => $frozenAfter,
                'reason' => $reason,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        });
    }

    /**
     * 手动调账：财务给商户直接加/扣可用余额（requirements.md 4.3「手动调账」），
     * 提交即生效、不需要二次审核——调用方（App\Service\Admin\MerchantAdminService::
     * adjustBalance()）在权限校验通过之后直接调用这里，中间没有另一个「审核」步骤，
     * 跟充值必须先经过 merchant_recharge_requests 的 pending -> approved 状态机
     * 完全不同。只改可用余额，冻结余额不动——手动调账不是订单流程的一环，从没有
     * 经过冻结环节，跟 recharge()/settleRebate() 只加可用余额的理由一致。
     *
     * `$amount` 可正可负（例如 `'10.00'` 表示加、`'-10.00'` 表示扣），格式校验用
     * self::ADJUST_AMOUNT_PATTERN（本方法自己的职责，属于「金额」这个值对象本身
     * 该满足的约束，不是 HTTP 层输入校验）；`$reason`/`$operatorId` 的合法性校验
     * 属于调用方职责（`$reason` 必须非空，比照
     * App\Service\Admin\MerchantAdminService::reject() 校验 reason 的方式，本方法
     * 假定拿到的 `$reason` 已经是非空字符串，不重复校验）。
     *
     * **关于负余额下限——这是本方法跟任务描述文字冲突、以 requirements.md 原文为准
     * 的一处重要判断，写在这里防止以后有人"读了任务描述就来改代码"**：
     * requirements.md 4.5「负余额」一节列出的、会让可用余额变成负数的场景**有三个**：
     * 快递补扣、返佣扣回、**财务手动扣款调账**（原文列表第三项，逐字照抄，没有
     * additional 说明文字——通篇 requirements.md 只有 4.3/4.4/4.5 三处提到"手动调账"，
     * 指的都是同一个功能，"财务手动扣款调账"就是本方法在 `$amount` 为负时的这个
     * 分支，不是另一个没建过的功能）。4.5 处理规则里"补扣、返佣扣回照常执行、
     * 不设下限，必须如实记账"这条虽然字面只点了两个名字，但既然手动扣款调账
     * 本来就在"会让余额变负"的那三个穷举原因之列，不可能对它单独设一个别处
     * 找不到出处的下限——那样会让 4.5 自己举的这个例子变得不可能发生。所以
     * adjust() **不**对扣款方向做「结果会不会小于 0」的下限校验，扣多少就扣多少，
     * 如实记账；真正的负余额后果（暂停下单、欠款预警线）是下单流程/欠款处理
     * 那边的职责，不是这个方法的职责（本方法只保证"调用了就正确地记一次账"，
     * 跟 freeze/deduct/unfreeze 的职责边界划分是同一个道理）。唯一在这里做的校验
     * 是「金额不能是 0」——调账金额是 0 没有任何业务意义，明显是误操作，在真正
     * 碰数据库之前就直接拒绝。
     */
    public function adjust(int $merchantId, string $amount, string $reason, ?int $operatorId): void
    {
        if (! preg_match(self::ADJUST_AMOUNT_PATTERN, $amount)) {
            throw new HttpException(422, 'amount 格式不合法，最多两位小数');
        }

        if (bccomp($amount, '0', self::SCALE) === 0) {
            throw new HttpException(422, 'amount 不能为 0');
        }

        Db::transaction(function () use ($merchantId, $amount, $reason, $operatorId) {
            $merchant = $this->merchantDao->lockForUpdate($merchantId);
            if (! $merchant) {
                throw new HttpException(404, '商户不存在');
            }

            $availableBefore = $merchant->available_balance;
            $availableAfter = bcadd($availableBefore, $amount, self::SCALE);
            $frozenBefore = $merchant->frozen_balance;
            $frozenAfter = $frozenBefore;

            $merchant->fill(['available_balance' => $availableAfter])->save();

            $this->balanceLogDao->create([
                'merchant_id' => $merchantId,
                'type' => 'adjustment',
                'amount' => $amount,
                'available_before' => $availableBefore,
                'available_after' => $availableAfter,
                'frozen_before' => $frozenBefore,
                'frozen_after' => $frozenAfter,
                'reason' => $reason,
                'operator_id' => $operatorId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        });
    }
}
