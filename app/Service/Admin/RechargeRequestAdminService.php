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

namespace App\Service\Admin;

use App\Dao\MerchantRechargeRequestDao;
use App\Model\MerchantRechargeRequest;
use App\Service\AbstractService;
use App\Service\Merchant\BalanceService;
use Carbon\Carbon;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统管理后台（web/admin）「充值与调账 - 充值申请审核」（requirements.md 4.3、8.3），
 * docs/modules.md 第 8 节「充值与调账」这一行的前半部分（手动调账/adjustment 是
 * 独立的另一半，不在本次任务范围）。结构模板取自
 * App\Service\Admin\MerchantAdminService::approve()/reject()（同样的
 * 「先决条件校验 -> Db::transaction() -> 写库」结构、request attribute 里取
 * reviewer id 的方式），但审核这条请求本身的并发防护比商户入驻审核更严格：
 * 那边的 approve()/reject() 只在事务外查一次 status（没有行锁），这里必须在
 * 事务内对 merchant_recharge_requests 这一行本身加行锁 + 重新检查
 * status === 'pending'，原因见下面 approve() 的文档注释。
 *
 * **为什么这一行的并发防护比 MerchantAdminService 更严格**：审核通过会真的
 * 触发 App\Service\Merchant\BalanceService::recharge() 加钱，而 recharge() 本身
 * 明确声明「不做幂等保护，防重复调用是调用方（也就是本类）的责任」（见
 * BalanceService 类注释 + recharge() 方法注释）——如果这里跟 MerchantAdminService
 * 一样只在事务外做一次不加锁的 status 检查，两个并发的 approve 请求会都读到
 * pending、都通过检查、都在各自的事务里把状态改成 approved 并各自调用一次
 * recharge()，商户被实际充值两次。所以本类改为把「锁行 + 重新检查 + 翻转状态」
 * 整体放进同一个事务，且这就是唯一的一次检查（不在事务外再多做一次不加锁的
 * 预检查）——不加锁的预检查在这里只是 TOCTOU 摆设（不能真正拦住并发），
 * 反而会让代码看起来"检查了两次"造成"已经很安全"的错觉，所以干脆不写。
 */
class RechargeRequestAdminService extends AbstractService
{
    private const VALID_STATUSES = ['pending', 'approved', 'rejected'];

    #[Inject]
    protected MerchantRechargeRequestDao $rechargeRequestDao;

    #[Inject]
    protected BalanceService $balanceService;

