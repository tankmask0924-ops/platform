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

namespace App\Service\Supplier;

use App\Dao\OrderAttemptDao;
use App\Dao\SupplierCircuitBreakerDao;
use App\Dao\SystemSettingDao;
use App\Model\SupplierCircuitBreaker;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 供应商熔断（requirements.md 6.6）。
 *
 * 判定：近 `circuit_breaker_window_minutes` 分钟内，分给某供应商的尝试里已出结果的
 * 达到 `circuit_breaker_min_orders` 次、且失败率超过 `circuit_breaker_fail_rate_percent`，
 * 就暂停给它分配新订单 `circuit_breaker_pause_minutes` 分钟。四个阈值都在系统参数里配
 * （后台「系统设置 - 系统参数」）。订单数下限就是需求里说的"避免订单少时一两单失败
 * 就误暂停"。
 *
 * **两个作用域都判**（requirements.md 6.6「熔断可以精确到供应商 + 商品」）：
 * 先判"这家供应商的这个商品"，再判"整个供应商"。先判商品是有意的——一个商品出问题
 * 时应该只切掉那个商品，不牵连这家供应商的其他商品；只有整家的失败率也超标，才整家切。
 * 两个作用域用同一组阈值，没有再拆一套"商品级阈值"：需求只给了一组值，多一组配置项
 * 就是多一处要运营去理解和调的东西。
 *
 * **判定在下单链路里同步做，不进队列**：判定只有一条聚合查询 + 可能一次 upsert，
 * 比一次供应商 HTTP 调用便宜得多；而放进队列意味着"已经知道这家在连续失败了，但还要
 * 再放几笔订单过去"，正好抵消了熔断的意义。整个判定包在 try/catch 里，**判定本身
 * 出错绝不能影响订单**：熔断是保护机制，它坏了最多是没保护到，不能反过来把正常订单搞挂。
 *
 * **到期恢复不依赖定时任务**：`isPaused()` 直接看 `paused_until` 是否已过
 * （`SupplierCircuitBreaker::isPausedNow()`），所以暂停 5 分钟就是 5 分钟，不会因为
 * 定时任务一分钟一跑而多停几十秒。定时任务（App\Crontab\CircuitBreakerRecoveryCrontab）
 * 只做把过期行写回 `normal` 的收尾，让后台列表和日志如实反映状态。
 *
 * **告警未接**：需求要求熔断时告警，但 `alerts` 表和告警模块同属二期、还没建
 * （docs/modules.md 第 8 节「告警」行）。这里先记 error 日志（`supplier` 渠道），
 * 告警模块落地时把 `alert()` 里的 TODO 换成写 `alerts` 行即可，调用点不用动。
 */
class CircuitBreakerService extends AbstractService
{
    public const WINDOW_MINUTES_SETTING_KEY = 'circuit_breaker_window_minutes';

    public const MIN_ORDERS_SETTING_KEY = 'circuit_breaker_min_orders';

    /**
     * 阈值用「百分比」存（50 = 50%），不是 database-design.md 4.10 列的小数 0.5000。
     * 刻意的取舍：系统参数只有 int / money（两位小数）两种类型和一套范围校验，百分比
     * 正好能用 money 那一套（`50.00`，单位 `%`），不用为一个参数新增一种类型、连带改
     * 后台的参数编辑页；而且运营在界面上输入和读到的就是"50%"，不需要心算 0.5000。
     * key 名带上 `_percent` 就是为了让每个读取点都不可能把单位搞错。
     */
    public const FAIL_RATE_PERCENT_SETTING_KEY = 'circuit_breaker_fail_rate_percent';

    public const PAUSE_MINUTES_SETTING_KEY = 'circuit_breaker_pause_minutes';

    public const DEFAULT_WINDOW_MINUTES = 10;

    public const DEFAULT_MIN_ORDERS = 20;

    public const DEFAULT_FAIL_RATE_PERCENT = '50.00';

    public const DEFAULT_PAUSE_MINUTES = 5;

    /** 人工暂停/恢复时记在 triggered_reason 上的前缀，跟自动熔断区分开 */
    public const MANUAL_REASON_PREFIX = '人工暂停：';

    #[Inject]
    protected SupplierCircuitBreakerDao $breakerDao;

    #[Inject]
    protected OrderAttemptDao $orderAttemptDao;

    #[Inject]
    protected SystemSettingDao $systemSettingDao;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 路由筛选用：这家供应商（可选：这家供应商的这个商品）此刻是不是被熔断了。
     * 整个供应商被熔断时，它的每个商品都算被熔断。
     */
    public function isPaused(int $supplierId, ?int $productId = null): bool
    {
        foreach ($this->breakerDao->listPausedForSupplier($supplierId) as $row) {
            if ($row->product_id !== SupplierCircuitBreaker::PRODUCT_ID_ALL
                && $row->product_id !== $productId) {
                continue;
            }
            if ($row->isPausedNow()) {
                return true;
            }
        }

        return false;
    }

