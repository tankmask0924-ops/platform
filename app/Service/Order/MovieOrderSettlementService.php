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

namespace App\Service\Order;

use App\Dao\MerchantDao;
use App\Dao\MerchantRebateDao;
use App\Dao\OrderAttemptDao;
use App\Dao\OrderDao;
use App\Dao\OrderMovieDao;
use App\Dao\SupplierDao;
use App\Dao\SystemSettingDao;
use App\Model\Order;
use App\Model\OrderMovie;
use App\OpenApi\ErrorCode;
use App\Service\AbstractService;
use App\Service\Merchant\BalanceService;
use App\Service\MerchantNotifyService;
use App\Service\Product\RebateCalculator;
use App\Supplier\DriverResult;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Carbon\Carbon;
use Hyperf\Database\Exception\QueryException;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * 电影票订单的资金推进（requirements.md 7.3、7.4、5.3、5.4，mango.md 第 3 节）。同步锁座结果、芒果回调
 * （App\Service\Order\MovieCallbackService）、定时查询、超时释放拿到的结果都走这里。
 *
 * 1. **出票成功**（`handle_step` 3/4）：冻结的钱全额扣款（电影票成本在锁座时就定了，没有多退少补），
 *    订单成功，**完成时间 = 出票成功时间**（7.5，出票后不可退票），写入取票码。
 * 2. **返佣基数是供应商返佣**（查询订单详情的 `total_rebate`，5.3），比例取等级在电影票业务线的设置。
 *    芒果可能出票之后才给最终返佣（5.4「供应商返佣晚到」）：成功那一刻没有返佣就先不生成，之后的回调/查询
 *    带来了再生成；`merchant_rebates.order_id` 唯一，重复到达不会生成两条。
 * 3. **改票根**：出票后芒果会再回调一次"出票成功"，取票码变了就更新并再回调商户一次；**不重复扣款、不重复返佣**
 *    （订单已经是成功，扣款那一步走不到；返佣靠唯一索引）。
 * 4. **失败**（`handle_step` -1 支付超时 / -2 出票失败退款，或锁座时"订单溢价"）：全额解冻，订单失败，
 *    失败原因用平台文案（订单溢价、锁座超时各有专门的码，商户据此决定是否重新锁座）。
 * 5. **商户主动释放座位**：订单 `cancelled`，全额解冻（release()，由 App\Service\OpenApi\MovieOrderService 调用）。
 *
 * 并发同快递：动钱之前先锁 `order_movies` 行、锁内重读订单，状态跳转再用带状态条件的更新。
 * 异常单（处理中超过异常单时长）照常推进——芒果带签名查询给出的就是确定结果。
 */
class MovieOrderSettlementService extends AbstractService
{
    public const BUSINESS_LINE = 'movie';

    private const ADVANCEABLE_STATUSES = [Order::STATUS_PROCESSING, Order::STATUS_ABNORMAL];

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderMovieDao $orderMovieDao;

    #[Inject]
    protected OrderAttemptDao $orderAttemptDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected BalanceService $balanceService;

    #[Inject]
    protected MerchantNotifyService $merchantNotifyService;

    #[Inject]
    protected RebateCalculator $rebateCalculator;

    #[Inject]
    protected MerchantRebateDao $merchantRebateDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected SystemSettingDao $systemSettingDao;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * @param bool $fromLock 同步锁座的结果：此时明确失败只可能是"订单溢价"
     */
    public function apply(Order $order, DriverResult $result, int $supplierId, bool $fromLock = false): void
    {
        $this->recordAttemptResult($order, $result);

        match ($result->result) {
            UnifiedResult::Success => $this->applyIssued($order, $result, $supplierId),
            UnifiedResult::DefiniteFailure => $this->finishUnsettled(
                $order,
                Order::STATUS_FAILED,
                $this->failureReason($result, $fromLock),
                $supplierId
            ),
            UnifiedResult::Processing, UnifiedResult::Unknown => $this->recordProgress($order, $result, $supplierId),
        };
    }

