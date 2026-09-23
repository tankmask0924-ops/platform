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

use App\Dao\MerchantBusinessSubscriptionDao;
use App\Dao\MerchantDao;
use App\Dao\MerchantQualificationDao;
use App\Model\MerchantBusinessSubscription;
use App\Service\AbstractService;
use App\Service\Merchant\SubscriptionService;
use Carbon\Carbon;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统管理后台「服务开通审核」（requirements.md 4.2、8.3）：审核商户的业务线开通申请。
 * 审核在事务里锁住申请行、重新确认仍是待审核，跟充值审核一样防止并发重复审核。
 * 通过后商户立刻能调用该业务线的商品查询和下单接口（开放 API 每次请求实时查）。
 */
class SubscriptionAdminService extends AbstractService
{
    private const STATUSES = ['pending', 'approved', 'rejected'];

    private const MAX_PER_PAGE = 100;

    #[Inject]
    protected MerchantBusinessSubscriptionDao $subscriptionDao;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantQualificationDao $qualificationDao;

    /**
     * @param array<string, mixed> $query status / business_line / merchant_id / page / per_page
     * @return array<string, mixed>
     */
    public function list(array $query): array
    {
        $filters = [];
        foreach (['status' => self::STATUSES, 'business_line' => SubscriptionService::BUSINESS_LINES] as $key => $allowed) {
            $value = $query[$key] ?? '';
            if ($value === '' || $value === null) {
                continue;
            }
            if (! in_array($value, $allowed, true)) {
                throw new HttpException(422, "{$key} 不合法");
            }
            $filters[$key] = $value;
        }
        if (isset($query['merchant_id']) && $query['merchant_id'] !== '') {
            if (! is_numeric($query['merchant_id'])) {
                throw new HttpException(422, 'merchant_id 不合法');
            }
            $filters['merchant_id'] = (int) $query['merchant_id'];
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($query['per_page'] ?? 15)));
        $rows = $this->subscriptionDao->paginateFiltered($filters, $page, $perPage);

        $merchantIds = $rows->pluck('merchant_id')->unique()->values()->all();
        $merchants = $this->merchantDao->findMany($merchantIds);
        // 列表里显示公司名/姓名，审核人员不用逐个点进商户详情
        $names = [];
        foreach ($merchantIds as $merchantId) {
            $qualification = $this->qualificationDao->findLatestByMerchantId($merchantId);
            $names[$merchantId] = $qualification?->company_name ?? $qualification?->id_card_name;
        }

        return [
            'data' => $rows->map(function (MerchantBusinessSubscription $row) use ($merchants, $names) {
                $merchant = $merchants->get($row->merchant_id);

                return [
                    'id' => $row->id,
                    'merchant_id' => $row->merchant_id,
                    'merchant_name' => $names[$row->merchant_id] ?? null,
                    'merchant_type' => $merchant?->type,
                    'merchant_phone' => $merchant?->phone,
                    'merchant_email' => $merchant?->email,
                    'merchant_status' => $merchant?->status,
                    'business_line' => $row->business_line,
                    'status' => $row->status,
                    'reject_reason' => $row->reject_reason,
                    'applied_at' => $row->applied_at?->toDateTimeString(),
                    'reviewed_by' => $row->reviewed_by,
                    'reviewed_at' => $row->reviewed_at?->toDateTimeString(),
                ];
            })->values()->all(),
            'total' => $this->subscriptionDao->countFiltered($filters),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function approve(int $id, int $reviewerId): void
    {
        Db::transaction(function () use ($id, $reviewerId) {
            $this->lockPendingOrFail($id)->fill([
                'status' => 'approved',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => Carbon::now(),
                'reject_reason' => null,
            ])->save();
        });
    }

    public function reject(int $id, mixed $reason, int $reviewerId): void
    {
        $reason = is_string($reason) ? trim($reason) : '';
        if ($reason === '') {
            throw new HttpException(422, '请填写驳回原因');
        }
        if (mb_strlen($reason) > 255) {
            throw new HttpException(422, '驳回原因不能超过 255 个字符');
        }

        Db::transaction(function () use ($id, $reason, $reviewerId) {
            $this->lockPendingOrFail($id)->fill([
                'status' => 'rejected',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => Carbon::now(),
                'reject_reason' => $reason,
            ])->save();
        });
    }

    /**
     * 调用方必须已经在事务里。
     */
    private function lockPendingOrFail(int $id): MerchantBusinessSubscription
    {
        $subscription = $this->subscriptionDao->lockForUpdate($id);
        if ($subscription === null) {
            throw new HttpException(404, '开通申请不存在');
        }
        if ($subscription->status !== 'pending') {
            throw new HttpException(409, '只有待审核的开通申请才能审核');
        }

        return $subscription;
    }
}