    /**
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(int $page, int $perPage, mixed $status): array
    {
        $status = $this->normalizeStatusFilter($status);

        $requests = $this->rechargeRequestDao->paginateForAdmin($page, $perPage, $status);

        return [
            'data' => $requests->map(fn (MerchantRechargeRequest $request) => $this->format($request))->values()->all(),
            'total' => $this->rechargeRequestDao->countForAdmin($status),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 审核通过：把申请标记为 approved，并调用 BalanceService::recharge() 给商户
     * 可用余额加钱，两件事在同一个事务里做，要么一起成功要么一起回滚。
     *
     * **事务嵌套**：BalanceService::recharge() 内部自己也开了一个
     * Hyperf\DbConnection\Db::transaction()。这不是问题——Hyperf\Database\
     * Concerns\ManagesTransactions（database 连接层，Db::transaction() 最终落到
     * 这里）对嵌套 transaction() 调用的处理方式是：只有最外层的 beginTransaction()
     * 真正开启一个数据库事务，内层调用因为 $this->transactions > 0 转而创建一个
     * SAVEPOINT；commit() 只有回到最外层（$transactions == 1）时才真正提交，内层
     * commit 只是把计数器减一；rollBack() 同理，内层异常只会回滚到对应的
     * SAVEPOINT（如果被内层自己捕获）或者一路向外传播、最终把最外层也回滚掉
     * （因为异常没被这里捕获，会继续往外抛，外层 transaction() 的 catch 块会
     * 对整个最外层事务做真正的 ROLLBACK）。所以把 recharge() 调用直接写在本方法
     * 自己开的 Db::transaction() 闭包内部，效果就是「申请状态翻转」和「余额变更」
     * 天然处于同一个物理事务里，任何一半失败（比如 recharge() 因商户不存在抛
     * HttpException(404)）都会让另一半的写入也不落地，不需要额外写手动的
     * try/catch/rollback 代码去"模拟"这种原子性。
     *
     * 行锁 + 状态重新检查是这里的唯一一次 pending 校验（见类注释），不在事务外
     * 再做一次不加锁的预检查：先 lockForUpdate() 拿到这条申请行的排他锁（同一时刻
     * 两个并发 approve 请求，后到的那个会在这里排队等锁），行不存在则 404；
     * 拿到锁之后重新读一遍 status——如果已经不是 pending（要么被这次并发竞争
     * 的另一个请求刚刚改过，要么本来就已经被审核过），409 拒绝，此时还没有
     * 调用 recharge()，商户余额分毫未动。只有确认仍是 pending 时才翻转状态 +
     * 调用 recharge()，这条路径上每次 approve() 成功调用最多让 recharge() 执行一次。
     */
    public function approve(int $id, int $reviewerId): void
    {
        Db::transaction(function () use ($id, $reviewerId) {
            $request = $this->lockPendingOrFail($id);

            $request->fill([
                'status' => 'approved',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => Carbon::now(),
            ])->save();

            $reason = '充值申请 #' . $request->id . ' 审核通过';
            if ($request->transfer_no) {
                $reason .= '，转账流水号 ' . $request->transfer_no;
            }

            $this->balanceService->recharge($request->merchant_id, $request->amount, $reason);
        });
    }

    /**
     * 驳回：标记 rejected + 记录原因，不触碰余额。reason 校验放在事务外——
     * 这是纯粹的输入形状校验，不依赖任何需要行锁保护的数据库状态，没必要
     * 挤占一次事务。
     */
    public function reject(int $id, mixed $reason, int $reviewerId): void
    {
        $reason = is_string($reason) ? trim($reason) : '';
        if ($reason === '') {
            throw new HttpException(422, 'reason 不能为空');
        }

        Db::transaction(function () use ($id, $reason, $reviewerId) {
            $request = $this->lockPendingOrFail($id);

            $request->fill([
                'status' => 'rejected',
                'reject_reason' => $reason,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => Carbon::now(),
            ])->save();
        });
    }

    /**
     * approve()/reject() 共用的「锁行 + 校验」：必须已经身处调用方开的
     * Db::transaction() 里（lockForUpdate() 脱离事务毫无意义）。
     */
    private function lockPendingOrFail(int $id): MerchantRechargeRequest
    {
        $request = $this->rechargeRequestDao->lockForUpdate($id);
        if (! $request) {
            throw new HttpException(404, '充值申请不存在');
        }

        if ($request->status !== 'pending') {
            throw new HttpException(409, '只有待审核状态的充值申请才能进行审核操作');
        }

        return $request;
    }

    private function normalizeStatusFilter(mixed $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        if (! is_string($status) || ! in_array($status, self::VALID_STATUSES, true)) {
            throw new HttpException(422, 'status 不合法');
        }

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(MerchantRechargeRequest $request): array
    {
        return [
            'id' => $request->id,
            'merchant_id' => $request->merchant_id,
            'amount' => $request->amount,
            'proof_image' => $request->proof_image,
            'transfer_no' => $request->transfer_no,
            'status' => $request->status,
            'reject_reason' => $request->reject_reason,
            'reviewed_by' => $request->reviewed_by,
            'reviewed_at' => $request->reviewed_at?->toDateTimeString(),
            'created_at' => $request->created_at?->toDateTimeString(),
        ];
    }
}
