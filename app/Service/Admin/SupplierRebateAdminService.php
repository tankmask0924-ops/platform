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

namespace App\Service\Admin;

use App\Dao\MerchantDao;
use App\Dao\OrderDao;
use App\Dao\SupplierDao;
use App\Model\Order;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统后台「返佣管理 - 供应商返佣明细」（requirements.md 8.3、5.4），只读。
 *
 * 一行一笔电影票/快递的成功订单：供应商返佣（`null` = 供应商还没给，电影票可能出票后才给最终值）、
 * 这笔订单的商户返佣和状态，以及两者之差。快递目前云洋没有返佣字段（requirements.md 7.2），
 * 快递行的供应商返佣都是"未返回"，列出来是为了让财务看到"这些单确实没有返佣"，而不是漏了。
 *
 * 供应商返佣跟平台记录对不上的情况由每日对账报出来（`type=rebate`，
 * App\Service\Reconciliation\ReconciliationService），这里只展示平台自己记下的值。
 *
 * 口径（只列成功订单、商户返佣只算待到账和已到账）见 App\Dao\OrderDao::summarizeSupplierRebates()。
 */
class SupplierRebateAdminService extends AbstractService
{
    public const BUSINESS_LINES = ['movie', 'express'];

    public const REBATE_FILTERS = ['returned', 'missing'];

    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    /**
     * @param array<string, mixed> $query business_line / supplier_id / merchant_id / order_no /
     *                                    completed_from / completed_to / rebate（returned 已返回 / missing 未返回）/ page / per_page
     * @return array<string, mixed>
     */
    public function list(array $query): array
    {
        $filters = $this->normalizeFilters($query);
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 15)));

        $orders = $this->orderDao->paginateSupplierRebates($filters, $page, $perPage);
        $summary = $this->orderDao->summarizeSupplierRebates($filters);
        $supplierRebate = $this->money($summary['supplier_rebate']);
        $merchantRebate = $this->money($summary['merchant_rebate']);

        return [
            'data' => $this->formatRows($orders),
            'total' => $summary['count'],
            'page' => $page,
            'per_page' => $perPage,
            'summary' => [
                'count' => $summary['count'],
                'returned_count' => $summary['returned_count'],
                'missing_count' => $summary['count'] - $summary['returned_count'],
                'supplier_rebate' => $supplierRebate,
                'merchant_rebate' => $merchantRebate,
                // 返佣收支 = 供应商返佣 − 商户返佣（requirements.md 1.2）
                'rebate_balance' => bcsub($supplierRebate, $merchantRebate, 2),
            ],
        ];
    }

    /**
     * @param iterable<Order> $orders
     * @return list<array<string, mixed>>
     */
    private function formatRows(iterable $orders): array
    {
        $merchantIds = [];
        $supplierIds = [];
        foreach ($orders as $order) {
            $merchantIds[] = (int) $order->merchant_id;
            $supplierIds[] = (int) $order->supplier_id;
        }
        $merchants = $merchantIds === [] ? collect() : $this->merchantDao->newQuery()
            ->whereIn('id', array_unique($merchantIds))->get(['id', 'phone', 'email'])->keyBy('id');
        $suppliers = $supplierIds === [] ? collect() : $this->supplierDao->newQuery()
            ->whereIn('id', array_unique($supplierIds))->pluck('name', 'id');

        $rows = [];
        foreach ($orders as $order) {
            $merchant = $merchants->get((int) $order->merchant_id);
            $supplierRebate = $order->supplier_rebate === null ? null : $this->money($order->supplier_rebate);
            // 作废、已扣回的商户返佣等于没付出去，不从供应商返佣里减
            $merchantRebatePaid = in_array($order->merchant_rebate_status, ['pending', 'settled'], true)
                ? $this->money($order->merchant_rebate_amount)
                : '0.00';

            $rows[] = [
                'order_id' => (int) $order->id,
                'order_no' => $order->order_no,
                'business_line' => $order->business_line,
                'merchant_id' => (int) $order->merchant_id,
                'merchant_contact' => $merchant === null ? null : ($merchant->phone ?? $merchant->email),
                'supplier_id' => $order->supplier_id === null ? null : (int) $order->supplier_id,
                'supplier_name' => $suppliers->get((int) $order->supplier_id),
                'sale_price' => $this->money($order->sale_price),
                'cost_price' => $this->money($order->cost_price),
                'completed_at' => $order->completed_at?->toDateTimeString(),
                'supplier_rebate' => $supplierRebate,
                'merchant_rebate' => $order->merchant_rebate_amount === null ? null : $this->money($order->merchant_rebate_amount),
                'merchant_rebate_status' => $order->merchant_rebate_status,
                'merchant_rebate_rate' => $order->merchant_rebate_rate === null ? null : (string) $order->merchant_rebate_rate,
                'rebate_balance' => bcsub($supplierRebate ?? '0.00', $merchantRebatePaid, 2),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $query): array
    {
        $filters = [];

        $businessLine = $query['business_line'] ?? '';
        if ($businessLine !== '' && $businessLine !== null) {
            if (! in_array($businessLine, self::BUSINESS_LINES, true)) {
                throw new HttpException(422, 'business_line 只能是 movie 或 express');
            }
            $filters['business_line'] = $businessLine;
        }

        foreach (['supplier_id', 'merchant_id'] as $key) {
            $value = $query[$key] ?? '';
            if ($value === '' || $value === null) {
                continue;
            }
            if (! is_numeric($value) || (int) $value <= 0) {
                throw new HttpException(422, $key . ' 不合法');
            }
            $filters[$key] = (int) $value;
        }

        $orderNo = $query['order_no'] ?? null;
        if (is_string($orderNo) && trim($orderNo) !== '') {
            $filters['order_no'] = trim($orderNo);
        }

        $rebate = $query['rebate'] ?? '';
        if ($rebate !== '' && $rebate !== null) {
            if (! in_array($rebate, self::REBATE_FILTERS, true)) {
                throw new HttpException(422, 'rebate 只能是 returned 或 missing');
            }
            $filters['rebate'] = $rebate;
        }

        foreach (['completed_from' => '00:00:00', 'completed_to' => '23:59:59'] as $key => $timeOfDay) {
            $value = $query[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || strtotime($value) === false) {
                throw new HttpException(422, $key . ' 需要 YYYY-MM-DD 格式的日期');
            }
            $filters[$key] = $value . ' ' . $timeOfDay;
        }

        return $filters;
    }

    private function money(mixed $value): string
    {
        return bcadd($value === null || ! is_numeric($value) ? '0' : (string) $value, '0', 2);
    }
}
