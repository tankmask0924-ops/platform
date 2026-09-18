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

namespace App\Service\Product;

use App\Dao\MerchantDao;
use App\Dao\MerchantLevelDao;
use App\Dao\MerchantRebateDao;
use App\Dao\OrderDao;
use App\Dao\SystemSettingDao;
use App\Model\Merchant;
use App\Model\MerchantRebate;
use App\Service\AbstractService;
use App\Service\Order\OrderResultApplier;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户返佣明细查询（requirements.md 8.2「返佣」、8.3「返佣管理」），商户后台和系统后台共用。
 *
 * 商户看到的是订单、等级比例、金额、状态和预计到账时间；返佣基数（平台的商品返佣金额或
 * 供应商返佣）和比例来源属于平台内部数据，只在系统后台返回。
 * 汇总（summary）按当前筛选条件、不分页，按状态给出笔数和金额。
 */
class RebateQueryService extends AbstractService
{
    public const STATUSES = ['pending', 'settled', 'voided', 'clawed_back'];

    private const BUSINESS_LINES = ['recharge', 'card', 'movie', 'express'];

    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected MerchantRebateDao $rebateDao;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantLevelDao $levelDao;

    #[Inject]
    protected SystemSettingDao $systemSettingDao;

    /**
     * @param array<string, mixed> $query status / business_line / order_no / created_from / created_to / page / per_page
     * @return array<string, mixed>
     */
    public function listForMerchant(Merchant $merchant, array $query): array
    {
        $filters = $this->normalizeFilters($query);
        $filters['merchant_id'] = (int) $merchant->id;

        return $this->list($filters, $query, false);
    }

    /**
     * @param array<string, mixed> $query 同 listForMerchant()，另外支持 merchant_id
     * @return array<string, mixed>
     */
    public function listForAdmin(array $query): array
    {
        $filters = $this->normalizeFilters($query);
        if (isset($query['merchant_id']) && $query['merchant_id'] !== '') {
            if (! is_numeric($query['merchant_id'])) {
                throw new HttpException(422, 'merchant_id 不合法');
            }
            $filters['merchant_id'] = (int) $query['merchant_id'];
        }

        $result = $this->list($filters, $query, true);
        $result['due_period_days'] = (int) $this->systemSettingDao->getValue(
            OrderResultApplier::REBATE_DUE_PERIOD_SETTING_KEY,
            OrderResultApplier::DEFAULT_REBATE_DUE_PERIOD_DAYS
        );

        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function list(array $filters, array $query, bool $internal): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 15)));

        $rebates = $this->rebateDao->paginateFiltered($filters, $page, $perPage);
        $orders = $this->orderDao->newQuery()
            ->whereIn('id', $rebates->pluck('order_id')->unique()->all())
            ->get(['id', 'order_no', 'merchant_order_no', 'sale_price'])
            ->keyBy('id');
        $merchants = $internal
            ? $this->merchantDao->newQuery()->whereIn('id', $rebates->pluck('merchant_id')->unique()->all())->get(['id', 'phone', 'email'])->keyBy('id')
            : null;
        $levels = $internal ? $this->levelDao->all()->keyBy('id') : null;

        $summary = [];
        $counts = $this->rebateDao->summarizeByStatus($filters);
        foreach (self::STATUSES as $status) {
            $summary[$status] = $counts[$status] ?? ['count' => 0, 'amount' => '0.00'];
        }

        return [
            'data' => $rebates->map(function (MerchantRebate $rebate) use ($orders, $merchants, $levels, $internal) {
                $order = $orders->get($rebate->order_id);
                $row = [
                    'id' => $rebate->id,
                    'order_no' => $order?->order_no,
                    'merchant_order_no' => $order?->merchant_order_no,
                    'business_line' => $rebate->business_line,
                    'sale_price' => $order?->sale_price,
                    'rebate_rate' => $rebate->rebate_rate,
                    'amount' => $rebate->amount,
                    'status' => $rebate->status,
                    'order_completed_at' => $rebate->order_completed_at?->toDateTimeString(),
                    'due_at' => $rebate->due_at?->toDateTimeString(),
                    'settled_at' => $rebate->settled_at?->toDateTimeString(),
                    'voided_at' => $rebate->voided_at?->toDateTimeString(),
                    'clawed_back_at' => $rebate->clawed_back_at?->toDateTimeString(),
                    'created_at' => $rebate->created_at?->toDateTimeString(),
                ];
                if ($internal) {
                    $merchant = $merchants->get($rebate->merchant_id);
                    $row += [
                        'order_id' => $rebate->order_id,
                        'merchant_id' => $rebate->merchant_id,
                        'merchant_phone' => $merchant?->phone,
                        'merchant_email' => $merchant?->email,
                        'level_id' => $rebate->level_id,
                        'level_name' => $levels->get($rebate->level_id)?->name,
                        'rebate_base' => $rebate->rebate_base,
                        'rebate_base_source' => $rebate->rebate_base_source,
                        'rebate_rate_source' => $rebate->rebate_rate_source,
                    ];
                }

                return $row;
            })->values()->all(),
            'total' => $this->rebateDao->countFiltered($filters),
            'page' => $page,
            'per_page' => $perPage,
            'summary' => $summary,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $query): array
    {
        $filters = [];

        $status = $query['status'] ?? '';
        if ($status !== '' && $status !== null) {
            if (! in_array($status, self::STATUSES, true)) {
                throw new HttpException(422, 'status 不合法');
            }
            $filters['status'] = $status;
        }

        $businessLine = $query['business_line'] ?? '';
        if ($businessLine !== '' && $businessLine !== null) {
            if (! in_array($businessLine, self::BUSINESS_LINES, true)) {
                throw new HttpException(422, 'business_line 不合法');
            }
            $filters['business_line'] = $businessLine;
        }

        $orderNo = $query['order_no'] ?? null;
        if (is_string($orderNo) && trim($orderNo) !== '') {
            $filters['order_no'] = trim($orderNo);
        }

        // 只给日期时，开始取当天 0 点、结束取当天 23:59:59
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

        return $filters;
    }
}
