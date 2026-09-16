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

use App\Model\Merchant;
use Hyperf\Database\Model\Collection;

class MerchantDao extends AbstractDao
{
    protected string $model = Merchant::class;

    public function find(int $id): ?Merchant
    {
        return Merchant::findFromCache($id);
    }

    /**
     * 按 app_key 查商户。app_key 不是主键，model-cache 只覆盖主键查询，
     * 这里走普通查询，不做额外缓存。
     */
    public function findByAppKey(string $appKey): ?Merchant
    {
        return $this->newQuery()->where('app_key', $appKey)->first();
    }

    /**
     * 事务内加行锁读商户账户，供 App\Service\Merchant\BalanceService 的
     * freeze/deduct/unfreeze 使用（requirements.md 4.5：并发下单要求先在事务里
     * 锁住商户账户再检查/变更余额，多笔订单排队依次处理）。不能用
     * find()/findFromCache()：那两个要么走缓存要么是无锁的普通查询，
     * 拿到的行在并发场景下不构成互斥，锁不住。调用方必须已经身处
     * Hyperf\DbConnection\Db::transaction() 里，否则 lockForUpdate() 不生效
     * （MySQL 的 SELECT ... FOR UPDATE 脱离事务毫无意义）。
     */
    public function lockForUpdate(int $id): ?Merchant
    {
        return $this->newQuery()->where('id', $id)->lockForUpdate()->first();
    }

    /**
     * 系统管理后台（web/admin）「商户列表」用（requirements.md 8.3），按创建时间倒序。
     *
     * 没有用 Hyperf\Database\Model\Builder::paginate()（会返回真正的
     * LengthAwarePaginator）——那个方法运行时依赖 `hyperf/paginator` 包
     * （composer.lock 里标注为"建议"依赖，实际没有被安装，见 composer.lock
     * 里 `hyperf/paginator` 那行的 suggest 注释），真调用会抛
     * `Class "Hyperf\Paginator\Paginator" not found`。补装这个新依赖超出本任务
     * 范围（只是要一个「分页 + 总数」的后台列表，不值得为此新增一个包依赖），
     * 所以跟 App\Dao\UserDao::paginate() 一样手写 forPage()+get()，
     * 只是多加一个 count() 方法来拿总数，两次查询自己组装成分页结果，
     * 效果等价于 LengthAwarePaginator 但不需要那个包。
     */
    public function paginate(int $page, int $perPage): Collection
    {
        return $this->newQuery()
            ->orderByDesc('created_at')
            ->forPage($page, $perPage)
            ->get();
    }

    public function count(): int
    {
        return $this->newQuery()->count();
    }
}
