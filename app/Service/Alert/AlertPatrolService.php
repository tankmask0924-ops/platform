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

namespace App\Service\Alert;

use App\Dao\MerchantDao;
use App\Dao\OrderDao;
use App\Dao\SystemSettingDao;
use App\Model\Alert;
use App\Service\AbstractService;
use App\Service\Merchant\BalanceService;
use Carbon\Carbon;
use Hyperf\Di\Annotation\Inject;

/**
 * 靠巡检才能发现的两类告警（requirements.md 8.3「告警」），由 App\Crontab\AlertPatrolCrontab 定时调用：.
 *
 * - `merchant_debt_exceeded`（requirements.md 4.5「欠款超过该金额时告警财务」）：可用余额 < −欠款预警线的商户
 *   各报一条。用巡检而不是在余额变动时报：会把余额扣成负数的路径有补扣、返佣扣回、手动调账好几条，
 *   巡检一处就全覆盖，也不给 BalanceService 的资金事务多加一个副作用；晚几分钟发现不影响处理。
 * - `abnormal_order_backlog`：异常单笔数达到阈值（系统参数 `abnormal_order_backlog_threshold`，默认 10）
 *   报一条全局告警。异常单本身不告警——标成异常就是转人工，后台有列表；积压说明没人在处理，或者
 *   某个供应商成批出问题了。
 *
 * 告警按 `(type, related_type, related_id)` 去重（AlertService），持续超线只累加次数；
 * 回到线内不自动关闭，由运营标记处理。
 */
class AlertPatrolService extends AbstractService
{
    public const ABNORMAL_BACKLOG_THRESHOLD_SETTING_KEY = 'abnormal_order_backlog_threshold';

    public const DEFAULT_ABNORMAL_BACKLOG_THRESHOLD = 10;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected SystemSettingDao $systemSettingDao;

    #[Inject]
    protected BalanceService $balanceService;

    #[Inject]
    protected AlertService $alertService;

    /**
     * @return array{debt_merchants: int, abnormal_orders: int, abnormal_backlog: bool}
     */
    public function patrol(): array
    {
        $debtMerchants = $this->checkMerchantDebt();
        [$abnormalOrders, $backlog] = $this->checkAbnormalBacklog();

        return ['debt_merchants' => $debtMerchants, 'abnormal_orders' => $abnormalOrders, 'abnormal_backlog' => $backlog];
    }

    /**
     * @return int 欠款超线的商户数
     */
    public function checkMerchantDebt(): int
    {
        $threshold = $this->balanceService->debtWarningThreshold();
        $debtors = $this->merchantDao->debtBeyond($threshold);

        foreach ($debtors as $merchantId => $availableBalance) {
            $this->alertService->raise(
                Alert::TYPE_MERCHANT_DEBT_EXCEEDED,
                Alert::LEVEL_WARNING,
                sprintf('商户 #%d 欠款 %s 元，超过预警线 %s 元，已暂停下单，需联系充值', $merchantId, bcmul($availableBalance, '-1', 2), $threshold),
                'merchant',
                $merchantId
            );
        }

        return count($debtors);
    }

    /**
     * @return array{0: int, 1: bool} [当前异常单笔数, 是否达到阈值]
     */
    public function checkAbnormalBacklog(): array
    {
        $threshold = $this->abnormalBacklogThreshold();
        $backlog = $this->orderDao->abnormalBacklog();
        if ($backlog['count'] < $threshold) {
            return [$backlog['count'], false];
        }

        $message = sprintf('异常单积压 %d 笔（阈值 %d 笔）', $backlog['count'], $threshold);
        if ($backlog['oldest_created_at'] !== null) {
            $message .= sprintf('，最早一笔下单于 %s，已 %d 小时', $backlog['oldest_created_at'], Carbon::parse($backlog['oldest_created_at'])->diffInHours(Carbon::now()));
        }
        $this->alertService->raise(Alert::TYPE_ABNORMAL_ORDER_BACKLOG, Alert::LEVEL_WARNING, $message . '，请尽快人工处理');

        return [$backlog['count'], true];
    }

    public function abnormalBacklogThreshold(): int
    {
        $value = $this->systemSettingDao->getValue(self::ABNORMAL_BACKLOG_THRESHOLD_SETTING_KEY, self::DEFAULT_ABNORMAL_BACKLOG_THRESHOLD);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_ABNORMAL_BACKLOG_THRESHOLD;
    }
}
