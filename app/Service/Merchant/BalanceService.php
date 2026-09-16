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
use App\Dao\SystemSettingDao;
use App\Model\Merchant;
use App\Model\MerchantRebate;
use App\Service\AbstractService;
use Carbon\Carbon;
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
 * `clawed_back` 的转换完全不在本类职责内）。
 *
 * `merchants.debt_since` 的维护（4.5「可用余额 < 0 时暂停该商户所有下单，充值
 * 补足到 ≥ 0 后自动恢复」）：六个方法最终都改余额列，统一收口到私有方法
 * persistBalance()（见其方法文档），由它比较"这次写入前"的可用余额和"这次写入后"
 * 的可用余额，只在真正跨越 0 这条线时才动 debt_since——从 ≥0 变成 <0 记下
 * 起始时间，从 <0 变回 ≥0 清空，停留在同一侧（含继续更负）不碰这一列。
 *
 * 但 debt_since 只是一个派生缓存（给"欠款从什么时候开始"这个展示/预警用途），
 * 它的正确性依赖"每一个改余额的写入路径都记得同步它"这个约定——本类内部能保证
 * （统一收口到 persistBalance()），但不该让下单流程这种外部调用方也依赖这个约定
 * 才能正确判断"现在是否该暂停下单"。所以真正的暂停闸门是 isSuspended()：直接对
 * `available_balance` 做一次新鲜的 bccomp，不读 debt_since，永远不可能过期。
 * 当前唯一的调用方是 App\Service\Order\RechargeOrderPlacementService::place()，
 * 未来卡券/电影票/快递下单流程也应该调这同一个方法，不要各自重新实现判断逻辑。
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

    /**
     * requirements.md 4.5「欠款预警线」的 system_settings key：欠款（可用余额为负时
     * 的绝对值）超过这个金额就该告警财务——真正的告警通道（邮件/短信/IM）这个代码库
     * 完全没有，不在本类职责内，这里只提供"超没超线"这个判断本身，供将来告警功能
     * 直接调用，不用等那个功能落地时才回来重新定义"超线"是什么意思。
     */
    private const DEBT_WARNING_THRESHOLD_SETTING_KEY = 'debt_warning_threshold';

    /**
     * 后台一行没配置时的代码级默认值，跟 OrderResultApplier::
     * DEFAULT_REBATE_DUE_PERIOD_DAYS 同一个"零配置也要能正确运行"的既有惯例。
     * requirements.md 没给具体数字，这里选 1000.00 元：数额小到几十上百元的欠款
     * 大概率是补扣/扣回的正常业务波动，不值得惊动财务；四位数以上通常意味着
     * 已经积累了不止一笔，值得人工介入。纯粹是一个保守的起点，真实数值应该由
     * 运营在后台按实际坏账规模调整，不是本类能替业务方决定的事。
     */
    private const DEFAULT_DEBT_WARNING_THRESHOLD = '1000.00';

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantBalanceLogDao $balanceLogDao;

    #[Inject]
    protected SystemSettingDao $systemSettingDao;

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

            $this->persistBalance($merchant, $availableAfter, $frozenAfter);

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

            $this->persistBalance($merchant, $availableAfter, $frozenAfter);
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

            $this->persistBalance($merchant, $availableAfter, $frozenAfter);
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

            $this->persistBalance($merchant, $availableAfter, $frozenAfter);

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

            $this->persistBalance($merchant, $availableAfter, $frozenAfter);

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

            $this->persistBalance($merchant, $availableAfter, $frozenAfter);

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

    /**
     * requirements.md 4.5「欠款预警线」的判断本身（"超没超线"），不包含任何真正的
     * 告警动作——这个代码库没有邮件/短信/IM 之类能通知内部财务人员的通道，"告警
     * 财务、商户后台醒目提示"两侧都还没建，属于另一个未来任务。这里先把"给定一个
     * 商户，它现在的欠款是不是超过了配置的预警线"这个可复用的判断露出来，供那个
     * 未来任务直接调用，不用等它落地时才回来定义"超线"是什么意思。
     *
     * 非欠款状态（`available_balance ≥ 0`）一律返回 `false`——没有欠款就无所谓
     * "超没超线"。
     */
    public function isOverDebtWarningThreshold(Merchant $merchant): bool
    {
        if (bccomp($merchant->available_balance, '0', self::SCALE) >= 0) {
            return false;
        }

        $debtAmount = bcmul($merchant->available_balance, '-1', self::SCALE);
        $threshold = (string) $this->systemSettingDao->getValue(
            self::DEBT_WARNING_THRESHOLD_SETTING_KEY,
            self::DEFAULT_DEBT_WARNING_THRESHOLD
        );

        return bccomp($debtAmount, $threshold, self::SCALE) > 0;
    }

    /**
     * requirements.md 4.5「负余额」暂停下单判断本身的唯一真实来源，供任意下单流程
     * 复用（见类注释「暂停下单的读取端」一段）：直接对 `available_balance` 做一次
     * 新鲜的 `bccomp`，不读 `debt_since`——后者是 persistBalance() 维护的派生缓存，
     * 正确与否依赖每一条改余额路径都记得同步它，`available_balance` 本身才是权威
     * 数据列，永远不需要担心过期或跟自己不一致。
     */
    public function isSuspended(Merchant $merchant): bool
    {
        return bccomp($merchant->available_balance, '0', self::SCALE) < 0;
    }

    /**
     * 六个余额变动方法（freeze/deduct/unfreeze/settleRebate/recharge/adjust）唯一
     * 真正写 `available_balance`/`frozen_balance` 列的地方，也是 `debt_since`
     * （requirements.md 4.5「负余额」）唯一的写入点。
     *
     * `debt_since` 只在**跨越 0 这条线**时才动：
     * - 写入前 `available_balance ≥ 0`、写入后 `$newAvailable < 0`：刚刚进入欠款，
     *   记下起始时间。
     * - 写入前 `< 0`、写入后 `$newAvailable ≥ 0`：欠款结清，清空。
     * - 停留在同一侧（含继续变得更负，比如已经欠款时又被扣了一笔补扣/扣回/调账）：
     *   完全不碰这一列——不重置一个已经存在的欠款起始时间，也不会因为"仍然 ≥0"
     *   就把一个本来是 null 的值再 set 成 null（`fill()` 只在真的要变的时候才把
     *   `debt_since` 塞进数组，两种"不需要变"的情况都不出现在 `$fill` 里）。
     *
     * 用 `Carbon::now()` 而不是这个类其它地方一律用的 `date('Y-m-d H:i:s')`：
     * 后者是 PHP 原生函数，不受 `Carbon::setTestNow()` 影响，没法在测试里精确控制
     * "先进入欠款"和"欠款期间再扣一次"这两次调用之间的时间差异（真实调用间隔可能
     * 落在同一秒内，光靠墙钟时间不能可靠区分"没重置"和"两次调用刚好在同一秒重置
     * 了"）；`Carbon::now()` 会读 `Carbon::setTestNow()` 设置的假时钟，测试能精确
     * 摆出"这两次调用之间已经过了 N 分钟"这个场景，断言时间戳真的原样未变，而不是
     * 恰好没来得及变。
     *
     * 三个余额列在同一个 `save()` 调用里一起落盘（`fill()` 攒齐了再统一 `save()`
     * 一次，不是三次独立 UPDATE），跟调用方（六个方法）都已经在 `Db::transaction()`
     * 里锁了商户行的前提配合，不需要这里再单独开事务。
     */
    private function persistBalance(Merchant $merchant, string $newAvailable, string $newFrozen): void
    {
        $wasNegative = bccomp($merchant->available_balance, '0', self::SCALE) < 0;
        $willBeNegative = bccomp($newAvailable, '0', self::SCALE) < 0;

        $fill = [
            'available_balance' => $newAvailable,
            'frozen_balance' => $newFrozen,
        ];

        if (! $wasNegative && $willBeNegative) {
            $fill['debt_since'] = Carbon::now()->format('Y-m-d H:i:s');
        } elseif ($wasNegative && ! $willBeNegative) {
            $fill['debt_since'] = null;
        }

        $merchant->fill($fill)->save();
    }
}
