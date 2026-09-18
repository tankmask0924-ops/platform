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

namespace HyperfTest\Cases\Merchant;

use App\Model\Merchant;
use App\Model\MerchantBusinessSubscription;
use App\Service\Admin\SubscriptionAdminService;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * 商户后台「服务开通」`GET/POST /merchant/subscriptions`（requirements.md 4.2、8.2）：
 * 申请 → 驳回 → 重新申请 → 通过；未开放的业务线、未通过资质审核的商户不能申请。
 *
 * @internal
 * @coversNothing
 */
class SubscriptionControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $merchantIds = [];

    protected function tearDown(): void
    {
        MerchantBusinessSubscription::whereIn('merchant_id', $this->merchantIds)->delete();
        Merchant::destroy($this->merchantIds);
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testListShowsAllBusinessLinesWithAvailability()
    {
        $body = $this->list($this->login($this->createMerchant('active')));

        $this->assertSame(['recharge', 'card', 'movie', 'express'], array_column($body, 'business_line'));
        $this->assertSame([true, true, false, false], array_column($body, 'available'));
        $this->assertSame([null, null, null, null], array_column($body, 'status'));
    }

    public function testApplyRejectReapplyApprove()
    {
        $merchant = $this->createMerchant('active');
        $token = $this->login($merchant);
        $admin = make(SubscriptionAdminService::class);

        $response = $this->apply($token, 'recharge');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('pending', $this->statusOf($this->list($token), 'recharge'));
        $this->assertSame(409, $this->apply($token, 'recharge')->getStatusCode());

        $subscription = MerchantBusinessSubscription::where('merchant_id', $merchant->id)->first();
        $admin->reject($subscription->id, '请先补充业务说明', 1);
        $row = $this->list($token)[0];
        $this->assertSame('rejected', $row['status']);
        $this->assertSame('请先补充业务说明', $row['reject_reason']);

        // 重新申请复用同一行
        $this->assertSame(200, $this->apply($token, 'recharge')->getStatusCode());
        $this->assertSame(1, MerchantBusinessSubscription::where('merchant_id', $merchant->id)->count());
        $row = $this->list($token)[0];
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['reject_reason']);

        $admin->approve($subscription->id, 1);
        $this->assertSame('approved', $this->statusOf($this->list($token), 'recharge'));
        $this->assertSame(409, $this->apply($token, 'recharge')->getStatusCode());
    }

    public function testCannotApplyForUnopenedLineOrBeforeQualificationApproved()
    {
        $token = $this->login($this->createMerchant('active'));
        $this->assertSame(422, $this->apply($token, 'movie')->getStatusCode());
        $this->assertSame(422, $this->apply($token, 'nope')->getStatusCode());

        $pendingToken = $this->login($this->createMerchant('pending'));
        $this->assertSame(409, $this->apply($pendingToken, 'recharge')->getStatusCode());
    }

    public function testNoTokenReturns401()
    {
        $this->assertSame(401, $this->client->request('GET', '/merchant/subscriptions')->getStatusCode());
    }

    private function statusOf(array $rows, string $line): ?string
    {
        return array_column($rows, 'status', 'business_line')[$line];
    }

    private function list(string $token): array
    {
        $response = $this->client->request('GET', '/merchant/subscriptions', ['headers' => ['Authorization' => 'Bearer ' . $token]]);
        $this->assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true)['data'];
    }

    private function apply(string $token, string $line)
    {
        return $this->client->request('POST', '/merchant/subscriptions', [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'json' => ['business_line' => $line],
        ]);
    }

    private function createMerchant(string $status): Merchant
    {
        $merchant = Merchant::create([
            'type' => 'company',
            'phone' => '189' . random_int(10000000, 99999999),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'status' => $status,
        ]);
        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function login(Merchant $merchant): string
    {
        $response = $this->client->request('POST', '/merchant/auth/login', [
            'form_params' => ['username' => $merchant->phone, 'password' => self::PASSWORD],
        ]);

        return (string) json_decode((string) $response->getBody(), true)['token'];
    }
}
