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

namespace App\Service\Merchant;

use App\Dao\MerchantBalanceLogDao;
use App\Dao\OrderDao;
use App\Export\ExportLimit;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

use function Hyperf\Collection\collect;

/**
 * 商户管理后台（web/merchant）「资金流水：查询」（requirements.md 4.4/4.5、7.2），
 * docs/modules.md 第 7 节。只读——`App\Service\Merchant\BalanceService` 的
 * freeze/deduct/unfreeze/recharge/settleRebate/adjust 六个方法已经在各自的业务
 * 流程里把每次余额变动写进 `merchant_balance_logs`，这里第一次把这张表的数据
 * 读出来给商户自己看，不做任何写操作。
 *
 * IDOR 防护：跟 App\Service\Merchant\RechargeRequestService 同一套约定——
 * list() 强制按调用方传入的 `$merchant->id` 过滤，Controller 层只能传认证中间件
 * （MerchantAuthMiddleware）从 token 解出的那个商户模型，商户没有办法在请求里
 * 指定别的 merchant_id 去看别人的资金流水。
 *
 * 导出（requirements.md 7.2「查询与导出」的另一半）走 export()：同一套筛选条件、
 * 同一份 present()，只是不分页、带行数上限，见该方法注释。
 *
 * **每行带上关联订单**（平台单号、商户单号、业务线）：冻结、扣款、解冻、补扣、退款、返佣入账/扣回都挂在
 * 订单上，没有单号商户只能按时间去对。可以按单号（平台单号或商户单号都行）筛出一笔订单的全部流水。
 * 系统后台的商户资金流水（App\Service\Admin\MerchantAdminService::balanceLogs()）用同一个 present()。
 */
class BalanceLogService extends AbstractService
{
    #[Inject]
    protected MerchantBalanceLogDao $balanceLogDao;

    #[Inject]
    protected OrderDao $orderDao;

    /**
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(Merchant $merchant, int $page, int $perPage, mixed $type, mixed $orderNo = null): array
    {
        $type = $this->normalizeTypeFilter($type);
        $orderId = $this->resolveOrderFilter((int) $merchant->id, $orderNo);

        $logs = $this->balanceLogDao->paginateByMerchantId($merchant->id, $page, $perPage, $type, $orderId);

        return [
            'data' => $this->present($logs),
            'total' => $this->balanceLogDao->countByMerchantId($merchant->id, $type, $orderId),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 导出用：同一套筛选条件下的全部流水，不分页（requirements.md 7.2「支持筛选导出」）。
     *
     * **返回的是 JSON，不是 CSV 文件**：真正拼 CSV 的是前端（web/shared 的 `downloadCsv`）。
     * 这样枚举值的中文名（充值/冻结/扣款……）只在 `web/shared/src/labels.ts` 存一份，
     * 不用在 PHP 里再抄一份中文标签跟着前端一起漂——导出的列和页面上看到的列因此天然一致。
     * 代价是前端要把全部行拿进内存，由 ExportLimit::MAX_ROWS 兜底。
     *
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function export(Merchant $merchant, mixed $type, mixed $orderNo = null): array
    {
        $type = $this->normalizeTypeFilter($type);
        $orderId = $this->resolveOrderFilter((int) $merchant->id, $orderNo);

        $total = $this->balanceLogDao->countByMerchantId($merchant->id, $type, $orderId);
        ExportLimit::assertWithinLimit($total);

        $logs = $this->balanceLogDao->paginateByMerchantId($merchant->id, 1, ExportLimit::MAX_ROWS, $type, $orderId);

        return [
            'data' => $this->present($logs),
            'total' => $total,
        ];
    }

    /**
     * 按单号筛：平台单号、商户单号都认，只在这个商户自己的订单里找（不能借此探测别人的单号）。
     * 找不到返回 0（查出空列表），不报错。不传返回 null（不筛）。
     */
    public function resolveOrderFilter(int $merchantId, mixed $orderNo): ?int
    {
        if (! is_string($orderNo) || trim($orderNo) === '') {
            return null;
        }
        $orderNo = trim($orderNo);
        $order = $this->orderDao->findByOrderNoForMerchant($merchantId, $orderNo)
            ?? $this->orderDao->findByMerchantOrderNoForMerchant($merchantId, $orderNo);

        return $order === null ? 0 : (int) $order->id;
    }

    /**
     * 流水行 + 关联订单的单号和业务线。订单按这一页出现的 order_id 一次查出来。
     *
     * @param iterable<MerchantBalanceLog> $logs
     * @return list<array<string, mixed>>
     */
    public function present(iterable $logs): array
    {
        $orderIds = [];
        foreach ($logs as $log) {
            if ($log->order_id !== null) {
                $orderIds[(int) $log->order_id] = true;
            }
        }
        $orders = $orderIds === [] ? collect() : $this->orderDao->newQuery()
            ->whereIn('id', array_keys($orderIds))
            ->get(['id', 'order_no', 'merchant_order_no', 'business_line'])
            ->keyBy('id');

        $rows = [];
        foreach ($logs as $log) {
            $order = $log->order_id === null ? null : $orders->get((int) $log->order_id);
            $rows[] = [
                'id' => $log->id,
                'merchant_id' => $log->merchant_id,
                'type' => $log->type,
                'amount' => $log->amount,
                'available_before' => $log->available_before,
                'available_after' => $log->available_after,
                'frozen_before' => $log->frozen_before,
                'frozen_after' => $log->frozen_after,
                'order_id' => $log->order_id,
                'order_no' => $order?->order_no,
                'merchant_order_no' => $order?->merchant_order_no,
                'business_line' => $order?->business_line,
                'rebate_id' => $log->rebate_id,
                'reason' => $log->reason,
                'operator_id' => $log->operator_id,
                'created_at' => $log->created_at?->toDateTimeString(),
            ];
        }

        return $rows;
    }

    private function normalizeTypeFilter(mixed $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        if (! is_string($type) || ! in_array($type, MerchantBalanceLog::TYPES, true)) {
            throw new HttpException(422, 'type 不合法');
        }

        return $type;
    }
}
