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
use App\Dao\ReconciliationDiffDao;
use App\Dao\SupplierDao;
use App\Model\Order;
use App\Model\ReconciliationDiff;
use App\Service\AbstractService;
use App\Supplier\DriverResult;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\Coroutine\Parallel;
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
 * **返佣对账（`type=rebate`）暂时没有对账器**：供应商返佣只有电影票、快递才有
 * （requirements.md 5.4），这两条业务线是三期，平台侧现在没有任何供应商返佣记录可对。
 * 等三期做业务线时在这里加一个 runRebate()，写入的行沿用同一张表和同一套
 * 后台列表/标记处理，不需要动表结构和前端。见 App\Model\ReconciliationDiff 类注释。
 */
class ReconciliationService extends AbstractService
{
    /**
     * 参与对账的订单终态。处理中、异常单不参与，理由见 OrderDao::listFinishedBetween。
     */
    public const TERMINAL_STATUSES = ['success', 'failed', 'cancelled', 'refunded'];

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
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected ReconciliationDiffDao $reconciliationDiffDao;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 跑一个批次的订单对账。整批算完之后一次性替换掉该批次的旧记录，
     * 中途失败不会留下"删了旧的还没写新的"的空批次。
     *
     * @param null|string $batchDate 批次日期 Y-m-d，默认今天；覆盖的是它前一天完成的订单
     * @return array{type: string, reconciliation_date: string, order_date: string,
     *     checked: int, unreachable: int, diff_count: int}
     */
    public function runOrderReconciliation(?string $batchDate = null): array
    {
        $batchDate ??= date('Y-m-d');
        $orderDate = date('Y-m-d', strtotime($batchDate . ' -1 day'));
        $now = date('Y-m-d H:i:s');

        $drivers = [];
        $checked = 0;
        $unreachable = 0;
        $rows = [];
        $afterId = 0;

        while (true) {
            $orders = $this->orderDao->listFinishedBetween(
                self::TERMINAL_STATUSES,
                $orderDate . ' 00:00:00',
                $orderDate . ' 23:59:59',
                $afterId,
                self::BATCH_SIZE
            );
            if ($orders->isEmpty()) {
                break;
            }
            $afterId = (int) $orders->last()->id;
            $this->ensureDrivers($orders->pluck('supplier_id')->unique()->all(), $drivers);

            $parallel = new Parallel(self::CONCURRENCY);
            foreach ($orders as $order) {
                $parallel->add(fn () => $this->compareOne($order, $drivers, $batchDate, $now));
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
            }
        }

        $this->reconciliationDiffDao->replaceBatch(ReconciliationDiff::TYPE_ORDER, $batchDate, $rows);

        $summary = [
            'type' => ReconciliationDiff::TYPE_ORDER,
            'reconciliation_date' => $batchDate,
            'order_date' => $orderDate,
            'checked' => $checked,
            'unreachable' => $unreachable,
            'diff_count' => count($rows),
        ];
        $this->logger()->info('order reconciliation finished', $summary);

        return $summary;
    }

    /**
     * 比一笔订单。返回 `reachable=false` 表示这笔没能拿到供应商记录（不产生差异行）。
     *
     * @param array<int, null|KasushouDriver> $drivers 供应商 id => 驱动（建不起来的是 null）
     * @return array{reachable: bool, rows: list<array<string, mixed>>}
     */
    private function compareOne(Order $order, array $drivers, string $batchDate, string $now): array
    {
        $unreachable = ['reachable' => false, 'rows' => []];

        try {
            $driver = $drivers[(int) $order->supplier_id] ?? null;
            $attempt = $this->orderAttemptDao->findLatestForOrder((int) $order->id);
            if ($driver === null || $attempt === null) {
                // 供应商被删/配置坏了（ensureDrivers 已记过日志），或者订单上挂了
                // supplier_id 却没有尝试记录——都没有可比对的供应商单号
                return $unreachable;
            }

            $result = $driver->queryOrder(
                $order->order_no . '-' . $attempt->attempt_no,
                $order->business_line === 'card'
            );
        } catch (Throwable $e) {
            $this->logger()->error('order reconciliation query failed', [
                'order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'error' => $e->getMessage(),
            ]);

            return $unreachable;
        }

        $supplierStatus = $this->describeSupplierStatus($result);
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

        return ['reachable' => true, 'rows' => $rows];
    }

    /**
     * 供应商侧这笔订单算什么状态，用订单表的同一套词（success/failed/processing/unknown）
     * 表达，方便后台直接把两个值并排显示。返回 null 表示根本没拿到供应商记录。
     */
    private function describeSupplierStatus(DriverResult $result): ?string
    {
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
        ?string $diffAmount = null
    ): array {
        return [
            'type' => ReconciliationDiff::TYPE_ORDER,
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
     * @param list<int> $supplierIds
     * @param array<int, null|KasushouDriver> $drivers
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
                $drivers[$supplierId] = $this->supplierDriverFactory->build($supplier);
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
