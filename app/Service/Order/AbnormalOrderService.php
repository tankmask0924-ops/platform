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

use App\Dao\OrderDao;
use App\Dao\SystemSettingDao;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;

/**
 * 异常单标记（requirements.md 7.1、7.4）：订单处理中超过异常单时长（所有业务线统一，
 * system_settings.abnormal_order_hours，默认 24 小时，从下单时间起算）仍没有结果，
 * 标成 `abnormal` 转人工。由 App\Crontab\AbnormalOrderCrontab 定时触发。
 *
 * - 余额保持冻结，不通知商户（7.6 只在成功/失败/取消/退款时通知）；开放 API 和商户
 *   回调里异常单仍显示为处理中，见 Order::merchantFacingStatus()。
 * - 标记后定时查询不再查它；迟到的回调/查询结果只记到尝试记录上供人工核实，不改订单、
 *   不切换供应商，见 SupplierRouter::applyAttemptResult()。
 * - 人工置成功/置失败、发起撤单属于后台"异常单处理"，异常单积压告警属于告警模块，
 *   都不在这里。
 */
class AbnormalOrderService extends AbstractService
{
    public const HOURS_SETTING_KEY = 'abnormal_order_hours';

    public const DEFAULT_HOURS = 24;

    public const BATCH_SIZE = 500;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected SystemSettingDao $systemSettingDao;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 一次调用最多标记 BATCH_SIZE 笔，积压更多时由后续调度继续处理。
     *
     * @return list<int> 这次被标记的订单 id
     */
    public function markOverdue(): array
    {
        $createdBefore = date('Y-m-d H:i:s', time() - $this->abnormalHours() * 3600);
        $marked = $this->orderDao->markAbnormalCreatedBefore($createdBefore, self::BATCH_SIZE);

        if ($marked !== []) {
            $this->loggerFactory->get('order')->warning('orders marked abnormal', [
                'count' => count($marked),
                'order_ids' => $marked,
                'created_before' => $createdBefore,
            ]);
        }

        return $marked;
    }

    public function abnormalHours(): int
    {
        $value = $this->systemSettingDao->getValue(self::HOURS_SETTING_KEY, self::DEFAULT_HOURS);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_HOURS;
    }
}
