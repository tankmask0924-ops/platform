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

namespace App\Service\Reconciliation;

use App\Dao\OrderAttemptDao;
use App\Dao\OrderDao;
use App\Dao\OrderMovieDao;
use App\Dao\ReconciliationDiffDao;
use App\Dao\SupplierDao;
use App\Model\Order;
use App\Model\ReconciliationDiff;
use App\Service\AbstractService;
use App\Supplier\DriverResult;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\Mango\MangoDriver;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\Coroutine\Parallel;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 订单对账（requirements.md 8.3「对账」，database-design.md 4.15）：拿平台自己的订单
 * 记录去跟供应商侧的订单记录逐笔比对，对不上的写进 `reconciliation_diffs` 给人工处理。
 * 由 App\Crontab\ReconciliationCrontab 每天跑一次，后台也可以手动重跑某个批次
 * （App\Service\Admin\ReconciliationAdminService）。
 *
 * **批次日期 = 跑对账的日期，覆盖的是前一天完成的订单**。`reconciliation_date` 按
 * database-design.md 4.15 的定义存"对账批次所属日期（跑对账任务的日期，不是订单创建
 * 日期）"，所以 09-21 的批次对的是 09-20 完成的订单。手动重跑传的也是批次日期，
 * 覆盖窗口跟着往前推一天，这样"重跑 09-21 这批"永远指同一批订单，
 * 幂等替换（ReconciliationDiffDao::replaceBatch）才有意义。
 *
 * **只对终态订单，按 finished_at 取**：理由见 App\Dao\OrderDao::listFinishedBetween。
 *
 * **拿不到供应商记录不算差异**。供应商侧查询超时、网络错误时驱动返回
 * `UnifiedResult::Unknown`，这是"我们没查到"，不是"两边对不上"，写成差异只会让运营每天
 * 去核对一堆网络抖动。这类只计进 `unreachable` 并记日志，下一天的批次会再对一次。
 * 但**供应商确实答了、只是结果本身不确定**（卡速售的部分退款：状态 4/5 且退款金额
 * 小于订单金额，kasushou.md 第 2 节）要报差异——这正是"成功后被部分退款"这种最该被
 * 人看见的情况。两者都是 Unknown，靠"驱动有没有解析出这笔订单"区分：解析成功时
 * supplierOrderNo / actualCost 至少有一个有值（见 KasushouDriver::mapOrderData），
 * 传输层失败时两者都是 null。判断的是结构，不是 failReason 的文案。
 *
 * **电影票一起对，快递暂不对**（2026-09-23）。电影票按芒果单号查芒果订单详情，比状态、成本，
 * 另外比**供应商返佣**（`type=rebate`，平台 `order_movies.supplier_rebate` vs 芒果 `total_rebate`）：
 * 供应商返佣没有单独的账单接口，查询订单详情给的就是芒果认定的最终值（mango.md 第 3 节），
 * 同一次查询同时产出订单差异和返佣差异，两个批次一起替换。返佣只在两边都成功时比，
 * 芒果没给返佣（`supplierRebate` 为 null）不比；平台没记到而芒果有，就是返佣晚到没接住，
 * 客服在订单详情「查询供应商」即可补上（会顺带生成商户返佣）。
 * 芒果的结果未知一律算没拿到记录：芒果驱动在传输失败时也会带回单号，上面那套"看结构"
 * 的判断对它不成立；芒果也没有"部分退款"这种答了但不确定的状态。
 * 快递不参与：云洋"成功"是扣费完成、之后还有费用调整，`orders.cost_price` 是四项实际费用之和，
 * 而云洋查询给的是总运费，口径还没跟云洋核对过；云洋也没有返佣。硬比只会每天报一批假差异。
 */
class ReconciliationService extends AbstractService
{
    /**
     * 参与对账的订单终态。处理中、异常单不参与，理由见 OrderDao::listFinishedBetween。
     */
    public const TERMINAL_STATUSES = ['success', 'failed', 'cancelled', 'refunded'];

    /**
     * 参与对账的业务线，快递为什么不在里面见类注释。
     */
    public const BUSINESS_LINES = ['recharge', 'card', 'movie'];

    private const BATCH_SIZE = 100;

