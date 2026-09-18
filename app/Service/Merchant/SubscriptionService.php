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

use App\Dao\MerchantBusinessSubscriptionDao;
use App\Model\Merchant;
use App\Model\MerchantBusinessSubscription;
use App\Service\AbstractService;
use Carbon\Carbon;
use Hyperf\Database\Exception\QueryException;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 服务开通（requirements.md 4.2）：商户按业务线申请开通，平台审核通过后才能调用该业务线的
 * 商品查询和下单接口（开放 API 那边用 isSubscribed() 判断）。
 *
 * 每个商户每条业务线只有一行：驳回后重新申请是把那一行改回待审核，不新增行。
 * 只有资质审核通过（active）的商户能申请。电影票、快递三期才上线，现在列出来但不能申请。
 */
class SubscriptionService extends AbstractService
{
    public const BUSINESS_LINES = ['recharge', 'card', 'movie', 'express'];

    /** 已经能下单的业务线，其余的显示"暂未开放" */
    public const OPEN_BUSINESS_LINES = ['recharge', 'card'];

    #[Inject]
    protected MerchantBusinessSubscriptionDao $subscriptionDao;

    public function isSubscribed(int $merchantId, string $businessLine): bool
    {
        return $this->subscriptionDao->isApproved($merchantId, $businessLine);
    }

    /**
     * 全部业务线及本商户在每条上的开通状态（没申请过为 null）。
     *
     * @return list<array<string, mixed>>
     */
    public function list(Merchant $merchant): array
    {
        $subscriptions = $this->subscriptionDao->listByMerchantId((int) $merchant->id)->keyBy('business_line');

        return array_map(function (string $line) use ($subscriptions) {
            /** @var null|MerchantBusinessSubscription $subscription */
            $subscription = $subscriptions->get($line);

            return [
                'business_line' => $line,
                'available' => in_array($line, self::OPEN_BUSINESS_LINES, true),
                'status' => $subscription?->status,
                'reject_reason' => $subscription?->reject_reason,
                'applied_at' => $subscription?->applied_at?->toDateTimeString(),
                'reviewed_at' => $subscription?->reviewed_at?->toDateTimeString(),
            ];
        }, self::BUSINESS_LINES);
    }

    /**
     * 提交开通申请；被驳回过的重新申请。
     *
     * @return list<array<string, mixed>> 同 list()
     */
    public function apply(Merchant $merchant, mixed $businessLine): array
    {
        if (! is_string($businessLine) || ! in_array($businessLine, self::BUSINESS_LINES, true)) {
            throw new HttpException(422, 'business_line 不合法');
        }
        if (! in_array($businessLine, self::OPEN_BUSINESS_LINES, true)) {
            throw new HttpException(422, '该业务线暂未开放');
        }
        if ($merchant->status !== 'active') {
            throw new HttpException(409, '资质审核通过后才能申请开通业务线');
        }

        try {
            Db::transaction(function () use ($merchant, $businessLine) {
                $existing = $this->subscriptionDao->lockByMerchantAndLine((int) $merchant->id, $businessLine);
                if ($existing === null) {
                    $this->subscriptionDao->create([
                        'merchant_id' => $merchant->id,
                        'business_line' => $businessLine,
                        'status' => 'pending',
                        'applied_at' => Carbon::now(),
                    ]);
                    return;
                }
                if ($existing->status === 'approved') {
                    throw new HttpException(409, '该业务线已开通');
                }
                if ($existing->status === 'pending') {
                    throw new HttpException(409, '开通申请正在审核中');
                }
                $existing->fill([
                    'status' => 'pending',
                    'applied_at' => Carbon::now(),
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'reject_reason' => null,
                ])->save();
            });
        } catch (QueryException $e) {
            // 两个首次申请同时到达：锁不住还不存在的行，靠 (merchant_id, business_line) 唯一索引兜底
            throw new HttpException(409, '开通申请正在审核中', 0, $e);
        }

        return $this->list($merchant);
    }
}
