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

namespace HyperfTest\Cases\OpenApi;

use App\Crypto\Encryptor;
use App\Model\Merchant;
use App\Model\MerchantRebate;
use App\Signature\SignatureSigner;
use HyperfTest\HttpTestCase;

/**
 * 真实 HTTP 派发的端到端测试：用 test/HttpTestCase.php 包的 Hyperf\Testing\Client
 * （会对派发前收集到的 per-route 中间件跑 MiddlewareManager::sortMiddlewares()，
 * 是真正走了路由 + 中间件栈，不是 mock）。
 *
 * 注意没有用 Hyperf\Testing\TestCase 自带的 get()/post() 辅助方法：那一套走的是
 * Hyperf\Testing\Http\Client，这个类在 execute() 里派发前漏调了
 * MiddlewareManager::sortMiddlewares()，导致通过 #[Middleware] 注解挂载的
 * per-route 中间件会以未展开的 PriorityMiddleware 对象混进中间件数组，一进
 * AbstractRequestHandler::handleRequest() 就会报"Invalid middleware, it has to
 * provide a process() method."——这是测试工具本身的限制，不是业务代码的问题，
 * 直接用 $this->client->request() 拿原始 PSR-7 响应可以绕开它。
 *
 * @internal
 * @coversNothing
 */
class BalanceControllerTest extends HttpTestCase
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
            Merchant::destroy($id);
        }
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testBalanceIsReturnedForValidSignedRequest()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, '1000.50', '20.00');
        $this->createPendingRebate($merchant->id, '30.25');
        $this->createRebate($merchant->id, '999.00', 'settled');

        $response = $this->client->request('GET', '/open-api/balance', [
            'query' => $this->signedParams($merchant->app_key, $secret),
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'code' => 0,
            'message' => 'ok',
            'data' => [
                'available_balance' => '1000.50',
                'frozen_balance' => '20.00',
                'pending_rebate' => '30.25',
            ],
        ], json_decode((string) $response->getBody(), true));
    }

    public function testInvalidSignatureIsRejectedThroughRealMiddlewareStack()
    {
        $secret = 'plain-secret-' . uniqid('', true);
        $merchant = $this->createMerchant($secret, '1000.50', '20.00');

        $params = $this->signedParams($merchant->app_key, $secret);
        $params['sign'] = 'clearly-wrong-signature';

        $response = $this->client->request('GET', '/open-api/balance', ['query' => $params]);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([
            'code' => 40005,
            'message' => 'invalid signature',
            'data' => null,
        ], json_decode((string) $response->getBody(), true));
    }

    private function signedParams(string $appKey, string $secret): array
    {
        $params = [
            'app_key' => $appKey,
            'timestamp' => (string) time(),
            'nonce' => uniqid('nonce_', true),
        ];
        $params['sign'] = (new SignatureSigner())->sign($params, $secret);

        return $params;
    }

    private function createMerchant(string $plainSecret, string $availableBalance, string $frozenBalance): Merchant
    {
        $unique = uniqid('balance_ctrl_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => (new Encryptor())->encrypt($plainSecret),
            'available_balance' => $availableBalance,
            'frozen_balance' => $frozenBalance,
        ]);

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createPendingRebate(int $merchantId, string $amount): MerchantRebate
    {
        return $this->createRebate($merchantId, $amount, 'pending');
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
