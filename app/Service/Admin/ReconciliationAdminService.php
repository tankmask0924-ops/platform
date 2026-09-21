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

use App\Dao\AdminUserDao;
use App\Dao\OrderDao;
use App\Dao\ReconciliationDiffDao;
use App\Dao\SupplierDao;
use App\Model\AdminUser;
use App\Model\ReconciliationDiff;
use App\Service\AbstractService;
use App\Service\Reconciliation\ReconciliationService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统管理后台「对账：差异列表 / 标记处理 / 重跑批次」（requirements.md 8.3），
 * docs/modules.md 第 8 节。
 *
 * 差异的产生在 App\Service\Reconciliation\ReconciliationService，这里只读、标记，
 * 以及重新触发一个批次——**后台不能手工新增或删除差异行**：差异是对账跑出来的事实，
 * 人工能做的是"我核对过了/这条不用管"，或者"再对一次"。这跟告警
 * （App\Service\Admin\AlertAdminService）是同一个取舍。
 *
 * `resolved` 和 `ignored` 的区别同样只在语义：前者是差异确实存在并且处理了（比如找
 * 供应商补了差额），后者是核对后判断不需要处理（比如供应商侧延迟同步，第二天自己就对上了）。
 * 备注 `remark` 存的就是这个判断，列表里直接展示，避免下一个人重新查一遍同一笔订单。
 *
 * 重跑是同步执行的（跟供应商商品手动同步一致）：一个批次要逐笔调供应商查询，
 * 请求会挂在那里直到跑完。批次是一天的终态订单，量级跟当天订单数一致；真的大到
 * 撑不住时再改成推队列，不提前为假想的量做异步。
 */
class ReconciliationAdminService extends AbstractService
{
    private const MAX_PER_PAGE = 100;

    /**
     * 允许手动重跑的最早批次：再往前的订单供应商那边多半已经查不到了
     * （卡速售订单详情 day=0 能查全部，但别的供应商未必），而且对账差异本身是
     * 当期要处理的事，翻几个月前的账应该走财务报表而不是重跑对账。
     */
    private const MAX_BACKFILL_DAYS = 90;

    #[Inject]
    protected ReconciliationDiffDao $reconciliationDiffDao;

