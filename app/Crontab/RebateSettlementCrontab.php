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

namespace App\Crontab;

use App\Dao\MerchantRebateDao;
use App\Service\Merchant\BalanceService;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * requirements.md 5.4"入账"：扫描到期的待到账返佣记录（`status = pending AND
 * due_at <= now()`，`App\Dao\MerchantRebateDao::findDuePending()`），逐条加到
 * 商户可用余额、状态改成"已到账"（`App\Service\Merchant\BalanceService::
 * settleRebate()`）。跟本项目唯一现成的 `#[Crontab]` 范例
 * `App\Crontab\HeartbeatCrontab` 一样，这个注解本身不会自动被调度检查——真正
 * 定时触发依赖已经在跑的 `App\Process\CrontabDispatcherProcess`（不用动这里的
 * 进程注册）。
 *
 * 调度间隔选每 5 分钟一次（`*\/5 * * * *`）：requirements.md 没有规定具体频率，
 * 只要求"到期后由定时任务扫描入账"，5 分钟是"及时性"和"没必要跑太勤"之间的
 * 合理折中，不是需求原文写死的值。`onOneServer: true`（同 Heartbeat 范例）：
 * 避免多台部署同时跑这个任务重复扫描——虽然 `settleRebate()` 本身靠
 * `dedupe_rebate_key` 唯一索引已经保证了重复调用不会重复入账，但没必要让多台
 * 机器都去跑一遍无意义的重复查询。
 *
 * 【范围】只做"结算"这一半（`merchant_rebates.status` pending -> settled），
 * **不做**作废（争议到期前没真正完成）、扣回（争议处理后核实未到账/供应商全额
 * 退款）——这两个转换分别由售后争议处理流程、人工改判订单状态触发，两者在这个
 * 代码库里都还不存在，`status` 到 `voided`/`clawed_back` 的转换完全不在本类
 * 职责内，也没有任何代码路径会产生这两种状态。
 *
 * 【每条记录独立事务，不是整批一个大事务】`settleDueRebates()` 对
 * `findDuePending()` 查出的每一行分别调用一次 `BalanceService::settleRebate()`
 * （该方法内部自己 `Db::transaction()`），用 try/catch 包住每次调用——批次里
 * 某一条结算抛异常（比如理论上不该发生的"商户不存在"）只会被记日志跳过，不影响
 * 同批次其它记录的处理，不是"一条失败整批回滚"。
 */
#[Crontab(rule: '*/5 * * * *', name: 'RebateSettlement', memo: '返佣到期自动入账（requirements.md 5.4）', onOneServer: true)]
class RebateSettlementCrontab
{
    #[Inject]
    protected MerchantRebateDao $merchantRebateDao;

    #[Inject]
    protected BalanceService $balanceService;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    public function __invoke(): void
    {
        $this->settleDueRebates();
    }

    /**
     * 单独暴露成一个有返回值的公开方法，供测试直接调用（不用真的等一次 cron
     * tick）——跟 hyperf-conventions 里"crontab 没法/不该真的等一次 tick，改成
     * 直接测 `__invoke()` 调的方法"这条约定一致。`__invoke()` 本身只是调用它再
     * 丢弃返回值，两者是同一段逻辑，不是重复实现。
     *
     * @return int 这次调用真正新结算（不含幂等 no-op）的返佣记录数
     */
    public function settleDueRebates(): int
    {
        $dueRebates = $this->merchantRebateDao->findDuePending();

        $settledCount = 0;
        foreach ($dueRebates as $rebate) {
            try {
                if ($this->balanceService->settleRebate($rebate)) {
                    ++$settledCount;
                }
            } catch (Throwable $e) {
                $this->logger()->error(sprintf(
                    '[Crontab] RebateSettlement: failed to settle merchant_rebate #%d (order #%d): %s',
                    $rebate->id,
                    $rebate->order_id,
                    $e->getMessage()
                ));
            }
        }

        $this->logger()->info(sprintf(
            '[Crontab] RebateSettlement: settled %d/%d due pending rebates at %s',
            $settledCount,
            count($dueRebates),
            date('Y-m-d H:i:s')
        ));

        return $settledCount;
    }

    private function logger(): LoggerInterface
    {
        return $this->loggerFactory->get('crontab');
    }
}
