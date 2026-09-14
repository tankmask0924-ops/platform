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

use App\Dao\OrderDao;
use App\Model\Merchant;
use App\Model\Order;
use Hyperf\Testing\TestCase;

/**
 * Dao 层直接单测，专门盯住「按商户维度限定查询」这一条（跟
 * test/Cases/OpenApi/OrderControllerTest.php 的跨商户 HTTP 测试互为补充：
 * 这里验证 OrderDao 本身查询条件写对了，那边验证整条链路真的用了这个方法、
 * 没有在别处又绕开限定条件）。
 *
 * @internal
 * @coversNothing
 */
class OrderDaoTest extends TestCase
{
    private array $merchantIds = [];

    private array $orderIds = [];

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $id) {
            Order::destroy($id);
        }
        $this->orderIds = [];

        foreach ($this->merchantIds as $id) {
            Merchant::destroy($id);
        }
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testFindByOrderNoForMerchantReturnsNullForAnotherMerchantsOrder()
    {
        $dao = $this->getContainer()->get(OrderDao::class);

        $merchantA = $this->createMerchant();
        $merchantB = $this->createMerchant();
        $orderB = $this->createOrder($merchantB->id);

        $this->assertNull($dao->findByOrderNoForMerchant($merchantA->id, $orderB->order_no));
        $this->assertNotNull($dao->findByOrderNoForMerchant($merchantB->id, $orderB->order_no));
    }

    public function testFindByMerchantOrderNoForMerchantReturnsNullForAnotherMerchantsOrder()
    {
        $dao = $this->getContainer()->get(OrderDao::class);

        $merchantA = $this->createMerchant();
        $merchantB = $this->createMerchant();
        $orderB = $this->createOrder($merchantB->id);

        $this->assertNull($dao->findByMerchantOrderNoForMerchant($merchantA->id, $orderB->merchant_order_no));
        $this->assertNotNull($dao->findByMerchantOrderNoForMerchant($merchantB->id, $orderB->merchant_order_no));
    }

    public function testFindByOrderNoForMerchantReturnsNullWhenOrderDoesNotExist()
    {
        $dao = $this->getContainer()->get(OrderDao::class);
        $merchant = $this->createMerchant();

        $this->assertNull($dao->findByOrderNoForMerchant($merchant->id, 'no-such-order-' . uniqid('', true)));
    }

    private function createMerchant(): Merchant
    {
        $unique = uniqid('order_dao_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => 'encrypted-secret-placeholder',
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createOrder(int $merchantId): Order
    {
        $unique = uniqid('', true);

        $order = Order::create([
            'order_no' => 'PF' . $unique,
            'merchant_id' => $merchantId,
            'merchant_order_no' => 'MO' . $unique,
            'business_line' => 'recharge',
            'status' => 'processing',
            'sale_price' => '10.00',
            'cost_price' => '8.00',
            'frozen_amount' => '10.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
        ]);

        $this->orderIds[] = $order->id;

        return $order;
    }
}
