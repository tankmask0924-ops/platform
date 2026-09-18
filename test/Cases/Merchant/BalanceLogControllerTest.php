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
use App\Model\MerchantBalanceLog;
use App\Service\Merchant\BalanceService;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * `GET /merchant/balance-logs` 是 requirements.md 4.4/4.5、7.2「资金流水：查询」
 * 商户后台一侧的落地，结构模板取自
 * test/Cases/Merchant/RechargeRequestControllerTest.php。
 *
 * 用 App\Service\Merchant\BalanceService 自己的方法（recharge/freeze/adjust）
 * 生成真实的流水行，而不是直接插 Model——这样顺带再验证一次那几个方法本身
 * 仍然工作正常，也让流水行的 before/after 快照跟真实业务场景一致。
 *
 * 全文件最重要的一条是 testListNeverLeaksAnotherMerchantsRows()：证明商户 A 的
 * token 无论如何都看不到商户 B 的流水，这是这个接口的核心 IDOR 防护。
 *
 * @internal
 * @coversNothing
 */
class BalanceLogControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

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

    public function testListReturnsOwnLogsOrderedNewestFirst()
    {
        $merchant = $this->createMerchant();
        $token = $this->loginAndGetToken($merchant);
        $balanceService = make(BalanceService::class);

        $balanceService->recharge($merchant->id, '100.00', '首次充值');
        $orderId = random_int(100000000, 999999999);
        $balanceService->freeze($merchant->id, $orderId, '30.00');
        $balanceService->adjust($merchant->id, '10.00', '快递理赔款', null);

        $response = $this->client->request('GET', '/merchant/balance-logs', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(3, $body['total']);
        $types = array_column($body['data'], 'type');
        // 最近发生的排最前面：最后一次调用是 adjust，第一次是 recharge。
        $this->assertSame(['adjustment', 'freeze', 'recharge'], $types);
    }

    public function testTypeFilterOnlyReturnsMatchingRows()
    {
        $merchant = $this->createMerchant();
        $token = $this->loginAndGetToken($merchant);
        $balanceService = make(BalanceService::class);

        $balanceService->recharge($merchant->id, '100.00', '首次充值');
        $balanceService->adjust($merchant->id, '10.00', '快递理赔款', null);

        $response = $this->client->request('GET', '/merchant/balance-logs', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'query' => ['type' => 'adjustment'],
        ]);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['data']);
        $this->assertSame('adjustment', $body['data'][0]['type']);
    }

    public function testInvalidTypeFilterReturnsCleanError()
    {
        $merchant = $this->createMerchant();
        $token = $this->loginAndGetToken($merchant);

        $response = $this->client->request('GET', '/merchant/balance-logs', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'query' => ['type' => 'not-a-real-type'],
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());
    }

    /**
     * 全文件最重要的一条：商户 A 的 token 不能看到商户 B 的流水，反过来也一样。
     */
    public function testListNeverLeaksAnotherMerchantsRows()
    {
        $merchantA = $this->createMerchant();
        $merchantB = $this->createMerchant();
        $tokenA = $this->loginAndGetToken($merchantA);
        $tokenB = $this->loginAndGetToken($merchantB);
        $balanceService = make(BalanceService::class);

        $balanceService->recharge($merchantA->id, '100.00', 'A 的充值');
        $balanceService->recharge($merchantB->id, '200.00', 'B 的充值');

        $responseA = $this->client->request('GET', '/merchant/balance-logs', [
            'headers' => ['Authorization' => 'Bearer ' . $tokenA],
        ]);
        $bodyA = json_decode((string) $responseA->getBody(), true);

        $this->assertSame(200, $responseA->getStatusCode());
        $this->assertCount(1, $bodyA['data']);
        $this->assertSame($merchantA->id, $bodyA['data'][0]['merchant_id']);
        $this->assertSame('100.00', $bodyA['data'][0]['amount']);

        $responseB = $this->client->request('GET', '/merchant/balance-logs', [
            'headers' => ['Authorization' => 'Bearer ' . $tokenB],
        ]);
        $bodyB = json_decode((string) $responseB->getBody(), true);

        $this->assertCount(1, $bodyB['data']);
        $this->assertSame($merchantB->id, $bodyB['data'][0]['merchant_id']);
        $this->assertSame('200.00', $bodyB['data'][0]['amount']);
    }

    public function testNoTokenReturns401()
    {
        $response = $this->client->request('GET', '/merchant/balance-logs');

        $this->assertSame(401, $response->getStatusCode());
    }

    private function createMerchant(): Merchant
    {
        $merchant = Merchant::create([
            'type' => 'company',
            'phone' => '189' . random_int(10000000, 99999999),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'status' => 'active',
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function loginAndGetToken(Merchant $merchant): string
    {
        $response = $this->client->request('POST', '/merchant/auth/login', [
            'form_params' => [
                'username' => $merchant->phone,
                'password' => self::PASSWORD,
            ],
        ]);

        $body = json_decode((string) $response->getBody(), true);

        return (string) $body['token'];
    }
}
