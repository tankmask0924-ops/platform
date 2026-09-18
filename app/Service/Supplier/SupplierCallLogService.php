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

use App\Dao\OrderDao;
use App\Dao\SupplierCallLogDao;
use App\Dao\SupplierDao;
use App\Model\SupplierCallLog;
use App\Service\AbstractService;
use App\Supplier\CardSecretMasker;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 供应商调用日志（requirements.md 6.8「每次调用供应商都记录请求、响应、耗时，卡密打码」）。
 *
 * 写入：驱动每发一次 HTTP 请求回调 record()（由 App\Supplier\SupplierDriverFactory 接上）。
 * 关联订单靠请求里的 `external_orderno`（平台单号-第几次尝试），不需要调用方另外传订单。
 * 写日志失败只记 error 日志，不影响下单和查询本身。
 *
 * 收到的供应商回调不在这里记（`supplier_notify_logs` 还没接）。
 */
class SupplierCallLogService extends AbstractService
{
    public const ACTIONS = ['place_order', 'query', 'query_balance', 'goods_detail', 'goods_list'];

    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected SupplierCallLogDao $callLogDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $response
     */
    public function record(int $supplierId, string $action, array $request, array $response, int $durationMs): void
    {
        try {
            $this->callLogDao->create([
                'supplier_id' => $supplierId,
                'order_id' => $this->resolveOrderId($request['body']['external_orderno'] ?? null),
                'action' => $action,
                'request' => CardSecretMasker::mask($request),
                'response' => CardSecretMasker::mask($response),
                'duration_ms' => $durationMs,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            $this->loggerFactory->get('supplier')->error('supplier call log write failed', [
                'supplier_id' => $supplierId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 系统后台按供应商查调用日志，最新在前。
     *
     * @param array<string, mixed> $query action / order_no / created_from / created_to / page / per_page
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function listForSupplier(int $supplierId, array $query): array
    {
        if ($this->supplierDao->find($supplierId) === null) {
            throw new HttpException(404, '供应商不存在');
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 20)));
        $filters = ['supplier_id' => $supplierId];

        $action = $query['action'] ?? '';
        if ($action !== '' && $action !== null) {
            if (! in_array($action, self::ACTIONS, true)) {
                throw new HttpException(422, 'action 不合法');
            }
            $filters['action'] = $action;
        }

        $orderNo = $query['order_no'] ?? '';
        if (is_string($orderNo) && trim($orderNo) !== '') {
            $order = $this->orderDao->findByOrderNo(trim($orderNo));
            if ($order === null) {
                return ['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
            }
            $filters['order_id'] = (int) $order->id;
        }

        foreach (['created_from' => '00:00:00', 'created_to' => '23:59:59'] as $key => $timeOfDay) {
            $value = $query[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $time = is_string($value) ? strtotime($value) : false;
            if ($time === false) {
                throw new HttpException(422, $key . ' 不是合法的时间');
            }
            $filters[$key] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? "{$value} {$timeOfDay}" : date('Y-m-d H:i:s', $time);
        }

        $logs = $this->callLogDao->paginateFiltered($filters, $page, $perPage);
        $orderNos = $this->orderDao->newQuery()
            ->whereIn('id', $logs->pluck('order_id')->filter()->unique()->all())
            ->pluck('order_no', 'id');

        return [
            'data' => $logs->map(static fn (SupplierCallLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'order_id' => $log->order_id,
                'order_no' => $log->order_id !== null ? ($orderNos[$log->order_id] ?? null) : null,
                // 历史数据也再打一次码，写入时漏掉的不会在后台露出来
                'request' => CardSecretMasker::mask($log->request),
                'response' => CardSecretMasker::mask($log->response),
                'http_status' => $log->response['http_status'] ?? null,
                'duration_ms' => $log->duration_ms,
                'created_at' => $log->created_at?->toDateTimeString(),
            ])->values()->all(),
            'total' => $this->callLogDao->countFiltered($filters),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * external_orderno 是「平台单号-第几次尝试」（App\Service\Order\SupplierRouter::callSupplier()）。
     */
    private function resolveOrderId(mixed $externalOrderNo): ?int
    {
        if (! is_string($externalOrderNo) || preg_match('/^(.+)-\d+$/', $externalOrderNo, $m) !== 1) {
            return null;
        }

        $order = $this->orderDao->findByOrderNo($m[1]);

        return $order !== null ? (int) $order->id : null;
    }
}
