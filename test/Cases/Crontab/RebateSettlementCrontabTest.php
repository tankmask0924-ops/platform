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

namespace HyperfTest\Cases\Crontab;

use App\Crontab\RebateSettlementCrontab;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantRebate;
use Hyperf\Testing\TestCase;

/**
 * `App\Crontab\RebateSettlementCrontab` 没法/不该在测试里真的等一次 cron tick
 * 触发（hyperf-conventions 的既有约定：改测 `#[Crontab]` 类 `__invoke()` 实际
 * 调用的那个公开方法），这里直接调 `settleDueRebates()`。
 *
 * 真正的"批次里某一条结算失败不影响其它记录"没法在单进程 PHPUnit 里用一条
 * 真实会抛异常的记录可靠复现（`BalanceService::settleRebate()` 唯一会抛异常的
 * 分支是"商户不存在"，`merchant_rebates.merchant_id` 在这里测试环境下总能查到
 * 对应商户）——用每条记录独立 `Db::transaction()` + `try/catch` 包住每次循环
 * 调用这个代码结构本身来保证这条属性（见 `RebateSettlementCrontab::
 * settleDueRebates()`），不是靠这条测试断言出来的；这条测试只覆盖"到期/未到期
 * 过滤对不对"这个能可靠断言的部分。
 *
 * @internal
 * @coversNothing
 */
class RebateSettlementCrontabTest extends TestCase
{
    private array $merchantIds = [];

    private array $rebateIds = [];

    protected function tearDown(): void
    {
        foreach ($this->rebateIds as $id) {
            MerchantRebate::destroy($id);
        }
        $this->rebateIds = [];

        foreach ($this->merchantIds as $id) {
            MerchantBalanceLog::where('merchant_id', $id)->delete();
            Merchant::destroy($id);
        }
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testOnlyDueRebatesAreSettledNotYetDueOnesAreLeftPending()
    {
        $merchant = $this->createMerchant('100.00');

        $dueRebate = $this->createRebate($merchant->id, '3.00', '-1 minute');
        $notYetDueRebate = $this->createRebate($merchant->id, '5.00', '+1 day');

        $settledCount = $this->crontab()->settleDueRebates();

        $this->assertSame(1, $settledCount);

        $dueRebate->refresh();
        $this->assertSame('settled', $dueRebate->status);
        $this->assertNotNull($dueRebate->settled_at);

        $notYetDueRebate->refresh();
        $this->assertSame('pending', $notYetDueRebate->status, '还没到期的返佣记录不应该被这次扫描处理');
        $this->assertNull($notYetDueRebate->settled_at);

        $merchant->refresh();
        $this->assertSame('103.00', $merchant->available_balance, '只有到期的那条 3.00 应该被加进可用余额');
    }

    public function testRunningTwiceDoesNotDoubleSettleTheSameDueRebate()
    {
        $merchant = $this->createMerchant('100.00');
        $dueRebate = $this->createRebate($merchant->id, '3.00', '-1 minute');

        $first = $this->crontab()->settleDueRebates();
        $this->assertSame(1, $first);

        // 第二次扫描：这条记录已经是 settled，不会再出现在 findDuePending() 的结果里，
        // 这次应该扫到 0 条待处理，可用余额也不应该再被加一次。
        $second = $this->crontab()->settleDueRebates();
        $this->assertSame(0, $second);

        $merchant->refresh();
        $this->assertSame('103.00', $merchant->available_balance);
    }

    private function crontab(): RebateSettlementCrontab
    {
        return $this->getContainer()->get(RebateSettlementCrontab::class);
    }

    private function createMerchant(string $availableBalance): Merchant
    {
        $unique = uniqid('rebate_settlement_crontab_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => 'encrypted-secret-placeholder',
            'available_balance' => $availableBalance,
            'frozen_balance' => '0.00',
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createRebate(int $merchantId, string $amount, string $dueAtModifier): MerchantRebate
    {
        $rebate = MerchantRebate::create([
            'order_id' => random_int(100000000, 999999999),
            'merchant_id' => $merchantId,
            'business_line' => 'recharge',
            'level_id' => random_int(1, 999999),
            'rebate_base' => '5.00',
            'rebate_base_source' => 'product',
            'rebate_rate' => '0.6000',
            'rebate_rate_source' => 'level',
            'amount' => $amount,
            'status' => 'pending',
            'order_completed_at' => date('Y-m-d H:i:s'),
            'due_at' => date('Y-m-d H:i:s', strtotime($dueAtModifier)),
        ]);

        $this->rebateIds[] = $rebate->id;

        return $rebate;
    }
}
