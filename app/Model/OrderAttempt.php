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
 * 一次下单尝试（requirements.md 6.5「固定优先级路由」+ 6.2「统一结果」），对应
 * migrations/2026_09_14_091900_create_order_attempts_table.php。一个 `Order`
 * 在路由过程中每实际调用一次供应商驱动的 `placeOrder()` 就落一行，`attempt_no`
 * 同一订单内从 1 递增（迁移里 `(order_id, attempt_no)` 唯一），由
 * `App\Service\Order\SupplierRouter` 负责编号和写入。
 *
 * `fail_reason` 是供应商/驱动给的原始原因，可能很长（比如驱动构造失败的异常信息），
 * 写入时截断到列宽 255，避免排障信息反过来让下单请求失败。
 *
 * `result` 是 `App\Supplier\UnifiedResult` 四态到字符串的映射，映射关系（也是
 * 本类文档的一部分，改动请同步这里）：
 *   UnifiedResult::Success        -> 'success'
 *   UnifiedResult::DefiniteFailure -> 'failed'
 *   UnifiedResult::Processing     -> 'processing'
 *   UnifiedResult::Unknown        -> 'unknown'
 *
 * `request_snapshot`/`response_snapshot` 直接存 `App\Supplier\DriverResult` 的
 * `rawRequest`/`rawResponse`，原样落库，不做二次加工——排障时需要看到驱动真正
 * 发出/收到的内容。
 *
 * @property int $id
 * @property int $order_id
 * @property int $supplier_id
 * @property int $attempt_no
 * @property string $result
 * @property null|string $fail_reason
 * @property null|array $request_snapshot
 * @property null|array $response_snapshot
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OrderAttempt extends Model
{
    private const FAIL_REASON_MAX_LENGTH = 255;

    protected ?string $table = 'order_attempts';

    protected array $fillable = [
        'order_id',
        'supplier_id',
        'attempt_no',
        'result',
        'fail_reason',
        'request_snapshot',
        'response_snapshot',
    ];

    protected array $casts = [
        'request_snapshot' => 'array',
        'response_snapshot' => 'array',
    ];

    public function setFailReasonAttribute(?string $value): void
    {
        $this->attributes['fail_reason'] = $value === null ? null : mb_substr($value, 0, self::FAIL_REASON_MAX_LENGTH);
    }
}
