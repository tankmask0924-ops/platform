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

namespace HyperfTest\Cases\Service\Merchant;

use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantRebate;
use App\Service\Merchant\BalanceService;
use Carbon\Carbon;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Testing\TestCase;

/**
 * App\Service\Merchant\BalanceService 的 freeze/deduct/unfreeze（requirements.md
 * 4.4/4.5）。这三个方法都直接改 merchants 表的余额列、写 merchant_balance_logs
 * 流水，是这个代码库里「money-critical」的既有测试惯例（跟
 * test/Cases/Dao/MerchantDaoTest.php、OrderDaoTest.php 一样）：打真实配置的
 * MySQL，不 mock，每个测试自己建一个全新的 Merchant，tearDown 里连同流水一起清掉，
 * 不共用/不回收固定商户，避免测试之间互相污染余额状态。
 *
 * 真正的并发（多个请求同时对同一商户 freeze）没法在 PHPUnit 单进程里真实复现，
 * 这里没有伪造一个「看起来测了并发但其实是顺序调用」的测试——行锁本身的验证见
 * test/Cases/Dao/MerchantDaoTest.php::testLockForUpdateGeneratesForUpdateSql()
 * （编译出的 SQL 确实带 `for update`）。这里只测 BalanceService 三个方法在
 * 单线程下的正确性和幂等性，这也是 requirements.md 真正能在测试里断言到的部分。
 *
 * @internal
 * @coversNothing
 */
class BalanceServiceTest extends TestCase
{
    private array $merchantIds = [];

    private array $rebateIds = [];