    /**
     * 同时向供应商发起的查询数，跟 SupplierResultPollingService 取同一个量级
     * （驱动单次请求超时 10 秒）。
     */
    private const CONCURRENCY = 10;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderAttemptDao $orderAttemptDao;

    #[Inject]
    protected OrderMovieDao $orderMovieDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected ReconciliationDiffDao $reconciliationDiffDao;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 跑一个批次的订单对账和返佣对账。整批算完之后一次性替换掉该批次的旧记录（两种类型在同一个事务里），
     * 中途失败不会留下"删了旧的还没写新的"的空批次。
     *
     * @param null|string $batchDate 批次日期 Y-m-d，默认今天；覆盖的是它前一天完成的订单
     * @return array{type: string, reconciliation_date: string, order_date: string,
     *     checked: int, unreachable: int, diff_count: int, rebate_checked: int, rebate_diff_count: int}
     */
    public function runOrderReconciliation(?string $batchDate = null): array
    {
        $batchDate ??= date('Y-m-d');
        $orderDate = date('Y-m-d', strtotime($batchDate . ' -1 day'));
        $now = date('Y-m-d H:i:s');

        $drivers = [];
        $checked = 0;
        $unreachable = 0;
        $rebateChecked = 0;
        $rows = [];
        $rebateRows = [];
        $afterId = 0;

        while (true) {
            $orders = $this->orderDao->listFinishedBetween(
                self::TERMINAL_STATUSES,
                $orderDate . ' 00:00:00',
                $orderDate . ' 23:59:59',
                $afterId,
                self::BATCH_SIZE,
                self::BUSINESS_LINES
            );
            if ($orders->isEmpty()) {
                break;
            }
            $afterId = (int) $orders->last()->id;
            $this->ensureDrivers($orders->pluck('supplier_id')->unique()->all(), $drivers);
            $platformRebates = $this->platformMovieRebates($orders);

            $parallel = new Parallel(self::CONCURRENCY);
            foreach ($orders as $order) {
                $parallel->add(fn () => $this->compareOne($order, $drivers, $batchDate, $now, $platformRebates));
            }

            foreach ($parallel->wait(false) as $outcome) {
                if ($outcome === null || $outcome['reachable'] === false) {
                    ++$unreachable;

                    continue;
                }
                ++$checked;
                foreach ($outcome['rows'] as $row) {
                    $rows[] = $row;
                }
                if ($outcome['rebate_checked'] ?? false) {
                    ++$rebateChecked;
                }
                foreach ($outcome['rebate_rows'] ?? [] as $row) {
                    $rebateRows[] = $row;
                }
            }
        }

        Db::transaction(function () use ($batchDate, $rows, $rebateRows) {
            $this->reconciliationDiffDao->replaceBatch(ReconciliationDiff::TYPE_ORDER, $batchDate, $rows);
            $this->reconciliationDiffDao->replaceBatch(ReconciliationDiff::TYPE_REBATE, $batchDate, $rebateRows);
        });

        $summary = [
            'type' => ReconciliationDiff::TYPE_ORDER,
            'reconciliation_date' => $batchDate,
            'order_date' => $orderDate,
            'checked' => $checked,
            'unreachable' => $unreachable,
            'diff_count' => count($rows),
            'rebate_checked' => $rebateChecked,
            'rebate_diff_count' => count($rebateRows),
        ];
        $this->logger()->info('order reconciliation finished', $summary);

        return $summary;
    }

