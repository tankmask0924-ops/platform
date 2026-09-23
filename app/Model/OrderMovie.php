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
 * 电影票订单明细（requirements.md 7.3，database-design.md 4.7），一笔订单一行，主键就是 `order_id`。
 *
 * `cinema_id`/`film_id` 是**查询时用的 ID**（芒果场次记录自带的那两个不可信，mango.md 第 1 节）。
 * `unit_price`/`unit_cost` 是锁座时的每张售价/成本快照：订单上的 `sale_price`/`cost_price` 是乘以张数的合计，
 * 商户看到的每张价格以这里为准（5.1 电影票按张计价）。
 * `confirmed_at` 为空表示商户还没确认出票，锁座到期（`lock_expire_at`）要释放座位、解冻；
 * 确认之后就不会再超时，等出票结果。
 *
 * @property int $order_id
 * @property string $cinema_id
 * @property null|string $cinema_name
 * @property string $film_id
 * @property null|string $film_name
 * @property string $show_id
 * @property Carbon $show_time
 * @property null|string $area_id
 * @property array $seats
 * @property int $seat_count
 * @property string $unit_price
 * @property string $unit_cost
 * @property string $mobile
 * @property Carbon $lock_expire_at
 * @property null|Carbon $confirmed_at
 * @property null|array $ticket_codes
 * @property null|string $supplier_rebate
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OrderMovie extends Model
{
    public bool $incrementing = false;

    protected ?string $table = 'order_movies';

    protected string $primaryKey = 'order_id';

    protected array $fillable = [
        'order_id', 'cinema_id', 'cinema_name', 'film_id', 'film_name', 'show_id', 'show_time', 'area_id',
        'seats', 'seat_count', 'unit_price', 'unit_cost', 'mobile', 'lock_expire_at', 'confirmed_at',
        'ticket_codes', 'supplier_rebate',
    ];

    protected array $casts = [
        'seats' => 'array',
        'ticket_codes' => 'array',
        'seat_count' => 'integer',
    ];

    protected array $dates = ['show_time', 'lock_expire_at', 'confirmed_at'];
}
