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

use App\Dao\AlertDao;
use App\Model\Alert;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 告警产生（requirements.md 8.3「告警」，database-design.md 4.14）。全平台**唯一**
 * 写 `alerts` 表的地方，所有检测点都调 `raise()`。
 *
 * **去重是这个服务存在的主要理由**：同一 `(type, related_type, related_id)` 已经有一条
 * `open` 的告警时不再插新行，只更新最近触发时间、内容和 `occurrence_count`
 * （database-design.md 4.14）。供应商余额持续走低时定时任务每 5 分钟命中一次同一条，
 * 没有去重的话后台一天就会被刷出几百条一模一样的记录，真正的新问题反而被淹掉。
 * 处理或忽略之后再触发，才算新的一条——这正是"这个问题又回来了"该有的信号。
 *
 * **`raise()` 永不抛**：调用方都是下单链路、定时任务这种"告警只是副作用"的地方，
 * 告警写不进去最多是少一条记录，不能反过来把主流程搞挂。写失败记 error 日志。
 *
 * **每条告警同时记一条日志**：后台列表是给运营看的，日志是给排查问题的人看的，
 * 两边都要有；而且告警表是二期才建的，在此之前各检测点本来就在记日志，接入告警时
 * 保留日志不丢历史连续性。
 *
 * 7 个 type 当前的产生方：
 * - `supplier_low_balance`：`App\Service\Supplier\SupplierBalanceService`（定时刷新后低于
 *   预警线、以及供应商返回"预存款不足"时，requirements.md 6.7）✅
 * - `supplier_circuit_broken` / `product_fail_rate_spike`：`App\Service\Supplier\CircuitBreakerService`
 *   熔断触发时，整家熔断报前者、单商品熔断报后者（requirements.md 6.6）✅
 * - `abnormal_order_backlog`：需要"积压多少算多"的阈值和一个巡检点，检测链路未建 ⬜
 * - `supplier_refund_after_success`：`App\Service\Order\SupplierRefundAfterSuccessService`（成功订单的卡速售回调、
 *   后台「查询供应商」发现全额/部分退款时，requirements.md 7.1）✅
 * - `rebate_loss`：requirements.md 5.5 的保护提示目前只在前端算，后端没有检测点 ⬜
 * - `merchant_debt_exceeded`：`BalanceService::isOverDebtWarningThreshold()` 只是个读取端
 *   判断，真正要告警需要在余额变动后或用巡检任务去触发，未建 ⬜
 */
class AlertService extends AbstractService
{
    private const MAX_MESSAGE_LENGTH = 255;

    #[Inject]
    protected AlertDao $alertDao;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 报一条告警。已有未处理的同类同对象告警时只累加，不新插。
     *
     * @param string $type Alert::TYPE_*
     * @param null|string $relatedType supplier/product/merchant/order，null = 全局告警
     */
    public function raise(
        string $type,
        string $level,
        string $message,
        ?string $relatedType = null,
        ?int $relatedId = null
    ): void {
        $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);

        $this->loggerFactory->get('alert')->warning('alert raised', [
            'type' => $type,
            'level' => $level,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'message' => $message,
        ]);

        try {
            $existing = $this->alertDao->findOpen($type, $relatedType, $relatedId);
            if ($existing !== null) {
                $this->alertDao->touchOccurrence($existing, $message, $level);

                return;
            }

            $now = date('Y-m-d H:i:s');
            $this->alertDao->create([
                'type' => $type,
                'level' => $level,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'message' => $message,
                'status' => Alert::STATUS_OPEN,
                'occurrence_count' => 1,
                'triggered_at' => $now,
            ]);
        } catch (Throwable $e) {
            // 并发下两个调用可能都没查到 open 行、都去插，第二条插进去就是一条重复记录；
            // 没有为此加唯一索引：去重键里 related_id 可空，MySQL 唯一索引不拦 NULL
            // （跟 supplier_circuit_breakers 用 0 当哨兵是同一个坑），而这里的代价只是
            // 偶尔多一条重复告警，远没到值得为它改表结构的程度。真出异常只记日志。
            $this->loggerFactory->get('alert')->error('alert write failed', [
                'type' => $type,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