    /**
     * 立即查芒果并推进（定时查询、后台手动查询、确认出票后的确认共用）。没有芒果单号（锁座结果未知）返回 null。
     */
    public function refreshFromSupplier(Order $order): ?DriverResult
    {
        if ($order->supplier_order_no === null || $order->supplier_order_no === '' || $order->supplier_id === null) {
            return null;
        }
        $supplier = $this->supplierDao->find((int) $order->supplier_id);
        if ($supplier === null) {
            throw new RuntimeException('supplier #' . $order->supplier_id . ' of movie order #' . $order->id . ' not found');
        }

        $result = $this->supplierDriverFactory->buildMango($supplier)->queryOrder($order->supplier_order_no);
        $order->refresh();
        $this->apply($order, $result, (int) $supplier->id);

        return $result;
    }

    /**
     * 没出票就结束：锁座超时（failed + 锁座超时原因）、商户释放座位（cancelled）、芒果说出票失败。全额解冻。
     * `$onlyIfUnconfirmed`：释放座位和超时释放必须在明细行锁里确认"商户还没确认出票"，否则跟确认出票有竞态。
     *
     * @return bool 是否由这次调用结束了订单
     */
    public function finishUnsettled(Order $order, string $status, ?string $failReason, ?int $supplierId = null, bool $onlyIfUnconfirmed = false): bool
    {
        $finished = Db::transaction(function () use ($order, $status, $failReason, $supplierId, $onlyIfUnconfirmed) {
            $movie = $this->orderMovieDao->lockForUpdate((int) $order->id);
            $order->refresh();
            if (! in_array($order->status, self::ADVANCEABLE_STATUSES, true)) {
                return false;
            }
            if ($onlyIfUnconfirmed && $movie?->confirmed_at !== null) {
                // 释放座位 / 超时释放跟确认出票同时到达：确认已经先拿到锁，不能再释放
                return false;
            }

            $attributes = ['status' => $status, 'fail_reason' => $failReason, 'finished_at' => date('Y-m-d H:i:s')];
            if ($supplierId !== null) {
                $attributes['supplier_id'] = $supplierId;
            }
            if (! $this->orderDao->finishIfStatus($order, $attributes, $order->status)) {
                return false;
            }

            $this->balanceService->unfreeze((int) $order->merchant_id, (int) $order->id, $order->frozen_amount);

            return true;
        });

        if ($finished) {
            $this->merchantNotifyService->notify((int) $order->id);
        }

        return $finished;
    }

    private function applyIssued(Order $order, DriverResult $result, int $supplierId): void
    {
        $tickets = $result->movieDetails['tickets'] ?? [];
        $rebate = $result->supplierRebate;

        $outcome = Db::transaction(function () use ($order, $result, $supplierId, $tickets, $rebate) {
            $movie = $this->orderMovieDao->lockForUpdate((int) $order->id);
            if ($movie === null) {
                throw new RuntimeException('order_movies row of order #' . $order->id . ' not found');
            }
            $order->refresh();

            if (in_array($order->status, self::ADVANCEABLE_STATUSES, true)) {
                // 出票时间就是完成时间（返佣起算点），用 Carbon 取，测试能用假时钟钉住
                $now = Carbon::now()->toDateTimeString();
                $finished = $this->orderDao->finishIfStatus($order, [
                    'status' => Order::STATUS_SUCCESS,
                    'supplier_id' => $supplierId,
                    'supplier_order_no' => $order->supplier_order_no ?? $result->supplierOrderNo,
                    'deducted_amount' => $order->frozen_amount,
                    'completed_at' => $now,
                    'finished_at' => $now,
                ], $order->status);
                if (! $finished) {
                    return null;
                }
                $this->balanceService->deduct((int) $order->merchant_id, (int) $order->id, $order->frozen_amount);
                $this->updateMovie($movie, $tickets, $rebate);

                return 'issued';
            }

            if ($order->status !== Order::STATUS_SUCCESS) {
                // 已经失败/取消的单又说出票成功：两边对不上，不动钱，留日志人工核实
                $this->logger()->warning('movie order issued after it was finished', ['order_id' => $order->id, 'status' => $order->status]);

                return null;
            }

            // 已经成功：改票根或者返佣晚到
            $ticketsChanged = $tickets !== [] && $tickets !== ($movie->ticket_codes ?? []);
            $this->updateMovie($movie, $tickets, $rebate);

            return $ticketsChanged ? 'tickets_changed' : 'unchanged';
        });

        if ($outcome === null) {
            return;
        }
        if ($outcome !== 'unchanged') {
            $this->merchantNotifyService->notify((int) $order->id);
        }
        $this->generateRebateIfDue($order->refresh(), $rebate);
    }