    #[Inject]
    protected ReconciliationService $reconciliationService;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    /**
     * @param array<string, mixed> $query type / status / field / supplier_id / order_no /
     *                                    date_from / date_to / page / per_page
     * @return array<string, mixed>
     */
    public function list(array $query): array
    {
        $filters = $this->normalizeFilters($query);
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));

        $diffs = $this->reconciliationDiffDao->paginateFiltered($filters, $page, $perPage);

        $orderNos = $this->orderDao->newQuery()
            ->whereIn('id', $diffs->pluck('order_id')->unique()->all())
            ->pluck('order_no', 'id');
        $supplierNames = $this->supplierDao->newQuery()
            ->whereIn('id', $diffs->pluck('supplier_id')->unique()->all())
            ->pluck('name', 'id');
        $operators = $this->adminUserDao->newQuery()
            ->whereIn('id', $diffs->pluck('resolved_by')->filter()->unique()->all())
            ->pluck('real_name', 'id');

        $counts = $this->reconciliationDiffDao->countByStatus();

        return [
            'data' => $diffs->map(static fn (ReconciliationDiff $diff) => [
                'id' => $diff->id,
                'type' => $diff->type,
                'order_id' => $diff->order_id,
                'order_no' => $orderNos[$diff->order_id] ?? null,
                'supplier_id' => $diff->supplier_id,
                'supplier_name' => $supplierNames[$diff->supplier_id] ?? null,
                'reconciliation_date' => $diff->reconciliation_date?->toDateString(),
                'field' => $diff->field,
                'platform_value' => $diff->platform_value,
                'supplier_value' => $diff->supplier_value,
                'diff_amount' => $diff->diff_amount,
                'status' => $diff->status,
                'resolved_by' => $diff->resolved_by !== null ? ($operators[$diff->resolved_by] ?? null) : null,
                'resolved_at' => $diff->resolved_at?->toDateTimeString(),
                'remark' => $diff->remark,
                'created_at' => $diff->created_at?->toDateTimeString(),
            ])->values()->all(),
            'total' => $this->reconciliationDiffDao->countFiltered($filters),
            'page' => $page,
            'per_page' => $perPage,
            // 未处理数给页头用，不受当前筛选影响
            'open_count' => $counts[ReconciliationDiff::STATUS_OPEN] ?? 0,
        ];
    }

    /**
     * 标记为已处理 / 已忽略。只能标记还是 `open` 的差异：两个运营同时点时后一个拿到
     * 409，而不是默默把前一个的处理人和备注覆盖掉。
     *
     * @param array<string, mixed> $query 备注 remark + 标记完要刷新的列表筛选条件
     * @return array<string, mixed>
     */
    public function markHandled(AdminUser $operator, int $id, string $status, array $query): array
    {
        if (! in_array($status, [ReconciliationDiff::STATUS_RESOLVED, ReconciliationDiff::STATUS_IGNORED], true)) {
            throw new HttpException(422, 'status 只能是 resolved 或 ignored');
        }

        $diff = $this->reconciliationDiffDao->find($id);
        if ($diff === null) {
            throw new HttpException(404, '对账差异不存在');
        }
        if ($diff->status !== ReconciliationDiff::STATUS_OPEN) {
            throw new HttpException(409, '这条差异已经被处理过了');
        }

        $remark = $query['remark'] ?? null;
        if ($remark !== null && ! is_string($remark)) {
            throw new HttpException(422, 'remark 不合法');
        }
        $remark = $remark === null || $remark === '' ? null : mb_substr($remark, 0, 255);

        if (! $this->reconciliationDiffDao->resolveIfOpen($id, $status, (int) $operator->id, $remark)) {
            throw new HttpException(409, '这条差异已经被处理过了');
        }

        return $this->list($query);
    }

    /**
     * 重跑一个批次的订单对账，结果整体替换该批次的旧记录（含已标记处理的，
     * 见 ReconciliationDiffDao::replaceBatch）。
     *
     * @param array<string, mixed> $query date 批次日期，默认今天（对的是前一天完成的订单）
     * @return array<string, mixed> 这次对账的汇总
     */
    public function run(array $query): array
    {
        $date = $query['date'] ?? null;
        if ($date === null || $date === '') {
            $date = date('Y-m-d');
        }
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            throw new HttpException(422, 'date 不是合法的日期');
        }
        if ($date > date('Y-m-d')) {
            throw new HttpException(422, '不能对未来的批次对账');
        }
        if ($date < date('Y-m-d', strtotime('-' . self::MAX_BACKFILL_DAYS . ' days'))) {
            throw new HttpException(422, '只能重跑最近 ' . self::MAX_BACKFILL_DAYS . ' 天的批次');
        }

        return $this->reconciliationService->runOrderReconciliation($date);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $query): array
    {
        $filters = [];

        foreach (['type' => ReconciliationDiff::TYPES, 'status' => ReconciliationDiff::STATUSES, 'field' => ReconciliationDiff::FIELDS] as $key => $allowed) {
            $value = $query[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (! in_array($value, $allowed, true)) {
                throw new HttpException(422, $key . ' 不合法');
            }
            $filters[$key] = $value;
        }

        $supplierId = $query['supplier_id'] ?? null;
        if ($supplierId !== null && $supplierId !== '') {
            if (! is_numeric($supplierId)) {
                throw new HttpException(422, 'supplier_id 不合法');
            }
            $filters['supplier_id'] = (int) $supplierId;
        }

        // 按平台订单号筛：差异表里存的是 order_id，先把单号翻成 id；查不到的单号
        // 用 0 占位，保证返回空列表而不是忽略这个条件把整张表倒出来
        $orderNo = $query['order_no'] ?? null;
        if (is_string($orderNo) && $orderNo !== '') {
            $order = $this->orderDao->findByOrderNo($orderNo);
            $filters['order_id'] = $order === null ? 0 : (int) $order->id;
        }

        foreach (['date_from', 'date_to'] as $key) {
            $value = $query[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                throw new HttpException(422, $key . ' 不是合法的日期');
            }
            $filters[$key] = $value;
        }

        return $filters;
    }
}
