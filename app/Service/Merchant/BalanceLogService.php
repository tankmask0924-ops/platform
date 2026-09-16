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
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

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
 * 导出（requirements.md 7.2 提到的"查询与导出"的另一半）不在本次任务范围内，
 * 这里只做"支持筛选"这部分。
 */
class BalanceLogService extends AbstractService
{
    #[Inject]
    protected MerchantBalanceLogDao $balanceLogDao;

    /**
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(Merchant $merchant, int $page, int $perPage, mixed $type): array
    {
        $type = $this->normalizeTypeFilter($type);

        $logs = $this->balanceLogDao->paginateByMerchantId($merchant->id, $page, $perPage, $type);

        return [
            'data' => $logs->map(fn (MerchantBalanceLog $log) => $this->format($log))->values()->all(),
            'total' => $this->balanceLogDao->countByMerchantId($merchant->id, $type),
            'page' => $page,
            'per_page' => $perPage,
        ];
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

    /**
     * @return array<string, mixed>
     */
    private function format(MerchantBalanceLog $log): array
    {
        return [
            'id' => $log->id,
            'merchant_id' => $log->merchant_id,
            'type' => $log->type,
            'amount' => $log->amount,
            'available_before' => $log->available_before,
            'available_after' => $log->available_after,
            'frozen_before' => $log->frozen_before,
            'frozen_after' => $log->frozen_after,
            'order_id' => $log->order_id,
            'rebate_id' => $log->rebate_id,
            'reason' => $log->reason,
            'operator_id' => $log->operator_id,
            'created_at' => $log->created_at?->toDateTimeString(),
        ];
    }
}
