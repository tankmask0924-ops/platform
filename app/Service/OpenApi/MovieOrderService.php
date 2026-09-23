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

namespace App\Service\OpenApi;

use App\Dao\OrderDao;
use App\Dao\OrderMovieDao;
use App\Dao\SupplierDao;
use App\Exception\OpenApiException;
use App\Model\Merchant;
use App\Model\Order;
use App\Model\OrderMovie;
use App\OpenApi\ErrorCode;
use App\Service\AbstractService;
use App\Service\Order\MovieOrderPresenter;
use App\Service\Order\MovieOrderSettlementService;
use App\Supplier\Mango\MangoDriver;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 开放 API「确认出票」「释放座位」和锁座超时释放（requirements.md 7.3）。
 *
 * **确认出票**：只能在锁座有效期内、对已经拿到芒果单号的锁座订单确认一次。先在明细行锁里写下
 * `confirmed_at`（写下之后超时释放任务就不会再碰这笔单），再调芒果确认下单。芒果受理后订单仍是处理中，
 * 出票结果靠回调/定时查询（交给 MovieOrderSettlementService）。同一笔单重复确认直接返回当前状态，
 * 不重复调芒果。芒果确认接口没受理（被拒或超时）也不回滚 `confirmed_at`：真实状态以查询订单详情为准，
 * 这里立刻查一次推进；查不出结论的留给定时查询和异常单。
 *
 * **释放座位**：没确认出票的锁座订单，商户放弃时释放。先在平台侧把订单结束（`cancelled`、全额解冻，锁内再确认
 * 一次没被确认出票），再通知芒果释放（失败只记日志——没确认的锁座芒果 10 分钟后也会自己超时释放，不会出票扣钱）。
 *
 * **超时释放**（expireLocks()，每分钟由 App\Crontab\MovieLockExpiryCrontab 调）：锁座到期仍未确认的订单，
 * 同样先结束订单（失败码 43005 锁座超时）、全额解冻，再通知芒果释放。锁座结果未知、没有芒果单号的订单也一样处理：
 * 没有单号商户就确认不了，芒果那边的锁座到期自己失效，不会出票。
 */
class MovieOrderService extends AbstractService
{
    /** 到期后多等一会儿再释放，给卡在最后几秒的确认请求留余地 */
    private const EXPIRY_GRACE_SECONDS = 30;

    private const EXPIRY_BATCH_SIZE = 200;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderMovieDao $orderMovieDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected MovieOrderSettlementService $settlementService;

