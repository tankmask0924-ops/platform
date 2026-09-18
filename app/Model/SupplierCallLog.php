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
 * 调用供应商接口的请求/响应日志（requirements.md 6.8），由 App\Service\Supplier\SupplierCallLogService 写入，
 * 卡号卡密落库前已打码。只有 created_at。
 *
 * @property int $id
 * @property int $supplier_id
 * @property null|int $order_id 查余额、同步商品等跟订单无关的调用为空
 * @property string $action place_order/query/query_balance/goods_detail/goods_list
 * @property array $request
 * @property null|array $response
 * @property null|int $duration_ms
 * @property Carbon $created_at
 */
class SupplierCallLog extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'supplier_call_logs';

    protected array $fillable = [
        'supplier_id',
        'order_id',
        'action',
        'request',
        'response',
        'duration_ms',
        'created_at',
    ];

    protected array $casts = [
        'request' => 'array',
        'response' => 'array',
    ];

    protected array $dates = [
        'created_at',
    ];
}
