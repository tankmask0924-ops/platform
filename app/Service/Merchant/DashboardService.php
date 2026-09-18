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

namespace App\Service\Merchant;

use App\Dao\MerchantRebateDao;
use App\Dao\OrderDao;
use App\Model\Merchant;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 商户后台首页统计（requirements.md 8.2「首页」）。余额、欠款提示在 /merchant/auth/me，这里只给统计。
 *
 * 口径（都按下单时间归到哪一天）：
 * - 订单数：当天下的所有订单，含处理中；
 * - 消费金额：实扣减去已退款（成功后又退款的不算消费），失败/取消的订单没有扣款；
 * - 成功率：成功 ÷（成功 + 失败 + 已退款），已退款是售后核实未到账的，算失败；
 *   处理中（含商户看不到的异常单）和商户取消的不计入，当天还没有出结果的订单时为 null。
 */
class DashboardService extends AbstractService
{
    public const TREND_DAYS = 7;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected MerchantRebateDao $rebateDao;

    /**
     * @return array<string, mixed>
     */
    public function stats(Merchant $merchant): array
    {
        $days = [];
        for ($i = self::TREND_DAYS - 1; $i >= 0; --$i) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $days[$day] = ['date' => $day, 'order_count' => 0, 'amount' => '0.00', 'success' => 0, 'finished' => 0];
        }

        $rows = $this->orderDao->dailySummaryForMerchant((int) $merchant->id, array_key_first($days) . ' 00:00:00');
        foreach ($rows as $row) {
            if (! isset($days[$row['day']])) {
                continue;
            }
            $day = &$days[$row['day']];
            $day['order_count'] += $row['count'];
            $day['amount'] = bcadd($day['amount'], bcsub($row['deducted'], $row['refunded'], 2), 2);
            if ($row['status'] === 'success') {
                $day['success'] += $row['count'];
            }
            if (in_array($row['status'], ['success', 'failed', 'refunded'], true)) {
                $day['finished'] += $row['count'];
            }
            unset($day);
        }

        $today = $days[date('Y-m-d')];

        return [
            'pending_rebate' => $this->rebateDao->sumPendingAmount((int) $merchant->id),
            'today' => [
                'order_count' => $today['order_count'],
                'amount' => $today['amount'],
                'success_count' => $today['success'],
                'finished_count' => $today['finished'],
                // 百分比，保留一位小数
                'success_rate' => $today['finished'] > 0 ? round($today['success'] * 100 / $today['finished'], 1) : null,
            ],
            'trend' => array_map(
                fn (array $day) => ['date' => $day['date'], 'order_count' => $day['order_count'], 'amount' => $day['amount']],
                array_values($days)
            ),
        ];
    }
}
