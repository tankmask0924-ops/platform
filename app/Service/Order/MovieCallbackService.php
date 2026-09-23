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

use App\Dao\OrderDao;
use App\Exception\CallbackOrderNotFoundException;
use App\Model\Order;
use App\Model\Supplier;
use App\Service\AbstractService;
use App\Service\Movie\MovieBaseDataSyncService;
use App\Supplier\DriverResult;
use App\Supplier\Mango\MangoDriver;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\UnifiedResult;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use RuntimeException;
use Throwable;

/**
 * 芒果（电影票）回调（mango.md 第 1、4 节），由 App\Service\Order\SupplierCallbackService 按驱动分派过来。
 *
 * **订单回调没有签名**：驱动 parseCallback() 只取 `order_number` 再调带签名的订单详情，这里只用权威结果推进
 * （MovieOrderSettlementService::apply()）。认领订单同快递：先按芒果单号；锁座结果未知、还没记下芒果单号的，
 * 按**查询结果里**原样带回的 `attach`（锁座时放的平台订单号），不信 payload 里的任何字段。
 * 认不出来 404、查询本身失败抛异常（不回复成功，芒果每种状态最多回调 4 次，还会再来）。
 *
 * **影院更新回调**（`/notify/{code}/{token}/cinema`）：只是"这家影院变了"的通知，按影院 ID 重拉它所在城市的影院。
 * 芒果不要求回复、只发一次不补发，这里失败只记日志（漏掉的由每日全量同步兜底）。
 */
class MovieCallbackService extends AbstractService
{
    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected MovieOrderSettlementService $settlementService;

    #[Inject]
    protected MovieBaseDataSyncService $baseDataSyncService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(Supplier $supplier, array $payload): string
    {
        $result = $this->supplierDriverFactory->buildMango($supplier)->parseCallback($payload);
        if ($result === null) {
            throw new CallbackOrderNotFoundException('MovieCallbackService: callback carries no order_number.');
        }
        if ($result->result === UnifiedResult::Unknown && $result->movieDetails === null) {
            throw new RuntimeException('MovieCallbackService: order detail query failed: ' . $result->failReason);
        }

        $order = $this->claimOrder((int) $supplier->id, $result);
        if ($order === null) {
            throw new CallbackOrderNotFoundException('MovieCallbackService: no movie order matches this callback.');
        }

        $this->settlementService->apply($order, $result, (int) $supplier->id);

        return MangoDriver::CALLBACK_REPLY;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handleCinemaUpdate(Supplier $supplier, array $payload): void
    {
        try {
            $update = $this->supplierDriverFactory->buildMango($supplier)->parseCinemaUpdate($payload);
            if ($update !== null) {
                $this->baseDataSyncService->syncCinema($supplier, $update['cinema_id']);
            }
        } catch (Throwable $e) {
            $this->loggerFactory->get('movie')->warning('movie cinema update sync failed', ['supplier_id' => $supplier->id, 'error' => $e->getMessage()]);
        }
    }

    private function claimOrder(int $supplierId, DriverResult $result): ?Order
    {
        if ($result->supplierOrderNo !== null) {
            $order = $this->orderDao->findBySupplierOrderNo($supplierId, $result->supplierOrderNo);
            if ($order !== null) {
                return $order->business_line === MovieOrderSettlementService::BUSINESS_LINE ? $order : null;
            }
        }

        $platformOrderNo = $result->movieDetails['platform_order_no'] ?? null;
        if (! is_string($platformOrderNo) || $platformOrderNo === '') {
            return null;
        }
        $order = $this->orderDao->findByOrderNo($platformOrderNo);
        if ($order === null
            || $order->business_line !== MovieOrderSettlementService::BUSINESS_LINE
            || ($order->supplier_order_no !== null && $order->supplier_order_no !== $result->supplierOrderNo)) {
            return null;
        }

        return $order;
    }
}
