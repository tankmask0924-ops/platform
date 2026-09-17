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

namespace App\Service\Supplier;

use App\Dao\SupplierDao;
use App\Job\RefreshSupplierBalanceJob;
use App\Model\Supplier;
use App\Service\AbstractService;
use App\Supplier\SupplierDriverFactory;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Coroutine\Parallel;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * 供应商余额监控（requirements.md 6.7）：通过驱动查询平台在各供应商的预存款，写回
 * `suppliers.balance` / `balance_synced_at`。路由按这个缓存值跳过余额不足的供应商
 * （App\Service\Order\SupplierRouter::eligibleCandidates()）。
 *
 * - 定时刷新所有启用中的供应商（App\Crontab\SupplierBalanceCrontab）；每条供应商记录
 *   各查各的，同一个实际账号配成多条记录也不合并（6.7 已确认的取舍）。
 * - 供应商返回预存款不足（卡速售状态 -1）时，路由异步触发一次单个供应商的刷新
 *   （App\Job\RefreshSupplierBalanceJob）。
 * - 查询失败只记日志，保留上一次的余额，不清空——清空会让路由把它当成"余额未知"
 *   继续分单。
 * - 低于预警阈值：告警表 `alerts` 是二期才建，目前先写 `supplier` 渠道 warning 日志，
 *   告警模块落地后改成写告警记录。
 */
class SupplierBalanceService extends AbstractService
{
    private const CONCURRENCY = 5;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected DriverFactory $queueDriverFactory;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * @return int 刷新成功的供应商数
     */
    public function refreshAll(): int
    {
        $parallel = new Parallel(self::CONCURRENCY);
        foreach ($this->supplierDao->listActive() as $supplier) {
            $parallel->add(fn () => $this->refresh($supplier));
        }

        return count(array_filter($parallel->wait(false)));
    }

    /**
     * 供应商报告预存款不足：告警财务，并异步立即刷新一次它的余额。
     */
    public function reportInsufficient(int $supplierId, ?string $context = null): void
    {
        $this->logger()->warning('supplier reported insufficient prepaid balance', [
            'supplier_id' => $supplierId,
            'context' => $context,
        ]);

        $this->queueDriverFactory->get('default')->push(new RefreshSupplierBalanceJob($supplierId));
    }

    public function refreshById(int $supplierId): bool
    {
        $supplier = $this->supplierDao->find($supplierId);

        return $supplier !== null && $this->refresh($supplier);
    }

    /**
     * @return bool 是否查到并写回了余额
     */
    public function refresh(Supplier $supplier): bool
    {
        try {
            $balance = $this->normalize($this->supplierDriverFactory->build($supplier)->queryBalance());
        } catch (Throwable $e) {
            $this->logger()->error('supplier balance query failed', [
                'supplier_id' => $supplier->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $supplier->fill([
            'balance' => $balance,
            'balance_synced_at' => date('Y-m-d H:i:s'),
        ])->save();

        $threshold = $supplier->balance_warning_threshold;
        if ($threshold !== null && bccomp($balance, (string) $threshold, 2) < 0) {
            $this->logger()->warning('supplier balance below warning threshold', [
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'balance' => $balance,
                'threshold' => (string) $threshold,
            ]);
        }

        return true;
    }

    /**
     * 驱动给的余额统一成两位小数字符串；不是普通十进制数（比如科学计数法、空串）
     * 当查询失败处理，不写入。
     */
    private function normalize(string $balance): string
    {
        $balance = trim($balance);
        if (preg_match('/^-?\d+(\.\d+)?$/', $balance) !== 1) {
            throw new RuntimeException('unparseable balance "' . $balance . '"');
        }

        return bcadd($balance, '0', 2);
    }

    private function logger(): LoggerInterface
    {
        return $this->loggerFactory->get('supplier');
    }
}