    /**
     * 比一笔订单。返回 `reachable=false` 表示这笔没能拿到供应商记录（不产生差异行）。
     *
     * @param array<int, null|KasushouDriver|MangoDriver> $drivers 供应商 id => 驱动（建不起来的是 null）
     * @param array<int, null|string> $platformRebates 电影票订单 id => 平台记录的供应商返佣
     * @return array{reachable: bool, rows: list<array<string, mixed>>, rebate_checked?: bool,
     *     rebate_rows?: list<array<string, mixed>>}
     */
    private function compareOne(Order $order, array $drivers, string $batchDate, string $now, array $platformRebates): array
    {
        $unreachable = ['reachable' => false, 'rows' => []];

        try {
            $result = $this->querySupplier($order, $drivers[(int) $order->supplier_id] ?? null);
        } catch (Throwable $e) {
            $this->logger()->error('order reconciliation query failed', [
                'order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'error' => $e->getMessage(),
            ]);

            return $unreachable;
        }
        if ($result === null) {
            return $unreachable;
        }

        $supplierStatus = $this->describeSupplierStatus($order, $result);
        if ($supplierStatus === null) {
            return $unreachable;
        }

        $rows = [];
        if ($supplierStatus !== $this->expectedSupplierStatus($order->status)) {
            $rows[] = $this->row($order, $batchDate, $now, ReconciliationDiff::FIELD_STATUS, $order->status, $supplierStatus);
        }

        $costDiff = $this->compareCost($order, $result, $supplierStatus);
        if ($costDiff !== null) {
            $rows[] = $this->row(
                $order,
                $batchDate,
                $now,
                ReconciliationDiff::FIELD_COST_PRICE,
                (string) $order->cost_price,
                (string) $result->actualCost,
                $costDiff
            );
        }

        $outcome = ['reachable' => true, 'rows' => $rows, 'rebate_checked' => false, 'rebate_rows' => []];
        if ($order->business_line === 'movie' && $order->status === 'success' && $supplierStatus === 'success'
            && $result->supplierRebate !== null && is_numeric($result->supplierRebate)) {
            $outcome['rebate_checked'] = true;
            $platformRebate = $this->money($platformRebates[(int) $order->id] ?? null);
            $rebateDiff = bcsub($platformRebate, $result->supplierRebate, 2);
            if (bccomp($rebateDiff, '0', 2) !== 0) {
                $outcome['rebate_rows'][] = $this->row(
                    $order,
                    $batchDate,
                    $now,
                    ReconciliationDiff::FIELD_REBATE_AMOUNT,
                    $platformRebate,
                    $this->money($result->supplierRebate),
                    $rebateDiff,
                    ReconciliationDiff::TYPE_REBATE
                );
            }
        }

        return $outcome;
    }

    /**
     * 按业务线查供应商侧的这笔订单。话费、卡券按 `平台订单号-尝试序号` 查卡速售，电影票按芒果单号查芒果。
     * 返回 null 表示没有可以拿去查的单号（驱动建不起来、没有尝试记录、没拿到芒果单号）。
     *
     * @param null|KasushouDriver|MangoDriver $driver
     */
    private function querySupplier(Order $order, mixed $driver): ?DriverResult
    {
        if ($order->business_line === 'movie') {
            if (! $driver instanceof MangoDriver || $order->supplier_order_no === null || $order->supplier_order_no === '') {
                return null;
            }

            return $driver->queryOrder($order->supplier_order_no);
        }

        $attempt = $this->orderAttemptDao->findLatestForOrder((int) $order->id);
        if (! $driver instanceof KasushouDriver || $attempt === null) {
            // 供应商被删/配置坏了（ensureDrivers 已记过日志），或者订单上挂了
            // supplier_id 却没有尝试记录——都没有可比对的供应商单号
            return null;
        }

        return $driver->queryOrder($order->order_no . '-' . $attempt->attempt_no, $order->business_line === 'card');
    }

    /**
     * 这一页里电影票订单平台记下的供应商返佣，一次查完，不在并发的每笔比对里各查一次。
     *
     * @param iterable<Order> $orders
     * @return array<int, null|string>
     */
    private function platformMovieRebates(iterable $orders): array
    {
        $ids = [];
        foreach ($orders as $order) {
            if ($order->business_line === 'movie' && $order->status === 'success') {
                $ids[] = (int) $order->id;
            }
        }
        if ($ids === []) {
            return [];
        }

        return $this->orderMovieDao->newQuery()->whereIn('order_id', $ids)->pluck('supplier_rebate', 'order_id')
            ->map(static fn ($value) => $value === null ? null : (string) $value)
            ->all();
    }

    private function money(mixed $value): string
    {
        return bcadd($value === null || ! is_numeric($value) ? '0' : (string) $value, '0', 2);
    }

