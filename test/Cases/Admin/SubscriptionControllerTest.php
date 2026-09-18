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

use App\Model\AdminOperationLog;
use App\Model\Merchant;
use App\Model\MerchantBusinessSubscription;
use HyperfTest\HttpTestCase;

/**
 * 系统后台「服务开通审核」（requirements.md 4.2、8.3）：权限、筛选、通过/驳回、只能审待审核的、操作日志。
 *
 * @internal
 * @coversNothing
 */
class SubscriptionControllerTest extends HttpTestCase
{
    use CreatesAdmins;

    private const PASSWORD = 'correct-password';

    private array $merchantIds = [];

    protected function tearDown(): void
    {
        MerchantBusinessSubscription::whereIn('merchant_id', $this->merchantIds)->delete();
        Merchant::destroy($this->merchantIds);
        $this->cleanUpAdmins();

        parent::tearDown();
    }

    public function testPermissions()
    {
        $subscription = $this->createSubscription('pending');
        $viewer = $this->loginAs($this->createAdminWithPermissions(['subscription.view']));

        $this->assertSame(401, $this->jsonRequest('GET', '/admin/subscriptions', null)->getStatusCode());
        $this->assertSame(200, $this->jsonRequest('GET', '/admin/subscriptions', $viewer)->getStatusCode());
        $this->assertSame(403, $this->jsonRequest('POST', "/admin/subscriptions/{$subscription->id}/approve", $viewer)->getStatusCode());
        $this->assertSame('pending', $subscription->refresh()->status);
    }

    public function testListFiltersAndApproveRejectFlow()
    {
        $pending = $this->createSubscription('pending');
        $other = $this->createSubscription('pending', 'card');
        $admin = $this->createAdminWithPermissions(['subscription.view', 'subscription.review']);
        $token = $this->loginAs($admin);

        $body = $this->body($this->jsonRequest('GET', '/admin/subscriptions?status=pending&merchant_id=' . $pending->merchant_id, $token));
        $this->assertSame(1, $body['total']);
        $this->assertSame($pending->id, $body['data'][0]['id']);
        $this->assertSame('recharge', $body['data'][0]['business_line']);
        $this->assertNotNull($body['data'][0]['merchant_phone']);
        $this->assertSame(422, $this->jsonRequest('GET', '/admin/subscriptions?status=bogus', $token)->getStatusCode());

        $this->assertSame(200, $this->jsonRequest('POST', "/admin/subscriptions/{$pending->id}/approve", $token)->getStatusCode());
        $pending->refresh();
        $this->assertSame('approved', $pending->status);
        $this->assertSame($admin->id, $pending->reviewed_by);
        $this->assertSame(409, $this->jsonRequest('POST', "/admin/subscriptions/{$pending->id}/reject", $token, ['reason' => 'x'])->getStatusCode());

        $this->assertSame(422, $this->jsonRequest('POST', "/admin/subscriptions/{$other->id}/reject", $token, ['reason' => ' '])->getStatusCode());
        $this->assertSame(200, $this->jsonRequest('POST', "/admin/subscriptions/{$other->id}/reject", $token, ['reason' => '暂不支持'])->getStatusCode());
        $other->refresh();
        $this->assertSame('rejected', $other->status);
        $this->assertSame('暂不支持', $other->reject_reason);

        $this->assertSame(404, $this->jsonRequest('POST', '/admin/subscriptions/999999999/approve', $token)->getStatusCode());
        $this->assertSame(2, AdminOperationLog::where('admin_user_id', $admin->id)->count());
    }

    public function testMerchantDetailShowsSubscriptions()
    {
        $subscription = $this->createSubscription('approved');
        $token = $this->loginAs($this->createAdminWithPermissions(['merchant.view']));

        $body = $this->body($this->jsonRequest('GET', '/admin/merchants/' . $subscription->merchant_id, $token));

        $this->assertSame('approved', array_column($body['subscriptions'], 'status', 'business_line')['recharge']);
        $this->assertNull(array_column($body['subscriptions'], 'status', 'business_line')['card']);
    }

    private function createSubscription(string $status, string $line = 'recharge'): MerchantBusinessSubscription
    {
        $merchant = Merchant::create([
            'type' => 'company',
            'phone' => '188' . random_int(10000000, 99999999),
            'password' => 'hashed-password',
            'status' => 'active',
        ]);
        $this->merchantIds[] = $merchant->id;

        return MerchantBusinessSubscription::create([
            'merchant_id' => $merchant->id,
            'business_line' => $line,
            'status' => $status,
            'applied_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
