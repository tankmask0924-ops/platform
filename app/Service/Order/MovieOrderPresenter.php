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

namespace App\Service\Order;

use App\Dao\OrderMovieDao;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 电影票订单对商户展示的明细：锁座、确认出票、释放座位的返回和订单查询都用这一份。
 * 只给每张售价 `unit_price`，不给成本和供应商返佣（5.5「商户看不到成本、供应商返佣」）。
 */
class MovieOrderPresenter extends AbstractService
{
    #[Inject]
    protected OrderMovieDao $orderMovieDao;

    /**
     * @return null|array<string, mixed>
     */
    public function present(int $orderId): ?array
    {
        $movie = $this->orderMovieDao->findByOrderId($orderId);
        if ($movie === null) {
            return null;
        }

        return [
            'cinema_id' => $movie->cinema_id,
            'cinema_name' => $movie->cinema_name,
            'film_id' => $movie->film_id,
            'film_name' => $movie->film_name,
            'show_id' => $movie->show_id,
            'show_time' => $movie->show_time->toDateTimeString(),
            'area_id' => $movie->area_id,
            'seats' => array_map(static fn (array $seat) => [
                'seat_code' => $seat['seat_code'],
                'row_label' => $seat['row_label'] ?? null,
                'col_label' => $seat['col_label'] ?? null,
                'love_status' => $seat['love_status'] ?? 0,
            ], $movie->seats),
            'seat_count' => $movie->seat_count,
            'unit_price' => $movie->unit_price,
            'mobile' => $movie->mobile,
            'lock_expire_at' => $movie->lock_expire_at->toDateTimeString(),
            'confirmed_at' => $movie->confirmed_at?->toDateTimeString(),
            'ticket_codes' => $movie->ticket_codes ?? [],
        ];
    }
}