    /**
     * 一次尝试拿到明确失败之后调用：重新算这家供应商（和这个商品）的近期失败率，
     * 超标就熔断。已经在熔断中的作用域不重复判定。
     *
     * 判定失败只记日志不抛，见类注释。
     */
    public function evaluate(int $supplierId, ?int $productId): void
    {
        try {
            if ($productId !== null) {
                $this->evaluateScope($supplierId, $productId);
            }
            $this->evaluateScope($supplierId, SupplierCircuitBreaker::PRODUCT_ID_ALL);
        } catch (Throwable $e) {
            $this->logger()->error('circuit breaker evaluation failed', [
                'supplier_id' => $supplierId,
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 运营手动暂停（requirements.md 6.6「运营也可以手动暂停/恢复」）。
     * `$minutes` 为 null 表示无限期，只有人工恢复才解除。
     */
    public function pauseManually(int $supplierId, int $productId, ?int $minutes, string $remark): SupplierCircuitBreaker
    {
        $pausedUntil = $minutes === null ? null : date('Y-m-d H:i:s', time() + $minutes * 60);

        return $this->breakerDao->upsertStatus(
            $supplierId,
            $productId,
            SupplierCircuitBreaker::STATUS_PAUSED,
            $pausedUntil,
            self::MANUAL_REASON_PREFIX . $remark
        );
    }

    /**
     * 运营手动恢复。对自动熔断和手动暂停一视同仁——运营确认供应商已经恢复了，就该能
     * 立刻放行，不必等暂停期满。
     */
    public function resumeManually(int $supplierId, int $productId): SupplierCircuitBreaker
    {
        return $this->breakerDao->upsertStatus(
            $supplierId,
            $productId,
            SupplierCircuitBreaker::STATUS_NORMAL,
            null,
            null
        );
    }

    /**
     * 定时任务收尾：把到期的熔断行写回 `normal`。恢复本身由 `isPausedNow()` 按时间
     * 判断，这里只是让库里的状态和日志跟上，见类注释。
     *
     * @return int 实际写回的行数
     */
    public function resumeExpired(): int
    {
        $now = date('Y-m-d H:i:s');
        $resumed = 0;

        foreach ($this->breakerDao->listExpired($now) as $row) {
            if (! $this->breakerDao->resumeIfExpired((int) $row->id, $now)) {
                // 这一行刚被人改过（比如同时到达的手动暂停），跳过，下一轮再看
                continue;
            }
            ++$resumed;
            $this->logger()->info('circuit breaker auto resumed', [
                'supplier_id' => $row->supplier_id,
                'product_id' => $row->product_id,
                'paused_until' => $row->paused_until?->toDateTimeString(),
            ]);
        }

        return $resumed;
    }

    /**
     * @return array{window_minutes: int, min_orders: int, fail_rate_percent: string, pause_minutes: int}
     */
    public function thresholds(): array
    {
        return [
            'window_minutes' => (int) $this->systemSettingDao->getValue(self::WINDOW_MINUTES_SETTING_KEY, self::DEFAULT_WINDOW_MINUTES),
            'min_orders' => (int) $this->systemSettingDao->getValue(self::MIN_ORDERS_SETTING_KEY, self::DEFAULT_MIN_ORDERS),
            'fail_rate_percent' => (string) $this->systemSettingDao->getValue(self::FAIL_RATE_PERCENT_SETTING_KEY, self::DEFAULT_FAIL_RATE_PERCENT),
            'pause_minutes' => (int) $this->systemSettingDao->getValue(self::PAUSE_MINUTES_SETTING_KEY, self::DEFAULT_PAUSE_MINUTES),
        ];
    }

    /**
     * 判定一个作用域（$productId = 0 表示整个供应商）。
     */
    private function evaluateScope(int $supplierId, int $productId): void
    {
        if ($this->isPaused($supplierId, $productId === SupplierCircuitBreaker::PRODUCT_ID_ALL ? null : $productId)) {
            return;
        }

        $thresholds = $this->thresholds();
        $since = date('Y-m-d H:i:s', time() - $thresholds['window_minutes'] * 60);
        $counts = $this->orderAttemptDao->resultCountsForSupplier(
            $supplierId,
            $since,
            $productId === SupplierCircuitBreaker::PRODUCT_ID_ALL ? null : $productId
        );

        if ($counts['total'] < $thresholds['min_orders']) {
            return;
        }

        // 失败率用 bcmath 算到两位小数再比，跟阈值（money 类型，两位小数）同一精度
        $failRate = bcdiv(bcmul((string) $counts['failed'], '100', 4), (string) $counts['total'], 2);
        if (bccomp($failRate, $thresholds['fail_rate_percent'], 2) <= 0) {
            return;
        }

        $reason = sprintf(
            '近 %d 分钟 %d 单中 %d 单失败，失败率 %s%%，超过阈值 %s%%',
            $thresholds['window_minutes'],
            $counts['total'],
            $counts['failed'],
            $failRate,
            $thresholds['fail_rate_percent']
        );

        $this->breakerDao->upsertStatus(
            $supplierId,
            $productId,
            SupplierCircuitBreaker::STATUS_PAUSED,
            date('Y-m-d H:i:s', time() + $thresholds['pause_minutes'] * 60),
            $reason
        );

        $this->alert($supplierId, $productId, $reason, $thresholds['pause_minutes']);
    }

    /**
     * requirements.md 6.6「自动暂停分配新订单 5 分钟并告警」的告警那一半。
     * TODO（告警模块，docs/modules.md 第 8 节「告警」行，二期）：这里改成写一条
     * `alerts` 行（type = supplier_circuit_broken，见 database-design.md 4.10）。
     * 在那之前先记 error 日志，至少排查时查得到。
     */
    private function alert(int $supplierId, int $productId, string $reason, int $pauseMinutes): void
    {
        $this->logger()->error('supplier circuit broken', [
            'supplier_id' => $supplierId,
            'product_id' => $productId === SupplierCircuitBreaker::PRODUCT_ID_ALL ? 'all' : $productId,
            'reason' => $reason,
            'pause_minutes' => $pauseMinutes,
        ]);
    }

    private function logger(): LoggerInterface
    {
        return $this->loggerFactory->get('supplier');
    }
}
