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

use App\Dao\MerchantBalanceLogDao;
use App\Dao\MerchantDao;
use App\Dao\OrderDao;
use App\Model\Alert;
use App\Service\AbstractService;
use App\Service\Alert\AlertService;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;

/**
 * 冻结余额定时核对（requirements.md 9「定时核对：商户冻结余额 = 该商户所有处理中订单的冻结金额之和」），
 * 由 App\Crontab\FrozenBalanceCheckCrontab 定时调用。
 *
 * **要抓的是"两步之间断掉"**：订单改状态和动冻结余额不在同一个事务里（先标成功再 `deduct()`、
 * 先标失败再 `unfreeze()`、先建订单行再 `freeze()`），中间进程挂了就会留下成功订单还冻着钱、
 * 或处理中订单根本没冻钱，之后扣款会把冻结余额扣穿。单笔操作本身有流水和幂等，发现不了这种情况。
 *
 * **口径**：`merchants.frozen_balance` 对比处理中 + 异常订单的 `orders.frozen_amount` 之和。
 * 只看冻结余额不为 0、或者还有处理中/异常订单的商户，两边都是 0 的不用比。
 *
 * **复查一次，两次差额相同才报**：上面那几个两步操作之间本来就有毫秒级的窗口，正好撞上会看到一次
 * 假差额。隔几秒对这个商户重算，差额没变才是真问题；变了（窗口已过或又撞上新的窗口）就留给下一轮。
 *
 * **只报告警、不自动改钱**：报一条 `frozen_balance_mismatch`（critical，按商户去重），内容带上按流水
 * 定位出的问题订单号——每笔订单按流水还冻着多少（freeze + freeze_adjust − deduct − unfreeze），
 * 处理中/异常订单应等于 `frozen_amount`，其余状态应为 0。定位不到订单时再比一下最近一条流水的
 * `frozen_after`，对不上说明余额被绕过 BalanceService 直接改过。完整明细记在 `alert` 日志里。
 */
class FrozenBalanceCheckService extends AbstractService
{
    public const DEFAULT_RECHECK_DELAY_SECONDS = 5;

    /** 告警内容里最多列几个订单号，完整列表看日志 */
    private const MAX_ORDER_NOS_IN_MESSAGE = 3;

    private const SCALE = 2;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected MerchantBalanceLogDao $balanceLogDao;

    #[Inject]
    protected AlertService $alertService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 核对全部商户：冻结余额不为 0、或者还有处理中/异常订单的。
     *
     * @return array{checked: int, suspected: int, mismatched: int}
     */
    public function checkAll(float $recheckDelaySeconds = self::DEFAULT_RECHECK_DELAY_SECONDS): array
    {
        return $this->check(null, $recheckDelaySeconds);
    }

    /**
     * 只核对指定商户（排查某个商户、测试用）。
     *
     * @param list<int> $merchantIds
     * @return array{checked: int, suspected: int, mismatched: int}
     */
    public function checkMerchants(array $merchantIds, float $recheckDelaySeconds = self::DEFAULT_RECHECK_DELAY_SECONDS): array
    {
        return $merchantIds === [] ? ['checked' => 0, 'suspected' => 0, 'mismatched' => 0] : $this->check($merchantIds, $recheckDelaySeconds);
    }

    /**
     * @param null|list<int> $scope null = 全部商户
     * @return array{checked: int, suspected: int, mismatched: int}
     */
    private function check(?array $scope, float $recheckDelaySeconds): array
    {
        $balances = $this->merchantDao->frozenBalances($scope);
        $openFrozen = $this->orderDao->sumOpenFrozenByMerchant($scope);
        $merchantIds = array_unique(array_merge(array_keys($balances), array_keys($openFrozen)));

        $suspected = [];
        foreach ($merchantIds as $merchantId) {
            $diff = $this->diff($balances[$merchantId] ?? '0', $openFrozen[$merchantId] ?? '0');
            if (bccomp($diff, '0', self::SCALE) !== 0) {
                $suspected[$merchantId] = $diff;
            }
        }

        $mismatched = 0;
        if ($suspected !== []) {
            if ($recheckDelaySeconds > 0) {
                Coroutine::sleep($recheckDelaySeconds);
            }
            foreach ($suspected as $merchantId => $firstDiff) {
                if ($this->recheck($merchantId, $firstDiff)) {
                    ++$mismatched;
                }
            }
        }

        return ['checked' => count($merchantIds), 'suspected' => count($suspected), 'mismatched' => $mismatched];
    }