    /**
     * 供应商侧这笔订单算什么状态，用订单表的同一套词（success/failed/processing/unknown）
     * 表达，方便后台直接把两个值并排显示。返回 null 表示根本没拿到供应商记录。
     */
    private function describeSupplierStatus(Order $order, DriverResult $result): ?string
    {
        if ($order->business_line === 'movie' && $result->result === UnifiedResult::Unknown) {
            // 芒果传输失败也带回单号，不能按结构判断；芒果也没有"答了但不确定"的状态，见类注释
            return null;
        }

        return match ($result->result) {
            UnifiedResult::Success => 'success',
            UnifiedResult::DefiniteFailure => 'failed',
            UnifiedResult::Processing => 'processing',
            // Unknown：供应商答了（部分退款这类）才报差异，传输层失败不报，见类注释
            UnifiedResult::Unknown => ($result->supplierOrderNo !== null || $result->actualCost !== null) ? 'unknown' : null,
        };
    }

    /**
     * 平台这个终态，期望供应商侧是什么状态。已退款、已取消在供应商侧都应该表现为
     * 失败（取消/已退款，kasushou.md 第 2 节把 4/5 都映射成明确失败）。
     */
    private function expectedSupplierStatus(string $orderStatus): string
    {
        return $orderStatus === 'success' ? 'success' : 'failed';
    }

    /**
     * 成本金额只在两边都认为成功时才比：一边失败时供应商返回的金额要么是 0、
     * 要么是退款前的原值，跟平台成本对不上是必然的，报出来只会重复 status 那条差异。
     *
     * @return null|string 差额（平台 - 供应商），两边一致或供应商没给金额时返回 null
     */
    private function compareCost(Order $order, DriverResult $result, string $supplierStatus): ?string
    {
        if ($order->status !== 'success' || $supplierStatus !== 'success' || $result->actualCost === null) {
            return null;
        }
        if (! is_numeric($result->actualCost)) {
            return null;
        }

        $diff = bcsub((string) $order->cost_price, $result->actualCost, 2);

        return bccomp($diff, '0', 2) === 0 ? null : $diff;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        Order $order,
        string $batchDate,
        string $now,
        string $field,
        string $platformValue,
        string $supplierValue,
        ?string $diffAmount = null,
        string $type = ReconciliationDiff::TYPE_ORDER
    ): array {
        return [
            'type' => $type,
            'order_id' => (int) $order->id,
            'supplier_id' => (int) $order->supplier_id,
            'reconciliation_date' => $batchDate,
            'field' => $field,
            'platform_value' => mb_substr($platformValue, 0, 64),
            'supplier_value' => mb_substr($supplierValue, 0, 64),
            'diff_amount' => $diffAmount,
            'status' => ReconciliationDiff::STATUS_OPEN,
            'created_at' => $now,
        ];
    }

    /**
     * 把这一批订单用到的供应商驱动建好，缓存在 `$drivers` 里：一批订单里同一个供应商
     * 会出现很多次，每笔都解密一遍配置、建一次驱动纯属浪费。
     *
     * 只建**这批订单真的用到的**供应商，不是把表里所有供应商都建一遍：配置坏掉的历史
     * 供应商（解密失败）会每天在日志里报一次错，而它名下根本没有订单要对。
     *
     * 建失败的用 `null` 占位，避免同一个坏配置在后面的分页里反复解密、反复记日志；
     * 它名下的订单这次对不了（计入 unreachable），不影响别的供应商。在并发查询**之前**
     * 串行建好，两个协程不会同时去建同一个驱动。
     *
     * 按供应商的驱动类型建：卡速售（话费、卡券）、芒果（电影票）。
     *
     * @param list<int> $supplierIds
     * @param array<int, null|KasushouDriver|MangoDriver> $drivers
     */
    private function ensureDrivers(array $supplierIds, array &$drivers): void
    {
        foreach ($supplierIds as $supplierId) {
            $supplierId = (int) $supplierId;
            if (array_key_exists($supplierId, $drivers)) {
                continue;
            }

            $supplier = $this->supplierDao->find($supplierId);
            $drivers[$supplierId] = null;
            if ($supplier === null) {
                continue;
            }

            try {
                $drivers[$supplierId] = $supplier->driver === 'mango'
                    ? $this->supplierDriverFactory->buildMango($supplier)
                    : $this->supplierDriverFactory->build($supplier);
            } catch (Throwable $e) {
                $this->logger()->error('order reconciliation cannot build supplier driver', [
                    'supplier_id' => $supplierId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function logger(): LoggerInterface
    {
        return $this->loggerFactory->get('reconciliation');
    }
}
