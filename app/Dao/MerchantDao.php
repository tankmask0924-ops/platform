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
}
