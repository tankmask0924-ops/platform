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

use App\Model\OrderRecharge;

class OrderRechargeDao extends AbstractDao
{
    protected string $model = OrderRecharge::class;

    /**
     * order_id 是 order_recharges 的主键，但不是自增主键，父类 AbstractDao::find()
     * 里的 $this->model::find($id) 语义上仍然按主键查，这里单独重写只是为了让方法名和
     * 返回类型跟这张表的语义（按 order_id 查一行）对齐，不涉及 model-cache。
     */
    public function find(int $orderId): ?OrderRecharge
    {
        return $this->newQuery()->where('order_id', $orderId)->first();
    }
}
