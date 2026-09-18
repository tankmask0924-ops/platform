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

namespace HyperfTest\Cases\Admin;

use App\Model\Merchant;
use App\Model\MerchantLevel;
use App\Model\MerchantRebate;
use App\Model\Order;
use App\Model\SystemSetting;
use HyperfTest\HttpTestCase;

/**
 * 系统后台「返佣管理 - 商户返佣明细」`GET /admin/rebates`（requirements.md 8.3）：
 * 权限、按商户筛选、平台内部字段（返佣基数和比例来源、等级名）、当前返佣期限。
 *
 * @internal
 * @coversNothing
 */
class RebateControllerTest extends HttpTestCase
{
    use CreatesAdmins;

    private const PASSWORD = 'correct-password';

    private array $merchantIds = [];

    private array $orderIds = [];

    private array $levelIds = [];

    protected function tearDown(): void
    {
        MerchantRebate::whereIn('order_id', $this->orderIds)->delete();
        Order::destroy($this->orderIds);
        Merchant::destroy($this->merchantIds);
        MerchantLevel::destroy($this->levelIds);
        $this->cleanUpAdmins();

        parent::tearDown();
    }

    public function testRequiresRebateViewPermission()
    {
        $this->assertSame(401, $this->jsonRequest('GET', '/admin/rebates', null)->getStatusCode());

        $token = $this->loginAs($this->createAdminWithPermissions(['order.view']));
        $this->assertSame(403, $this->jsonRequest('GET', '/admin/rebates', $token)->getStatusCode());
    }

    public function testFiltersByMerchantAndReturnsInternalFields()
    {
        $level = MerchantLevel::create(['name' => 'rebate_test_' . uniqid()]);
        $this->levelIds[] = $level->id;
        $merchant = $this->createMerchant();
        $other = $this->createMerchant();
        $rebate = $this->createRebate($merchant, $level->id, 'clawed_back', '0.80');
        $this->createRebate($other, $level->id, 'pending', '1.00');
        $token = $this->loginAs($this->createAdminWithPermissions(['rebate.view']));

        $response = $this->jsonRequest('GET', '/admin/rebates?merchant_id=' . $merchant->id, $token);
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $body['total']);
        $this->assertSame(['count' => 1, 'amount' => '0.80'], $body['summary']['clawed_back']);
        $row = $body['data'][0];
        $this->assertSame($rebate->id, $row['id']);
        $this->assertSame($merchant->id, $row['merchant_id']);
        $this->assertSame($merchant->phone, $row['merchant_phone']);
        $this->assertSame($level->name, $row['level_name']);
        $this->assertSame('1.60', $row['rebate_base']);
        $this->assertSame('product', $row['rebate_base_source']);
        $this->assertSame('product_level', $row['rebate_rate_source']);
        $this->assertIsInt($body['due_period_days']);

        $this->assertSame(422, $this->jsonRequest('GET', '/admin/rebates?merchant_id=abc', $token)->getStatusCode());
    }

    public function testDuePeriodReflectsSystemSetting()
    {
        $backup = SystemSetting::find('rebate_due_period_days')?->getAttributes();
        SystemSetting::query()->updateOrInsert(['key' => 'rebate_due_period_days'], ['value' => '12', 'updated_at' => date('Y-m-d H:i:s')]);
        try {
            $token = $this->loginAs($this->createAdminWithPermissions(['rebate.view']));
            $this->assertSame(12, $this->body($this->jsonRequest('GET', '/admin/rebates', $token))['due_period_days']);
        } finally {
            SystemSetting::query()->where('key', 'rebate_due_period_days')->delete();
            if ($backup !== null) {
                SystemSetting::query()->insert($backup);
            }
        }
    }

    private function createRebate(Merchant $merchant, int $levelId, string $status, string $amount): MerchantRebate
    {
        $completedAt = date('Y-m-d H:i:s', time() - 3600);
        $order = Order::create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => 'MO-' . uniqid('', true),
            'business_line' => 'recharge',
            'status' => 'success',
            'sale_price' => '100.00',
            'cost_price' => '98.00',
            'frozen_amount' => '100.00',
            'deducted_amount' => '100.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://merchant.example.com/notify',
            'completed_at' => $completedAt,
            'finished_at' => $completedAt,
        ]);
        $this->orderIds[] = $order->id;

        return MerchantRebate::create([
            'order_id' => $order->id,
            'merchant_id' => $merchant->id,
            'business_line' => 'recharge',
            'level_id' => $levelId,
            'rebate_base' => '1.60',
            'rebate_base_source' => 'product',
            'rebate_rate' => '0.5000',
            'rebate_rate_source' => 'product_level',
            'amount' => $amount,
            'status' => $status,
            'order_completed_at' => $completedAt,
            'due_at' => date('Y-m-d H:i:s', strtotime($completedAt) + 7 * 86400),
        ]);
    }

    private function createMerchant(): Merchant
    {
        $merchant = Merchant::create([
            'type' => 'company',
            'phone' => '189' . random_int(10000000, 99999999),
            'password' => 'hashed-password',
            'status' => 'active',
        ]);
        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }
}
