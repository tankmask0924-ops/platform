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

namespace HyperfTest\Cases\Dao;

use App\Dao\MerchantRebateDao;
use App\Model\MerchantRebate;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class MerchantRebateDaoTest extends TestCase
{
    private array $rebateIds = [];

    protected function tearDown(): void
    {
        foreach ($this->rebateIds as $id) {
            MerchantRebate::destroy($id);
        }
        $this->rebateIds = [];

        parent::tearDown();
    }

    public function testSumPendingAmountReturnsZeroWhenNoRows()
    {
        $dao = $this->getContainer()->get(MerchantRebateDao::class);

        $this->assertSame('0.00', $dao->sumPendingAmount(999999999));
    }

    public function testSumPendingAmountOnlySumsPendingStatus()
    {
        $merchantId = random_int(100000000, 999999999);

        $this->createRebate($merchantId, '10.00', 'pending');
        $this->createRebate($merchantId, '5.50', 'pending');
        $this->createRebate($merchantId, '100.00', 'settled');
        $this->createRebate($merchantId, '50.00', 'voided');

        $dao = $this->getContainer()->get(MerchantRebateDao::class);

        $this->assertSame('15.50', $dao->sumPendingAmount($merchantId));
    }

    private function createRebate(int $merchantId, string $amount, string $status): MerchantRebate
    {
        static $orderIdSeq = 0;
        ++$orderIdSeq;

        $rebate = MerchantRebate::create([
            'order_id' => (int) (microtime(true) * 1000000) + $orderIdSeq,
            'merchant_id' => $merchantId,
            'business_line' => 'mobile_recharge',
            'level_id' => 1,
            'rebate_base' => $amount,
            'rebate_base_source' => 'product',
            'rebate_rate' => '1.0000',
            'rebate_rate_source' => 'level',
            'amount' => $amount,
            'status' => $status,
        ]);

        $this->rebateIds[] = $rebate->id;

        return $rebate;
    }
}
