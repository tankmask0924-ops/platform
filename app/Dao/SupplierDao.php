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

use App\Model\Supplier;
use Hyperf\Database\Model\Collection;

class SupplierDao extends AbstractDao
{
    protected string $model = Supplier::class;

    public function find(int $id): ?Supplier
    {
        return Supplier::find($id);
    }

    /**
     * 按编码查供应商——供应商编码是未来 `/notify/{code}` 回调路由分发要用的稳定标识
     * （见 requirements.md 6.3 + docs/modules.md 第 1 节「供应商回调入口与验签框架」，
     * 那个功能本身还没建，这里先把查询方法建好），也用于创建时的唯一性预检查。
     */
    public function findByCode(string $code): ?Supplier
    {
        return $this->newQuery()->where('code', $code)->first();
    }

    /**
     * 系统管理后台（web/admin）「供应商管理 - 列表」用，按创建时间倒序。
     *
     * 跟 App\Dao\MerchantDao::paginate() 同样的手写分页原因：`hyperf/paginator`
     * 没有被安装（见 MerchantDao 类注释），这里复用同一套 forPage()+count() 写法。
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
