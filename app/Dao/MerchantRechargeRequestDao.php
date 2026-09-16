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

namespace App\Dao;

use App\Model\MerchantRechargeRequest;
use Hyperf\Database\Model\Collection;

class MerchantRechargeRequestDao extends AbstractDao
{
    protected string $model = MerchantRechargeRequest::class;

    /**
     * 商户后台「充值：查看记录」用，按创建时间倒序，严格限定在 $merchantId 下——
     * 调用方（App\Service\Merchant\RechargeRequestService）必须只传认证中间件解出的
     * 那个商户自己的 id，不接受商户传参指定别的 merchant_id，避免 IDOR。
     */
    public function paginateByMerchantId(int $merchantId, int $page, int $perPage): Collection
    {
        return $this->newQuery()
            ->where('merchant_id', $merchantId)
            ->orderByDesc('created_at')
            ->forPage($page, $perPage)
            ->get();
    }

    public function countByMerchantId(int $merchantId): int
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->count();
    }

    /**
     * 系统管理后台「充值审核」列表用，全部商户，可选按 status 过滤。
     * 跟 App\Dao\MerchantDao::paginate() 一样手写 forPage()+get()，不用
     * Hyperf\Database\Model\Builder::paginate()（依赖未安装的 hyperf/paginator，
     * 见 MerchantDao::paginate() 类注释里记录的原因）。
     */
    public function paginateForAdmin(int $page, int $perPage, ?string $status = null): Collection
    {
        $query = $this->newQuery();
        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('created_at')->forPage($page, $perPage)->get();
    }

    public function countForAdmin(?string $status = null): int
    {
        $query = $this->newQuery();
        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->count();
    }

    /**
     * 事务内加行锁读充值申请，供 App\Service\Admin\RechargeRequestAdminService 的
     * approve()/reject() 使用——审核动作需要「锁住这条申请行 + 重新检查
     * status === 'pending' + 原子翻转状态」来堵住两个并发审核请求都读到
     * pending 的竞态，形状跟 App\Dao\MerchantDao::lockForUpdate() 完全一致。
     * 调用方必须已经身处 Hyperf\DbConnection\Db::transaction() 里。
     */
    public function lockForUpdate(int $id): ?MerchantRechargeRequest
    {
        return $this->newQuery()->where('id', $id)->lockForUpdate()->first();
    }
}