    protected function tearDown(): void
    {
        // 欠款穿越场景的测试用 Carbon::setTestNow() 冻结/推进假时钟，测试结束
        // 必须重置回真实时钟，不然会污染同一进程里跑的其它测试。
        Carbon::setTestNow();

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

    public function testFreezeWithSufficientBalanceMovesMoneyAndLogsIt()
    {
        $service = $this->getContainer()->get(BalanceService::class);
        $merchant = $this->createMerchant('100.00', '0.00');
        $orderId = $this->uniqueOrderId();

        $result = $service->freeze($merchant->id, $orderId, '30.00');

        $this->assertTrue($result);

        $merchant->refresh();
        $this->assertSame('70.00', $merchant->available_balance);
        $this->assertSame('30.00', $merchant->frozen_balance);

        $log = MerchantBalanceLog::where('order_id', $orderId)->where('type', 'freeze')->first();
        $this->assertNotNull($log);
        $this->assertSame($merchant->id, $log->merchant_id);
        $this->assertSame('30.00', $log->amount);
        $this->assertSame('100.00', $log->available_before);
        $this->assertSame('70.00', $log->available_after);
        $this->assertSame('0.00', $log->frozen_before);
        $this->assertSame('30.00', $log->frozen_after);
    }

    public function testFreezeWithInsufficientBalanceIsRejectedWithoutSideEffects()
    {
        $service = $this->getContainer()->get(BalanceService::class);
        $merchant = $this->createMerchant('10.00', '0.00');
        $orderId = $this->uniqueOrderId();

        $result = $service->freeze($merchant->id, $orderId, '30.00');

        $this->assertFalse($result);

        $merchant->refresh();
        $this->assertSame('10.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);

        $this->assertSame(0, MerchantBalanceLog::where('order_id', $orderId)->count());
    }

    public function testFreezeWithExactlyEqualBalanceSucceeds()
    {
        $service = $this->getContainer()->get(BalanceService::class);
        $merchant = $this->createMerchant('30.00', '0.00');
        $orderId = $this->uniqueOrderId();

        $result = $service->freeze($merchant->id, $orderId, '30.00');

        $this->assertTrue($result);
        $merchant->refresh();
        $this->assertSame('0.00', $merchant->available_balance);
        $this->assertSame('30.00', $merchant->frozen_balance);
    }

    public function testDeductReducesOnlyFrozenBalanceAndLogsIt()
    {
        $service = $this->getContainer()->get(BalanceService::class);
        // 模拟「已经在下单时冻结过」的状态：可用余额已经减过，冻结余额里躺着这笔钱。
        $merchant = $this->createMerchant('70.00', '30.00');
        $orderId = $this->uniqueOrderId();

        $service->deduct($merchant->id, $orderId, '30.00');

        $merchant->refresh();
        $this->assertSame('70.00', $merchant->available_balance, 'deduct 不应该动可用余额');
        $this->assertSame('0.00', $merchant->frozen_balance);

        $log = MerchantBalanceLog::where('order_id', $orderId)->where('type', 'deduct')->first();
        $this->assertNotNull($log);
        $this->assertSame('30.00', $log->amount);
        $this->assertSame('70.00', $log->available_before);
        $this->assertSame('70.00', $log->available_after);
        $this->assertSame('30.00', $log->frozen_before);
        $this->assertSame('0.00', $log->frozen_after);
    }

    /**
     * 全任务里最重要的一条：同一笔订单重复 deduct，第二次必须是安全的 no-op——
     * 不抛异常、不重复插日志、更不能把冻结余额再扣一次变成负数。
     */
    public function testDeductTwiceForSameOrderIsIdempotent()
    {
        $service = $this->getContainer()->get(BalanceService::class);
        $merchant = $this->createMerchant('70.00', '30.00');
        $orderId = $this->uniqueOrderId();

        $service->deduct($merchant->id, $orderId, '30.00');
        $merchant->refresh();
        $this->assertSame('0.00', $merchant->frozen_balance);

        // 第二次调用：不应该抛异常。
        $service->deduct($merchant->id, $orderId, '30.00');

        $merchant->refresh();
        // 关键断言：冻结余额没有被第二次调用再扣一次（不是 -30.00）。
        $this->assertSame('0.00', $merchant->frozen_balance);
        $this->assertSame('70.00', $merchant->available_balance);

        // 关键断言：日志表里这个 order_id + deduct 组合只有一条，不是两条。
        $this->assertSame(1, MerchantBalanceLog::where('order_id', $orderId)->where('type', 'deduct')->count());
    }

    public function testUnfreezeIncreasesAvailableAndReducesFrozenAndLogsIt()
    {
        $service = $this->getContainer()->get(BalanceService::class);
        $merchant = $this->createMerchant('70.00', '30.00');
        $orderId = $this->uniqueOrderId();

        $service->unfreeze($merchant->id, $orderId, '30.00');

        $merchant->refresh();
        $this->assertSame('100.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);

        $log = MerchantBalanceLog::where('order_id', $orderId)->where('type', 'unfreeze')->first();
        $this->assertNotNull($log);
        $this->assertSame('30.00', $log->amount);
        $this->assertSame('70.00', $log->available_before);
        $this->assertSame('100.00', $log->available_after);
        $this->assertSame('30.00', $log->frozen_before);
        $this->assertSame('0.00', $log->frozen_after);
    }

    /**
     * 跟 deduct 的重复调用测试对称：同一笔订单重复 unfreeze 必须是安全的 no-op，
     * 不能把可用余额重复退回、冻结余额重复扣减到负数。
     */
    public function testUnfreezeTwiceForSameOrderIsIdempotent()
    {
        $service = $this->getContainer()->get(BalanceService::class);
        $merchant = $this->createMerchant('70.00', '30.00');
        $orderId = $this->uniqueOrderId();

        $service->unfreeze($merchant->id, $orderId, '30.00');
        $merchant->refresh();
        $this->assertSame('100.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);

        $service->unfreeze($merchant->id, $orderId, '30.00');

        $merchant->refresh();
        // 关键断言：可用余额没有被第二次调用再退一次（不是 130.00）。
        $this->assertSame('100.00', $merchant->available_balance);
        $this->assertSame('0.00', $merchant->frozen_balance);

        $this->assertSame(1, MerchantBalanceLog::where('order_id', $orderId)->where('type', 'unfreeze')->count());
    }

    public function testSettleRebateIncreasesAvailableBalanceOnlyAndLogsIt()
    {
        $service = $this->getContainer()->get(BalanceService::class);
        // 返佣从来没有被冻结过，模拟一个跟冻结余额无关的商户余额状态。
        $merchant = $this->createMerchant('70.00', '5.00');
        $orderId = $this->uniqueOrderId();
        $rebate = $this->createRebate($merchant->id, $orderId, '3.00');

        $result = $service->settleRebate($rebate);

        $this->assertTrue($result);

        $merchant->refresh();
        $this->assertSame('73.00', $merchant->available_balance, 'settleRebate 只应该增加可用余额');
        $this->assertSame('5.00', $merchant->frozen_balance, 'settleRebate 不应该动冻结余额');

        $rebate->refresh();
        $this->assertSame('settled', $rebate->status);
        $this->assertNotNull($rebate->settled_at);

        $log = MerchantBalanceLog::where('rebate_id', $rebate->id)->where('type', 'rebate_settle')->first();
        $this->assertNotNull($log);
        $this->assertSame($merchant->id, $log->merchant_id);
        $this->assertSame($orderId, $log->order_id);
        $this->assertSame('3.00', $log->amount);
        $this->assertSame('70.00', $log->available_before);
        $this->assertSame('73.00', $log->available_after);
        $this->assertSame('5.00', $log->frozen_before);
        $this->assertSame('5.00', $log->frozen_after);
    }

    /**
     * 跟 deduct/unfreeze 的重复调用测试同样的目的：同一条 rebate_id 重复
     * settleRebate()，第二次必须是安全的 no-op——不重复加余额，不抛异常，
     * 流水表里这条 rebate_id + rebate_settle 组合只有一条。
     */
    public function testSettleRebateTwiceForSameRebateIsIdempotent()
    {
        $service = $this->getContainer()->get(BalanceService::class);
        $merchant = $this->createMerchant('70.00', '0.00');
        $orderId = $this->uniqueOrderId();
        $rebate = $this->createRebate($merchant->id, $orderId, '3.00');

        $first = $service->settleRebate($rebate);
        $merchant->refresh();
        $this->assertTrue($first);
        $this->assertSame('73.00', $merchant->available_balance);

        // 第二次调用：不应该抛异常，也不应该再加一次钱。
        $second = $service->settleRebate($rebate);

        $this->assertFalse($second, '第二次调用应该识别出已经结算过，返回 false');

        $merchant->refresh();
        // 关键断言：可用余额没有被第二次调用再加一次（不是 76.00）。
        $this->assertSame('73.00', $merchant->available_balance);

        // 关键断言：日志表里这个 rebate_id + rebate_settle 组合只有一条，不是两条。
        $this->assertSame(1, MerchantBalanceLog::where('rebate_id', $rebate->id)->where('type', 'rebate_settle')->count());
    }

    public function testAdjustWithPositiveAmountIncreasesAvailableBalanceAndRecordsOperator()
    {
        $service = $this->getContainer()->get(BalanceService::class);
        $merchant = $this->createMerchant('100.00', '5.00');

        $service->adjust($merchant->id, '20.00', '快递理赔款', 42);

        $merchant->refresh();
        $this->assertSame('120.00', $merchant->available_balance);
        $this->assertSame('5.00', $merchant->frozen_balance, 'adjust 不应该动冻结余额');

        $log = MerchantBalanceLog::where('merchant_id', $merchant->id)->where('type', 'adjustment')->first();
        $this->assertNotNull($log);
        $this->assertSame('20.00', $log->amount);
        $this->assertSame('100.00', $log->available_before);
        $this->assertSame('120.00', $log->available_after);
        $this->assertSame('5.00', $log->frozen_before);
        $this->assertSame('5.00', $log->frozen_after);
        $this->assertSame('快递理赔款', $log->reason);
        $this->assertSame(42, $log->operator_id);
    }

    public function testAdjustWithNegativeAmountWithinBalanceDecreasesAvailableBalance()
    {
        $service = $this->getContainer()->get(BalanceService::class);
        $merchant = $this->createMerchant('100.00', '0.00');

        $service->adjust($merchant->id, '-30.00', '误充值冲正', 7);

        $merchant->refresh();
        $this->assertSame('70.00', $merchant->available_balance);

        $log = MerchantBalanceLog::where('merchant_id', $merchant->id)->where('type', 'adjustment')->first();
        $this->assertSame('-30.00', $log->amount);
        $this->assertSame('100.00', $log->available_before);
        $this->assertSame('70.00', $log->available_after);
        $this->assertSame(7, $log->operator_id);
    }

    /**
     * requirements.md 4.5「负余额」原文把"财务手动扣款调账"列为会让可用余额变成
     * 负数的三个穷举场景之一（另外两个是快递补扣、返佣扣回），处理规则明确
     * "不设下限，必须如实记账"——所以这里**不**校验"扣完是不是小于 0"，扣多少
     * 扣多少，如实记账，这是跟任务描述文字表述（"必须拒绝会让余额变负的调账"）
     * 刻意不一致的地方，以 requirements.md 原文为准，见
     * App\Service\Merchant\BalanceService::adjust() 方法文档注释里的完整推理。
     */
    public function testAdjustWithNegativeAmountExceedingBalanceStillAppliesAndRecordsNegativeBalance()
    {
        $service = $this->getContainer()->get(BalanceService::class);
        $merchant = $this->createMerchant('10.00', '0.00');

        $service->adjust($merchant->id, '-30.00', '财务手动扣款调账', 7);

        $merchant->refresh();
        $this->assertSame('-20.00', $merchant->available_balance);

        $log = MerchantBalanceLog::where('merchant_id', $merchant->id)->where('type', 'adjustment')->first();
        $this->assertNotNull($log);
        $this->assertSame('-30.00', $log->amount);
        $this->assertSame('10.00', $log->available_before);
        $this->assertSame('-20.00', $log->available_after);
    }

    public function testAdjustWithZeroAmountIsRejectedBeforeAnyWrite()
    {
        $service = $this->getContainer()->get(BalanceService::class);
        $merchant = $this->createMerchant('10.00', '0.00');

        try {
            $service->adjust($merchant->id, '0.00', '无意义调账', 7);
            $this->fail('0 金额调账应该被拒绝');
        } catch (HttpException $e) {
            $this->assertGreaterThanOrEqual(400, $e->getStatusCode());
            $this->assertLessThan(500, $e->getStatusCode());
        }

        $merchant->refresh();
        $this->assertSame('10.00', $merchant->available_balance);
        $this->assertSame(0, MerchantBalanceLog::where('merchant_id', $merchant->id)->count());
    }

    public function testAdjustWithInvalidAmountFormatIsRejectedBeforeAnyWrite()
    {
        $service = $this->getContainer()->get(BalanceService::class);
        $merchant = $this->createMerchant('10.00', '0.00');

        try {
            $service->adjust($merchant->id, 'not-a-number', '无意义调账', 7);
            $this->fail('格式不合法的金额应该被拒绝');
        } catch (HttpException $e) {
            $this->assertGreaterThanOrEqual(400, $e->getStatusCode());
            $this->assertLessThan(500, $e->getStatusCode());
        }

        $merchant->refresh();
        $this->assertSame('10.00', $merchant->available_balance);
        $this->assertSame(0, MerchantBalanceLog::where('merchant_id', $merchant->id)->count());
    }

    /**
     * requirements.md 4.5「负余额」：可用余额从 ≥0 变成 <0 那一刻，`debt_since`
     * 必须被设置成这次写入发生的时间——`adjust()` 的负数分支是三个会让余额变负
     * 的场景之一（另外两个：快递补扣、返佣扣回，都还没建），穿越 0 这条线的判断
     * 逻辑本身在 `BalanceService::persistBalance()`，这里通过 `adjust()` 验证它
     * 真的被接上了。用 `Carbon::setTestNow()` 冻结时钟，断言 `debt_since` 精确
     * 等于冻结的那个时间点，不是宽松地判断"不是 null"。
     */
    public function testAdjustCrossingFromNonNegativeToNegativeSetsDebtSince()
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00'));

        $service = $this->getContainer()->get(BalanceService::class);
        $merchant = $this->createMerchant('10.00', '0.00');
        $this->assertNull($merchant->debt_since);

        $service->adjust($merchant->id, '-30.00', '财务手动扣款调账', 7);

        $merchant->refresh();
        $this->assertSame('-20.00', $merchant->available_balance);
        $this->assertSame('2026-01-01 10:00:00', $merchant->debt_since);
    }

    /**
     * 跟上一条对称：可用余额从 <0 变回 ≥0（这里用 `recharge()`，覆盖除 `adjust()`
     * 之外另一个走 `persistBalance()` 的调用方）必须清空 `debt_since`。商户起始
     * 状态直接摆成"已经欠款"（不经过 `adjust()`，用 `fill()->save()` 直接布置初始
     * 状态，聚焦测试 `recharge()` 这次调用本身的穿越行为）。
     */
    public function testRechargeCrossingFromNegativeToNonNegativeClearsDebtSince()
    {
        $service = $this->getContainer()->get(BalanceService::class);
        $merchant = $this->createMerchant('-20.00', '0.00');
        $merchant->fill(['debt_since' => '2026-01-01 09:00:00'])->save();

        $service->recharge($merchant->id, '20.00', '充值补足欠款');

        $merchant->refresh();
        $this->assertSame('0.00', $merchant->available_balance);
        $this->assertNull($merchant->debt_since);
    }

    /**
     * 全篇最容易踩坑的一条：已经欠款的商户再被扣一次（比如同一天先后两笔手动
     * 扣款调账），`debt_since` 必须原样保留**第一次**进入欠款的时间，不能被
     * 第二次扣款刷新成更晚的时间——不然"欠了多久"这个信息就丢了。用
     * `Carbon::setTestNow()` 让两次调用之间真的经过一段可控的时间差
     * （而不是指望两次调用凑巧落在系统时钟的不同秒上），断言第二次调用之后
     * `debt_since` 精确等于第一次的时间戳，不是"仍然不是 null"这种弱断言。
     */
    public function testAdjustWhileAlreadyInDebtDoesNotResetExistingDebtSince()
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00'));

        $service = $this->getContainer()->get(BalanceService::class);
        $merchant = $this->createMerchant('10.00', '0.00');

        $service->adjust($merchant->id, '-30.00', '财务手动扣款调账', 7);
        $merchant->refresh();
        $firstDebtSince = $merchant->debt_since;
        $this->assertSame('2026-01-01 10:00:00', $firstDebtSince);

        Carbon::setTestNow(Carbon::parse('2026-01-01 10:05:00'));
        $service->adjust($merchant->id, '-5.00', '追加扣款', 7);

        $merchant->refresh();
        $this->assertSame('-25.00', $merchant->available_balance);
        $this->assertSame(
            $firstDebtSince,
            $merchant->debt_since,
            '已经在欠款状态时再扣一次不应该重置 debt_since'
        );
    }

    /**
     * requirements.md 4.5「负余额」暂停下单判断的唯一真实来源是 isSuspended()，
     * 断言它跟直接对 `available_balance` 做一次新鲜 bccomp 的结果一致——两个方向
     * 都验证：负数一律 true（哪怕只差一分钱），非负（含正好等于 0）一律 false。
     * 不读 `debt_since`：这里特意把 `debt_since` 摆成跟余额符号"不一致"的状态
     * （比如余额已经非负但 debt_since 还没来得及被清空的中间态），证明 isSuspended()
     * 真的只看 available_balance，没有偷偷读 debt_since 这一列。
     */
    public function testIsSuspendedAgreesWithFreshBccompOnAvailableBalanceBothDirections()
    {
        $service = $this->getContainer()->get(BalanceService::class);

        $negative = $this->createMerchant('-0.01', '0.00');
        $this->assertTrue($service->isSuspended($negative));

        $zero = $this->createMerchant('0.00', '0.00');
        $this->assertFalse($service->isSuspended($zero));

        $positive = $this->createMerchant('10.00', '0.00');
        $this->assertFalse($service->isSuspended($positive));

        // debt_since 跟余额符号故意摆成不一致的中间态：isSuspended() 不该被它带偏。
        $inconsistent = $this->createMerchant('5.00', '0.00');
        $inconsistent->fill(['debt_since' => '2026-01-01 08:00:00'])->save();
        $this->assertFalse(
            $service->isSuspended($inconsistent),
            'isSuspended() 必须只看 available_balance，不能被一个过期/不一致的 debt_since 带偏'
        );
    }

    private function createRebate(int $merchantId, int $orderId, string $amount): MerchantRebate
    {
        $rebate = MerchantRebate::create([
            'order_id' => $orderId,
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
            'due_at' => date('Y-m-d H:i:s'),
        ]);

        $this->rebateIds[] = $rebate->id;

        return $rebate;
    }

    private function createMerchant(string $availableBalance, string $frozenBalance): Merchant
    {
        $unique = uniqid('balance_service_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => 'encrypted-secret-placeholder',
            'available_balance' => $availableBalance,
            'frozen_balance' => $frozenBalance,
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function uniqueOrderId(): int
    {
        return random_int(100000000, 999999999);
    }
}
