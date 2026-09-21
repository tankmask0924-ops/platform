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

namespace App\Dao;

use App\Model\MerchantBalanceLog;
use Hyperf\Database\Model\Collection;

class MerchantBalanceLogDao extends AbstractDao
{
    protected string $model = MerchantBalanceLog::class;

    /**
     * 按订单查资金流水，按时间正序（一笔订单最多两条：freeze + deduct 或
     * freeze + unfreeze，正序方便按发生顺序核对）。目前主要给测试和将来的
     * 订单详情页用，这里先留一个基础查询，不做分页。
     */
    public function findByOrderId(int $orderId): Collection
    {
        return $this->newQuery()
            ->where('order_id', $orderId)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * 资金流水列表用（requirements.md 7.2「支持筛选」），按创建时间倒序——最近发生的
     * 排最前面，是流水这种"看历史记录"场景的自然阅读顺序，跟 findByOrderId() 故意用
     * 正序（核对一笔订单内 freeze/deduct 的先后发生顺序）目的不同，不复用同一个方法。
     * 商户端、管理端共用这一个方法：商户端调用方（App\Service\Merchant\BalanceLogService）
     * 永远只传认证中间件解出的商户自己的 id；管理端调用方
     * （App\Service\Admin\MerchantAdminService）传路径参数里的 {id}，本身就是这个
     * 商户后台管理场景要看的目标，不存在 IDOR 问题——IDOR 防护的责任在调用方
     * 传什么 $merchantId 进来，不在这个方法本身。
     *
     * 加 `id DESC` 当第二排序键，不是只按 `created_at DESC` 单独排：`created_at`
     * 只有秒级精度（`date('Y-m-d H:i:s')` 写入，见 App\Service\Merchant\BalanceService
     * 各方法），同一秒内连续发生的多次余额变动（写测试时几乎必然如此，生产环境
     * 短时间内密集调账/下单也会发生）`created_at` 会相同，MySQL 对这种并列情况
     * 排序顺序未定义，只按它排会导致"最近发生的排最前面"这个承诺在有并列时
     * 失效（曾经在自测时真实复现：同一秒插入的三条记录，只按 created_at 排出来
     * 是插入顺序而不是期望的倒序）。`id` 自增，天然反映真实插入顺序，加上去之后
     * 同秒内的记录也能确定性地按"后发生的排前面"排列。
     */
    public function paginateByMerchantId(int $merchantId, int $page, int $perPage, ?string $type = null): Collection
    {
        $query = $this->newQuery()->where('merchant_id', $merchantId);
        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $perPage)->get();
    }

    public function countByMerchantId(int $merchantId, ?string $type = null): int
    {
        $query = $this->newQuery()->where('merchant_id', $merchantId);
        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query->count();
    }

    /**
     * 财务报表（requirements.md 8.3「财务报表：资金流水」）的资金流水汇总：某段时间内
     * 各类流水的笔数和金额合计，不分页、不返回明细行（明细在商户详情页看）。
     *
     * `adjustment` 的金额是带符号的（加钱为正、扣钱为负，见
     * App\Service\Merchant\BalanceService::adjust()），所以它这一行的合计是**净额**；
     * 其余类型都存正数，方向由类型本身表达。
     *
     * @return list<array{type: string, count: int, amount: string}> 按金额合计倒序
     */
    public function summarizeByType(string $from, string $to, ?int $merchantId = null): array
    {
        $query = $this->newQuery()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('type, count(*) as n, COALESCE(SUM(amount), 0) as amount_total')
            ->groupBy('type')
            ->orderByDesc('amount_total');

        if ($merchantId !== null) {
            $query->where('merchant_id', $merchantId);
        }

        return $query->get()
            ->map(static fn ($row) => [
                'type' => (string) $row->type,
                'count' => (int) $row->n,
                'amount' => (string) $row->amount_total,
            ])
            ->values()
            ->all();
    }
}
