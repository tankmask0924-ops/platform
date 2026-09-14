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
 * 每次「平台 → 商户」结果回调的请求/响应都写一条，写完不再更新（只有 created_at，
 * 没有 updated_at），跟 App\Model\OrderRecharge 关掉自增/时间戳是同一类坑：这里
 * 关掉的是 Eloquent 默认的 created_at/updated_at 自动维护（$timestamps = false），
 * created_at 需要在写入时自己传值（见 App\Dao\MerchantNotifyLogDao）。
 *
 * payload 里不放 card_no/card_pwd 等卡密字段（结果通知只需要告诉商户订单状态，
 * 卡密走专门鉴权的订单查询接口），所以这里没有额外的"打码"逻辑，见
 * App\Job\NotifyMerchantJob 的类注释。
 *
 * @property int $id
 * @property int $order_id
 * @property string $url
 * @property array $payload
 * @property null|string $response_body
 * @property null|int $http_status
 * @property int $attempt_no
 * @property bool $success
 * @property Carbon $created_at
 */
class MerchantNotifyLog extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'merchant_notify_logs';

    protected array $fillable = [
        'order_id',
        'url',
        'payload',
        'response_body',
        'http_status',
        'attempt_no',
        'success',
        'created_at',
    ];

    protected array $casts = [
        'payload' => 'array',
        'success' => 'bool',
    ];

    protected array $dates = [
        'created_at',
    ];
}
