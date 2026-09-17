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

use App\Model\MerchantLevel;
use Hyperf\Database\Model\Collection;

/**
 * create/update 直接用 AbstractDao 自带的通用实现，这里只补按名称查询和全量列表。
 */
class MerchantLevelDao extends AbstractDao
{
    protected string $model = MerchantLevel::class;

    public function find(int $id): ?MerchantLevel
    {
        return MerchantLevel::find($id);
    }

    /**
     * 名称唯一性预检查用（merchant_levels.name 有唯一索引，真正的防线在数据库）。
     */
    public function findByName(string $name): ?MerchantLevel
    {
        return $this->newQuery()->where('name', $name)->first();
    }

    /**
     * 系统管理后台「商户等级 - 列表」用，全量不分页：等级是运营手工创建的
     * 少量配置行（普通/银牌/金牌这一量级），不是会持续增长的业务数据。
     * 按 id 正序，即创建顺序。
     */
    public function all(): Collection
    {
        return $this->newQuery()->orderBy('id')->get();
    }
}
