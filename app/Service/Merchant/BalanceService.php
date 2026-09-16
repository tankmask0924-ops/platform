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
 * 只实现 freeze/deduct/unfreeze 三种 type；recharge/supplement_deduct/refund/
 * adjustment/rebate_settle/rebate_clawback 对应充值审核、售后补扣/退款、财务
 * 手动调账、返佣结算/扣回，都是各自独立的、还没建的功能，不在这次任务范围内
 * （merchants.debt_since 只会被 supplement_deduct/rebate_clawback 驱动进负数，
 * 两者都不在本任务，所以这里的代码完全不碰 debt_since）。
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
}
