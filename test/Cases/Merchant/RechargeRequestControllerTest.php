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
use App\Model\MerchantRechargeRequest;
use HyperfTest\HttpTestCase;

/**
 * 真实 HTTP 派发的端到端测试，结构跟 test/Cases/Merchant/DevSettingsControllerTest.php
 * 一致：登录态通过真实调用 /merchant/auth/login 拿 token。
 *
 * @internal
 * @coversNothing
 */
class RechargeRequestControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $merchantIds = [];

    private array $requestIds = [];

    protected function tearDown(): void
    {
        foreach ($this->requestIds as $id) {
            MerchantRechargeRequest::destroy($id);
        }
        foreach ($this->merchantIds as $id) {
            Merchant::destroy($id);
        }
        $this->requestIds = [];
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testSubmitValidRequestCreatesPendingRowScopedToAuthenticatedMerchant()
    {
        $merchant = $this->createMerchant();
        $token = $this->loginAndGetToken($merchant);

        $response = $this->client->request('POST', '/merchant/recharge-requests', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => [
                'amount' => '500.00',
                'proof_image' => 'https://example.com/proof.png',
                'transfer_no' => 'TRX123456',
            ],
        ]);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($merchant->id, $body['merchant_id']);
        $this->assertSame('500.00', $body['amount']);
        $this->assertSame('pending', $body['status']);
        $this->assertSame('TRX123456', $body['transfer_no']);
        $this->requestIds[] = $body['id'];

        $stored = MerchantRechargeRequest::find($body['id']);
        $this->assertSame($merchant->id, $stored->merchant_id);
        $this->assertSame('pending', $stored->status);
        $this->assertSame('https://example.com/proof.png', $stored->proof_image);
    }

    public function testSubmitWithoutTransferNoStillSucceeds()
    {
        $merchant = $this->createMerchant();
        $token = $this->loginAndGetToken($merchant);

        $response = $this->client->request('POST', '/merchant/recharge-requests', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => [
                'amount' => '100',
                'proof_image' => 'https://example.com/proof2.png',
            ],
        ]);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($body['transfer_no']);
        $this->requestIds[] = $body['id'];
    }

    public function testSubmitMissingAmountReturnsCleanError()
    {
        $merchant = $this->createMerchant();
        $token = $this->loginAndGetToken($merchant);

        $response = $this->client->request('POST', '/merchant/recharge-requests', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => [
                'proof_image' => 'https://example.com/proof.png',
            ],
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());
    }

    public function testSubmitInvalidAmountReturnsCleanError()
    {
        $merchant = $this->createMerchant();
        $token = $this->loginAndGetToken($merchant);

        $response = $this->client->request('POST', '/merchant/recharge-requests', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => [
                'amount' => '-5.00',
                'proof_image' => 'https://example.com/proof.png',
            ],
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());
    }

    public function testSubmitMissingProofImageReturnsCleanError()
    {
        $merchant = $this->createMerchant();
        $token = $this->loginAndGetToken($merchant);

        $response = $this->client->request('POST', '/merchant/recharge-requests', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => [
                'amount' => '100.00',
            ],
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());
    }

    public function testListOnlyReturnsAuthenticatedMerchantsOwnRequests()
    {
        $merchantA = $this->createMerchant();
        $merchantB = $this->createMerchant();
        $tokenA = $this->loginAndGetToken($merchantA);
        $tokenB = $this->loginAndGetToken($merchantB);

        $requestA = $this->createRechargeRequest($merchantA->id);
        $requestB = $this->createRechargeRequest($merchantB->id);

        $responseA = $this->client->request('GET', '/merchant/recharge-requests', [
            'headers' => ['Authorization' => 'Bearer ' . $tokenA],
        ]);
        $bodyA = json_decode((string) $responseA->getBody(), true);

        $this->assertSame(200, $responseA->getStatusCode());
        $idsA = array_column($bodyA['data'], 'id');
        $this->assertContains($requestA->id, $idsA);
        $this->assertNotContains($requestB->id, $idsA);

        $responseB = $this->client->request('GET', '/merchant/recharge-requests', [
            'headers' => ['Authorization' => 'Bearer ' . $tokenB],
        ]);
        $bodyB = json_decode((string) $responseB->getBody(), true);

        $idsB = array_column($bodyB['data'], 'id');
        $this->assertContains($requestB->id, $idsB);
        $this->assertNotContains($requestA->id, $idsB);
    }

    public function testNoTokenOnEitherRouteReturns401()
    {
        $submitResponse = $this->client->request('POST', '/merchant/recharge-requests', [
            'form_params' => ['amount' => '100.00', 'proof_image' => 'x'],
        ]);
        $this->assertSame(401, $submitResponse->getStatusCode());

        $listResponse = $this->client->request('GET', '/merchant/recharge-requests');
        $this->assertSame(401, $listResponse->getStatusCode());
    }

    private function createMerchant(array $overrides = []): Merchant
    {
        $merchant = Merchant::create(array_merge([
            'type' => 'company',
            'phone' => '188' . random_int(10000000, 99999999),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'status' => 'active',
        ], $overrides));

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createRechargeRequest(int $merchantId): MerchantRechargeRequest
    {
        $request = MerchantRechargeRequest::create([
            'merchant_id' => $merchantId,
            'amount' => '200.00',
            'proof_image' => 'https://example.com/proof.png',
            'status' => 'pending',
        ]);
        $this->requestIds[] = $request->id;

        return $request;
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
