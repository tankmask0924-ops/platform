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

use App\Dao\OrderAttemptDao;
use App\Dao\OrderDao;
use App\Dao\SupplierDao;
use App\Model\Order;
use App\Model\OrderAttempt;
use App\Service\AbstractService;
use App\Supplier\DriverResult;
use App\Supplier\SupplierDriverFactory;
use Hyperf\Coroutine\Parallel;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * 供应商结果定时查询（requirements.md 6.2「处理中 / 结果未知：等回调或定时查询」、
 * 7.1「超时订单保持处理中，靠查询接口或供应商回调拿到最终结果」）。给回调丢失、
 * 下单超时的订单兜底，由 App\Crontab\SupplierResultQueryCrontab 每分钟触发。
 *
 * 每次取一批"订单处理中、最新一次尝试仍是处理中/未知、距上次更新超过查询间隔"的
 * 尝试，用下单时的供应商侧单号 `{order_no}-{attempt_no}` 调驱动 queryOrder()，
 * 结果跟回调一样交给 SupplierRouter::applyAttemptResult()：明确失败（包括查询确认
 * 供应商没有这笔订单）按切换规则换下一家，其余直接落到订单上。
 *
 * - 查询间隔按尝试的 updated_at 计：下单、回调、上一次查询都会刷新它，刚下单的订单
 *   至少等一个间隔才查，避免供应商那边还没落库就被查成"没有这笔订单"。
 * - 查询本身失败（驱动构造失败、网络异常）只记日志并刷新 updated_at，下个间隔再查，
 *   不改订单。
 * - 只查处理中的订单；被标成异常单后不再定时查询，后台"手动查询供应商"也走
 *   queryLatestAttempt()，异常单查到的结果只记到尝试记录上。
 * - 查询和处理之间订单可能已经被回调推进或切换到新的尝试，处理前重新确认。
 */
class SupplierResultPollingService extends AbstractService
{
    /**
     * 同一次尝试两次查询之间至少隔这么久（秒）。
     */
    public const QUERY_INTERVAL_SECONDS = 60;

    public const BATCH_SIZE = 100;

    /**
     * 同时查询的供应商请求数，驱动单次请求超时 10 秒。
     */
    private const CONCURRENCY = 10;

    #[Inject]
    protected OrderAttemptDao $orderAttemptDao;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected SupplierRouter $supplierRouter;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    #[Inject]
    protected ExpressOrderSettlementService $expressSettlementService;

    /**
     * @return int 这次实际发起查询的尝试数
     */
    public function pollDue(): int
    {
        $updatedBefore = date('Y-m-d H:i:s', time() - self::QUERY_INTERVAL_SECONDS);
        $attempts = $this->orderAttemptDao->listDueForQuery($updatedBefore, self::BATCH_SIZE);

        $parallel = new Parallel(self::CONCURRENCY);
        foreach ($attempts as $attempt) {
            $parallel->add(fn () => $this->pollOne($attempt));
        }
        $results = $parallel->wait(false);

        return count(array_filter($results));
    }

    /**
     * 立即向供应商查询这笔订单最新一次尝试的结果，并像回调一样交给
     * SupplierRouter::applyAttemptResult()（异常单只记录不改订单）。定时查询和后台
     * "手动查询供应商"共用。驱动构造/查询失败的异常原样抛出。
     *
     * @return null|DriverResult 订单没有尝试记录时返回 null
     */
    public function queryLatestAttempt(Order $order): ?DriverResult
    {
        if ($order->business_line === ExpressOrderSettlementService::BUSINESS_LINE) {
            // 快递按云洋单号查、结果交给快递自己的结算（requirements.md 7.2），不走路由切换
            return $this->expressSettlementService->refreshFromSupplier($order);
        }

        $attempt = $this->orderAttemptDao->findLatestForOrder((int) $order->id);
        if ($attempt === null) {
            return null;
        }

        $supplier = $this->supplierDao->find((int) $attempt->supplier_id);
        if ($supplier === null) {
            throw new RuntimeException('supplier #' . $attempt->supplier_id . ' of order attempt #' . $attempt->id . ' not found');
        }

        $result = $this->supplierDriverFactory->build($supplier)
            ->queryOrder($order->order_no . '-' . $attempt->attempt_no, $order->business_line === 'card');

        // 查询期间可能已经有回调推进了订单或切到了下一家
        $order->refresh();
        $latest = $this->orderAttemptDao->findLatestForOrder((int) $order->id);
        if ($latest !== null && (int) $latest->id === (int) $attempt->id) {
            $this->supplierRouter->applyAttemptResult($order, $latest, $result, (int) $supplier->id);
        }

        return $result;
    }

    /**
     * @return bool 是否真的调用了供应商查询
     */
    private function pollOne(OrderAttempt $attempt): bool
    {
        try {
            $order = $this->orderDao->find((int) $attempt->order_id);
            if ($order === null || $order->status !== Order::STATUS_PROCESSING) {
                return false;
            }

            return $this->queryLatestAttempt($order) !== null;
        } catch (Throwable $e) {
            $this->logger()->error('supplier result query failed', [
                'order_attempt_id' => $attempt->id,
                'order_id' => $attempt->order_id,
                'supplier_id' => $attempt->supplier_id,
                'error' => $e->getMessage(),
            ]);
            $this->touchQuietly($attempt);

            return false;
        }
    }

    private function touchQuietly(OrderAttempt $attempt): void
    {
        try {
            $attempt->touch();
        } catch (Throwable) {
            // 刷新失败只会让下一轮提前再查一次
        }
    }

    private function logger(): LoggerInterface
    {
        return $this->loggerFactory->get('order');
    }
}