    /**
     * 对单个商户重算一次，差额跟第一次相同时定位订单并报告警。
     */
    private function recheck(int $merchantId, string $firstDiff): bool
    {
        $balance = $this->merchantDao->frozenBalances([$merchantId])[$merchantId] ?? null;
        if ($balance === null) {
            return false;
        }
        $expected = $this->orderDao->sumOpenFrozenByMerchant([$merchantId])[$merchantId] ?? '0';
        $diff = $this->diff($balance, $expected);
        if (bccomp($diff, $firstDiff, self::SCALE) !== 0) {
            return false;
        }

        $orders = $this->locateOrders($merchantId);
        $latestFrozenAfter = $this->balanceLogDao->latestFrozenAfter($merchantId);

        $this->loggerFactory->get('alert')->error('frozen balance mismatch', [
            'merchant_id' => $merchantId,
            'frozen_balance' => $balance,
            'open_orders_frozen' => $expected,
            'diff' => $diff,
            'latest_log_frozen_after' => $latestFrozenAfter,
            'orders' => $orders,
        ]);

        $message = sprintf(
            '商户 #%d 冻结余额 %s，处理中订单冻结合计 %s，差 %s；',
            $merchantId,
            $this->money($balance),
            $this->money($expected),
            $this->money($diff)
        );
        if ($orders !== []) {
            $orderNos = array_column(array_slice($orders, 0, self::MAX_ORDER_NOS_IN_MESSAGE), 'order_no');
            $message .= sprintf('流水对不上的订单 %d 笔：%s', count($orders), implode('、', $orderNos));
            if (count($orders) > self::MAX_ORDER_NOS_IN_MESSAGE) {
                $message .= ' 等';
            }
        } elseif ($latestFrozenAfter !== null && bccomp($latestFrozenAfter, $balance, self::SCALE) !== 0) {
            $message .= sprintf('订单流水都对得上，但最近一条流水记的冻结余额是 %s，余额可能被直接改过', $this->money($latestFrozenAfter));
        } else {
            $message .= '未定位到具体订单，需人工核对资金流水';
        }

        $this->alertService->raise(Alert::TYPE_FROZEN_BALANCE_MISMATCH, Alert::LEVEL_CRITICAL, $message, 'merchant', $merchantId);

        return true;
    }

    /**
     * 按流水算每笔订单还冻着多少，跟订单状态应有的冻结金额比，列出对不上的订单。
     *
     * @return list<array{order_id: int, order_no: string, expected_frozen: string, ledger_frozen: string}>
     */
    private function locateOrders(int $merchantId): array
    {
        $expected = $this->orderDao->openFrozenAmountsForMerchant($merchantId);
        $ledger = $this->balanceLogDao->netFrozenByOrder($merchantId);

        $rows = [];
        foreach (array_unique(array_merge(array_keys($expected), array_keys($ledger))) as $orderId) {
            $should = $expected[$orderId] ?? '0';
            $actual = $ledger[$orderId] ?? '0';
            if (bccomp($should, $actual, self::SCALE) !== 0) {
                $rows[$orderId] = ['order_id' => $orderId, 'expected_frozen' => $this->money($should), 'ledger_frozen' => $this->money($actual)];
            }
        }
        if ($rows === []) {
            return [];
        }

        ksort($rows);
        $orderNos = $this->orderDao->orderNosByIds(array_keys($rows));

        return array_values(array_map(
            static fn (array $row) => ['order_id' => $row['order_id'], 'order_no' => $orderNos[$row['order_id']] ?? ('#' . $row['order_id'])] + $row,
            $rows
        ));
    }

    private function diff(string $balance, string $expected): string
    {
        return bcsub($balance, $expected, self::SCALE);
    }

    private function money(string $amount): string
    {
        return bcadd($amount, '0', self::SCALE);
    }
}
