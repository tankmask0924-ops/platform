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

use App\Model\ProductLevelRebate;
use Hyperf\Database\Model\Collection;

class ProductLevelRebateDao extends AbstractDao
{
    protected string $model = ProductLevelRebate::class;

    /**
     * 按 (product_id, level_id) 查商品对某个等级单独设置的返佣比例覆盖
     * （requirements.md 5.3 返佣比例取法第 1 步），没有单独设置时返回 null。
     */
    public function findForProductAndLevel(int $productId, int $levelId): ?ProductLevelRebate
    {
        return $this->newQuery()
            ->where('product_id', $productId)
            ->where('level_id', $levelId)
            ->first();
    }

    /**
     * 某个本地商品已单独设置的全部等级比例覆盖（requirements.md 5.2「一个商品可以
     * 只给部分等级单独设置比例」），后台详情页展示用，按 level_id 排序。
     */
    public function listForProduct(int $productId): Collection
    {
        return $this->newQuery()
            ->where('product_id', $productId)
            ->orderBy('level_id')
            ->get();
    }

    /**
     * 设置（新建或原地更新）某商品对某等级单独设置的返佣比例：没有行就插入，已有行
     * 就原地更新 rebate_rate。跟 App\Dao\MerchantLevelBusinessRateDao::upsertRate()
     * 同样的原生 upsert 技术（MySQL 下编译成 INSERT ... ON DUPLICATE KEY UPDATE，
     * 冲突判定靠 `(product_id, level_id)` 唯一索引），单条语句原子完成，不需要
     * 先查后写再 catch QueryException 的兜底，也不会自动维护时间戳，所以手动带上
     * created_at/updated_at，冲突时只更新 rebate_rate/updated_at，保留原来的
     * created_at。
     */
    public function upsertRate(int $productId, int $levelId, string $rebateRate): ProductLevelRebate
    {
        $now = date('Y-m-d H:i:s');

        $this->newQuery()->upsert(
            [[
                'product_id' => $productId,
                'level_id' => $levelId,
                'rebate_rate' => $rebateRate,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['product_id', 'level_id'],
            ['rebate_rate', 'updated_at']
        );

        return $this->findForProductAndLevel($productId, $levelId);
    }

    /**
     * 删除某商品对某等级单独设置的比例覆盖（requirements.md 5.2「没设置的等级继续
     * 用等级比例」）：删除后 App\Service\Product\RebateCalculator 会自动回退到
     * 该等级在该业务线的默认比例，不需要额外处理。调用方（ProductAdminService）
     * 负责在删除前确认行确实存在，这里只负责删，返回值给调用方兜底判断。
     */
    public function deleteForProductAndLevel(int $productId, int $levelId): bool
    {
        return $this->newQuery()
            ->where('product_id', $productId)
            ->where('level_id', $levelId)
            ->delete() > 0;
    }
}