    #[Inject]
    protected MovieOrderPresenter $presenter;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * @return array<string, mixed>
     */
    public function confirm(Merchant $merchant, ?string $orderNo, ?string $merchantOrderNo): array
    {
        $order = $this->findOrder($merchant, $orderNo, $merchantOrderNo);

        $firstConfirm = Db::transaction(function () use ($order) {
            $movie = $this->orderMovieDao->lockForUpdate((int) $order->id);
            $order->refresh();
            if ($movie?->confirmed_at !== null) {
                return false;
            }
            if ($movie === null || ! in_array($order->status, [Order::STATUS_PROCESSING, Order::STATUS_ABNORMAL], true)
                || $order->supplier_order_no === null) {
                // 已经失败/取消，或者锁座结果未知没有芒果单号：没法确认
                throw new OpenApiException(ErrorCode::OrderNotCancellable, '订单当前状态不能确认出票');
            }
            if ($movie->lock_expire_at->isPast()) {
                throw new OpenApiException(ErrorCode::MovieLockExpired);
            }

            $movie->fill(['confirmed_at' => date('Y-m-d H:i:s')])->save();

            return true;
        });

        if ($firstConfirm) {
            $result = $this->driverFor($order)->confirmOrder((string) $order->supplier_order_no);
            if ($result->result !== UnifiedResult::Processing) {
                $this->logger()->warning('movie confirm not accepted, checking order detail', ['order_id' => $order->id, 'reason' => $result->failReason]);
            }
            $this->refreshQuietly($order);
        }

        return $this->present($order->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function release(Merchant $merchant, ?string $orderNo, ?string $merchantOrderNo): array
    {
        $order = $this->findOrder($merchant, $orderNo, $merchantOrderNo);
        $movie = $this->orderMovieDao->findByOrderId((int) $order->id);
        if ($movie === null || $movie->confirmed_at !== null
            || ! in_array($order->status, [Order::STATUS_PROCESSING, Order::STATUS_ABNORMAL], true)) {
            throw new OpenApiException(ErrorCode::OrderNotCancellable, '已确认出票或已结束的订单不能释放座位');
        }

        // 先在平台侧结束（锁内再确认一次没被确认出票），再通知芒果：反过来的话，释放到一半商户确认了，
        // 芒果那边座位已经放掉、平台这边却在等出票
        if (! $this->settlementService->finishUnsettled($order, Order::STATUS_CANCELLED, null, null, true)) {
            throw new OpenApiException(ErrorCode::OrderNotCancellable, '订单状态已变化，请查询订单');
        }
        $this->releaseAtSupplier($order);

        return $this->present($order->refresh());
    }

    /**
     * @return int 本次释放的订单数
     */
    public function expireLocks(): int
    {
        $before = date('Y-m-d H:i:s', time() - self::EXPIRY_GRACE_SECONDS);
        $released = 0;
        foreach ($this->orderMovieDao->listExpiredUnconfirmed($before, self::EXPIRY_BATCH_SIZE) as $movie) {
            /** @var OrderMovie $movie */
            $order = $this->orderDao->find((int) $movie->order_id);
            if ($order === null) {
                continue;
            }
            try {
                if ($this->settlementService->finishUnsettled($order, Order::STATUS_FAILED, ErrorCode::MovieLockTimeout->message(), null, true)) {
                    ++$released;
                    $this->releaseAtSupplier($order);
                }
            } catch (Throwable $e) {
                $this->logger()->error('movie lock expiry failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        return $released;
    }

    /**
     * 通知芒果释放座位。失败不影响平台侧释放（见类注释），只记日志。
     */
    private function releaseAtSupplier(Order $order): void
    {
        if ($order->supplier_order_no === null) {
            return;
        }
        try {
            $released = $this->driverFor($order)->releaseSeats($order->supplier_order_no);
            if (! $released['released']) {
                $this->logger()->info('movie release rejected by supplier', ['order_id' => $order->id, 'message' => $released['message']]);
            }
        } catch (Throwable $e) {
            $this->logger()->warning('movie release call failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private function refreshQuietly(Order $order): void
    {
        try {
            $this->settlementService->refreshFromSupplier($order);
        } catch (Throwable $e) {
            $this->logger()->warning('movie order refresh failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private function findOrder(Merchant $merchant, ?string $orderNo, ?string $merchantOrderNo): Order
    {
        $order = null;
        if ($orderNo !== null && $orderNo !== '') {
            $order = $this->orderDao->findByOrderNoForMerchant((int) $merchant->id, $orderNo);
        } elseif ($merchantOrderNo !== null && $merchantOrderNo !== '') {
            $order = $this->orderDao->findByMerchantOrderNoForMerchant((int) $merchant->id, $merchantOrderNo);
        }
        if ($order === null || $order->business_line !== MovieOrderSettlementService::BUSINESS_LINE) {
            throw new OpenApiException(ErrorCode::OrderNotFound);
        }

        return $order;
    }

    private function driverFor(Order $order): MangoDriver
    {
        $supplier = $order->supplier_id === null ? null : $this->supplierDao->find((int) $order->supplier_id);
        if ($supplier === null) {
            throw new OpenApiException(ErrorCode::MovieUnavailable);
        }

        return $this->supplierDriverFactory->buildMango($supplier);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Order $order): array
    {
        return [
            'order_no' => $order->order_no,
            'merchant_order_no' => $order->merchant_order_no,
            'business_line' => $order->business_line,
            'status' => $order->merchantFacingStatus(),
            'sale_price' => $order->sale_price,
            'frozen_amount' => $order->frozen_amount,
            'deducted_amount' => $order->deducted_amount,
            'refunded_amount' => $order->refunded_amount,
            'completed_at' => $order->completed_at?->toDateTimeString(),
        ] + ErrorCode::presentOrderFailure($order->fail_reason) + [
            'movie' => $this->presenter->present((int) $order->id),
        ];
    }

    private function logger(): LoggerInterface
    {
        return $this->loggerFactory->get('movie');
    }
}
