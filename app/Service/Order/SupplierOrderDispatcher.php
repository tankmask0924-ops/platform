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
use App\Dao\OrderRechargeDao;
use App\Dao\ProductDao;
use App\Job\PlaceSupplierOrderJob;
use App\Model\Order;
use App\Model\Product;
use App\Service\AbstractService;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 话费、卡券订单"调用供应商下单"的分派（requirements.md 9「调用供应商下单……走异步队列，不阻塞商户下单请求」）。
 *
 * - **下单请求里只建单、冻结、入队**，马上给商户返回"处理中"；供应商路由（SupplierRouter::routeNewOrder()）
 *   在队列消费者里跑。卡速售本来就是受理制，同步下单拿到的绝大多数也只是"处理中"，商户侧的约定
 *   （最终结果看回调或查询）不变；变的是商户请求不再陪着一家家供应商等 10 秒超时。
 * - **幂等**：dispatch() 只处理"仍在处理中、一次尝试都没有"的订单；两个消费者同时拿到同一笔，
 *   `order_attempts` 的 (order_id, attempt_no) 唯一索引让后到的直接退出（SupplierRouter 里的 claim），
 *   不会重复下单。
 * - **兜底**：入队失败（Redis 不可用）只记日志、不让商户下单失败——钱已经冻结、订单已经建好，
 *   这时报错商户会重试成另一笔单。漏掉的由 redispatchStale()（每分钟的
 *   App\Crontab\SupplierDispatchRecoveryCrontab）捡回来：处理中、没有任何尝试、建单超过 1 分钟的订单重新入队。
 *   迟迟没分派出去、已经被标成异常单的不再自动下单（dispatch() 只认处理中），交给人工处理。
 * - **开关**：`config('supplier.dispatch_async')`（环境变量 `SUPPLIER_DISPATCH_ASYNC`，默认开）。关掉时在请求里同步路由，
 *   行为跟改造前一样；测试环境关掉，已有的路由用例照旧按同步结果断言。
 *
 * 电影票锁座、快递下单**不走这里**：锁座结果（锁没锁上、锁到几点）是商户下一步确认出票的前提，必须同步给；
 * 快递下单要把云洋的冻结运费同步算进冻结金额、返回给商户，而且云洋没有防重复单号，放进队列后
 * 消费者超时重跑就是重复下单。两者都只调一次供应商、不做多家切换，同步等一次的代价有限。
 */
class SupplierOrderDispatcher extends AbstractService
{
    public const BUSINESS_LINES = ['recharge', 'card'];

    /** 建单多久还没有任何尝试，就认为入队丢了 */
    private const STALE_AFTER_SECONDS = 60;

    private const REDISPATCH_BATCH = 100;

    #[Inject]
    protected SupplierRouter $supplierRouter;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderAttemptDao $orderAttemptDao;

    #[Inject]
    protected OrderRechargeDao $orderRechargeDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected DriverFactory $queueFactory;

    #[Inject]
    protected ConfigInterface $config;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 下单请求里调用：订单已建好、已冻结。异步时入队后立即返回；同步时直接路由（改造前的行为）。
     */
    public function enqueue(Order $order, Product $product, ?string $rechargeAccount): void
    {
        if (! $this->config->get('supplier.dispatch_async', true)) {
            $this->supplierRouter->routeNewOrder($order, $product, $rechargeAccount);

            return;
        }

        try {
            $this->queueFactory->get('default')->push(new PlaceSupplierOrderJob((int) $order->id));
        } catch (Throwable $e) {
            $this->logger()->error('supplier dispatch enqueue failed, recovery crontab will pick it up', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 队列消费者调用。
     *
     * @return bool 是否真的路由了这笔订单
     */
    public function dispatch(int $orderId): bool
    {
        $order = $this->orderDao->find($orderId);
        if ($order === null || $order->status !== Order::STATUS_PROCESSING
            || ! in_array($order->business_line, self::BUSINESS_LINES, true)) {
            return false;
        }
        if ($this->orderAttemptDao->findLatestForOrder($orderId) !== null) {
            // 已经分派过（重复入队、兜底任务和队列同时到）
            return false;
        }

        $detail = $this->orderRechargeDao->find($orderId);
        $product = $detail === null ? null : $this->productDao->find((int) $detail->product_id);
        if ($detail === null || ! $product instanceof Product) {
            $this->logger()->error('supplier dispatch skipped: order has no product detail', ['order_id' => $orderId]);

            return false;
        }

        $this->supplierRouter->routeNewOrder($order, $product, $detail->recharge_account);

        return true;
    }

    /**
     * 兜底：处理中、一次尝试都没有、建单超过 1 分钟的话费卡券订单重新入队。
     *
     * @return int 重新入队的订单数
     */
    public function redispatchStale(): int
    {
        if (! $this->config->get('supplier.dispatch_async', true)) {
            return 0;
        }

        $orderIds = $this->orderDao->listUndispatchedIds(
            self::BUSINESS_LINES,
            date('Y-m-d H:i:s', time() - self::STALE_AFTER_SECONDS),
            self::REDISPATCH_BATCH
        );
        foreach ($orderIds as $orderId) {
            try {
                $this->queueFactory->get('default')->push(new PlaceSupplierOrderJob($orderId));
            } catch (Throwable $e) {
                $this->logger()->error('supplier dispatch redispatch failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);

                break;
            }
        }
        if ($orderIds !== []) {
            $this->logger()->warning('supplier dispatch recovered undispatched orders', ['order_ids' => $orderIds]);
        }

        return count($orderIds);
    }

    private function logger(): LoggerInterface
    {
        return $this->loggerFactory->get('order');
    }
}
