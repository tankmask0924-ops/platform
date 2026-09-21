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

namespace App\Model;

use Carbon\Carbon;

/**
 * 供应商熔断状态（requirements.md 6.6），对应
 * migrations/2026_09_21_100000_create_supplier_circuit_breakers_table.php。
 * 判定、暂停、恢复的全部逻辑在 App\Service\Supplier\CircuitBreakerService。
 *
 * **`product_id = 0` 表示"整个供应商"**（`PRODUCT_ID_ALL`），非 0 表示只熔断这个
 * 供应商的这个商品（requirements.md 6.6「熔断可以精确到供应商 + 商品，避免一个商品
 * 出问题影响该供应商的其他商品」）。用 0 而不是 NULL 的原因见迁移文件注释：
 * MySQL 唯一索引不拦截重复的 NULL。
 *
 * **`paused_until = null` 且 `status = paused` 表示无限期暂停**，只有人工恢复才能解除；
 * 自动熔断永远会写一个截止时间（到期自动恢复，6.6），所以无限期只可能来自运营手动暂停。
 *
 * 一行一旦建出来就不删，恢复只是把 `status` 改回 `normal`——保留这一行才能在后台看到
 * "这个供应商/商品曾经熔断过、上次是什么原因"，删掉就只剩"从来没出过事"一种状态了。
 *
 * @property int $id
 * @property int $supplier_id
 * @property int $product_id
 * @property string $status
 * @property null|Carbon $paused_until
 * @property null|string $triggered_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SupplierCircuitBreaker extends Model
{
    /** 整个供应商（不特指某个商品）的熔断行 */
    public const PRODUCT_ID_ALL = 0;

    public const STATUS_NORMAL = 'normal';

    public const STATUS_PAUSED = 'paused';

    protected ?string $table = 'supplier_circuit_breakers';

    protected array $fillable = [
        'supplier_id',
        'product_id',
        'status',
        'paused_until',
        'triggered_reason',
    ];

    protected array $casts = [
        'supplier_id' => 'integer',
        'product_id' => 'integer',
    ];

    protected array $dates = [
        'paused_until',
    ];

    /**
     * 此刻是否真的在熔断中。**到期的行按"已恢复"算，不依赖定时任务先把它改回
     * `normal`**：定时任务最快也要一分钟一跑，中间这段时间路由不应该继续把这家
     * 供应商排除在外——暂停时长是 5 分钟就该是 5 分钟。定时任务只做把过期行写回
     * `normal` 的收尾（见 App\Crontab\CircuitBreakerRecoveryCrontab）。
     */
    public function isPausedNow(): bool
    {
        if ($this->status !== self::STATUS_PAUSED) {
            return false;
        }

        // 手动无限期暂停：没有截止时间，只有人工恢复
        return $this->paused_until === null || $this->paused_until->getTimestamp() > time();
    }
}
