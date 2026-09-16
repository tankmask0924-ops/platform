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
use App\Service\Merchant\BalanceService;
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

    protected function tearDown(): void
    {
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