    /**
     * @param list<mixed> $tickets
     */
    private function updateMovie(OrderMovie $movie, array $tickets, ?string $rebate): void
    {
        if ($tickets !== []) {
            $movie->ticket_codes = $tickets;
        }
        if ($rebate !== null) {
            $movie->supplier_rebate = $rebate;
        }
        $movie->save();
    }

    /**
     * 5.3 / 5.4：订单成功且拿到了供应商返佣才生成待到账返佣；已经生成过的靠 `order_id` 唯一索引跳过。
     */
    private function generateRebateIfDue(Order $order, ?string $supplierRebate): void
    {
        if ($order->status !== Order::STATUS_SUCCESS || $order->completed_at === null
            || $supplierRebate === null || bccomp($supplierRebate, '0', 2) <= 0) {
            return;
        }
        $merchant = $this->merchantDao->find((int) $order->merchant_id);
        if ($merchant === null) {
            return;
        }

        $calculation = $this->rebateCalculator->calculateForSupplierRebate(self::BUSINESS_LINE, $supplierRebate, $merchant->level_id);
        if (bccomp($calculation->amount, '0', 2) <= 0) {
            return;
        }

        $dueDays = (int) $this->systemSettingDao->getValue(
            OrderResultApplier::REBATE_DUE_PERIOD_SETTING_KEY,
            OrderResultApplier::DEFAULT_REBATE_DUE_PERIOD_DAYS
        );
        $completedAt = $order->completed_at->toDateTimeString();

        try {
            $this->merchantRebateDao->create([
                'order_id' => $order->id,
                'merchant_id' => $order->merchant_id,
                'business_line' => self::BUSINESS_LINE,
                'level_id' => $merchant->level_id,
                'rebate_base' => $supplierRebate,
                'rebate_base_source' => 'supplier',
                'rebate_rate' => $calculation->rate,
                'rebate_rate_source' => $calculation->rateSource,
                'amount' => $calculation->amount,
                'status' => 'pending',
                'order_completed_at' => $completedAt,
                // 返佣晚到超过到账时间的，下一次到账任务直接入账（5.4）
                'due_at' => Carbon::parse($completedAt)->addDays($dueDays)->toDateTimeString(),
            ]);
        } catch (QueryException) {
            // order_id 唯一：已经生成过（改票根回调、重复推送），幂等跳过
        }
    }

    private function recordProgress(Order $order, DriverResult $result, int $supplierId): void
    {
        if ($order->supplier_order_no === null && $result->supplierOrderNo !== null) {
            $order->fill(['supplier_id' => $supplierId, 'supplier_order_no' => $result->supplierOrderNo])->save();
        }
        if ($result->result === UnifiedResult::Unknown) {
            $this->logger()->info('movie order result unknown', ['order_id' => $order->id, 'reason' => $result->failReason]);
        }
    }

    private function failureReason(DriverResult $result, bool $fromLock): string
    {
        if ($fromLock && ($result->movieDetails['price_changed'] ?? false)) {
            return ErrorCode::MoviePriceChanged->message();
        }
        if ($result->movieDetails['lock_expired'] ?? false) {
            return ErrorCode::MovieLockTimeout->message();
        }

        return ErrorCode::OrderFailed->message();
    }

    private function recordAttemptResult(Order $order, DriverResult $result): void
    {
        $attempt = $this->orderAttemptDao->findLatestForOrder((int) $order->id);
        if ($attempt === null) {
            return;
        }
        $attempt->fill([
            'result' => SupplierRouter::RESULT_MAP[$result->result->name],
            'fail_reason' => $result->failReason ?? $attempt->fail_reason,
        ]);
        $attempt->isDirty() ? $attempt->save() : $attempt->touch();
    }

    private function logger(): LoggerInterface
    {
        return $this->loggerFactory->get('movie');
    }
}
