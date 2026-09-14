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

use App\Crypto\Encryptor;
use App\Model\Merchant;
use HyperfTest\HttpTestCase;

/**
 * 真实 HTTP 派发的端到端测试，用法跟 test/Cases/Merchant/AuthControllerTest.php 一致
 * （走真实路由 + 中间件栈，登录态通过真实调用 /merchant/auth/login 拿 token，
 * 不直接 new MerchantJwtGuard 走捷径，跟 AuthControllerTest 保持一致的做法）。
 *
 * @internal
 * @coversNothing
 */
class DevSettingsControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $merchantIds = [];

    protected function tearDown(): void
    {
        foreach ($this->merchantIds as $id) {
            Merchant::destroy($id);
        }
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testGetBeforeAnythingGeneratedReturnsNullKeyAndFalseFlag()
    {
        $merchant = $this->createMerchant();
        $token = $this->loginAndGetToken($merchant);

        $response = $this->client->request('GET', '/merchant/dev-settings', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($body['app_key']);
        $this->assertFalse($body['app_secret_generated']);
        $this->assertNull($body['app_secret_reset_at']);
        $this->assertSame([], $body['ip_whitelist']);
    }

    public function testGenerateAppKeyOnPendingMerchantReturns403()
    {
        $merchant = $this->createMerchant(['status' => 'pending']);
        $token = $this->loginAndGetToken($merchant);

        $response = $this->client->request('POST', '/merchant/dev-settings/app-key', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testGenerateAppKeyOnActiveMerchantSucceedsAndSecretNeverExposedAgain()
    {
        $merchant = $this->createMerchant(['status' => 'active']);
        $token = $this->loginAndGetToken($merchant);

        $generate = $this->client->request('POST', '/merchant/dev-settings/app-key', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $generateBody = json_decode((string) $generate->getBody(), true);

        $this->assertSame(200, $generate->getStatusCode());
        $this->assertIsString($generateBody['app_key']);
        $this->assertNotSame('', $generateBody['app_key']);
        $this->assertIsString($generateBody['app_secret']);
        $this->assertNotSame('', $generateBody['app_secret']);
        $this->assertNotEmpty($generateBody['app_secret_reset_at']);

        // 数据库里存的是密文，不是明文，且能用 Encryptor 解密回同一个明文。
        $stored = Merchant::find($merchant->id);
        $this->assertNotSame($generateBody['app_secret'], $stored->app_secret);
        $encryptor = new Encryptor();
        $this->assertSame($generateBody['app_secret'], $encryptor->decrypt($stored->app_secret));
        $this->assertSame($generateBody['app_key'], $stored->app_key);

        // 后续 GET 只能看到 app_key 和「已生成」标志，永远看不到 app_secret 本身（明文或密文）。
        $get = $this->client->request('GET', '/merchant/dev-settings', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $getBody = json_decode((string) $get->getBody(), true);

        $this->assertSame(200, $get->getStatusCode());
        $this->assertSame($generateBody['app_key'], $getBody['app_key']);
        $this->assertTrue($getBody['app_secret_generated']);
        $this->assertArrayNotHasKey('app_secret', $getBody);
        $this->assertStringNotContainsString($generateBody['app_secret'], (string) $get->getBody());
    }

    public function testGenerateAppKeyAgainWhenOneAlreadyExistsReturns409()
    {
        $merchant = $this->createMerchant(['status' => 'active']);
        $token = $this->loginAndGetToken($merchant);

        $first = $this->client->request('POST', '/merchant/dev-settings/app-key', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->assertSame(200, $first->getStatusCode());

        $second = $this->client->request('POST', '/merchant/dev-settings/app-key', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->assertSame(409, $second->getStatusCode());
    }

    public function testResetAppSecretChangesSecretButKeepsAppKey()
    {
        $merchant = $this->createMerchant(['status' => 'active']);
        $token = $this->loginAndGetToken($merchant);

        $generate = $this->client->request('POST', '/merchant/dev-settings/app-key', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $generateBody = json_decode((string) $generate->getBody(), true);
        $originalAppKey = $generateBody['app_key'];
        $originalCipher = Merchant::find($merchant->id)->app_secret;

        $reset = $this->client->request('POST', '/merchant/dev-settings/app-secret/reset', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $resetBody = json_decode((string) $reset->getBody(), true);

        $this->assertSame(200, $reset->getStatusCode());
        $this->assertSame($originalAppKey, $resetBody['app_key']);
        $this->assertNotSame($generateBody['app_secret'], $resetBody['app_secret']);

        $stored = Merchant::find($merchant->id);
        $this->assertSame($originalAppKey, $stored->app_key);
        $this->assertNotSame($originalCipher, $stored->app_secret);

        $encryptor = new Encryptor();
        $this->assertSame($resetBody['app_secret'], $encryptor->decrypt($stored->app_secret));
    }

    public function testResetAppSecretWithoutExistingAppKeyReturns409()
    {
        $merchant = $this->createMerchant(['status' => 'active']);
        $token = $this->loginAndGetToken($merchant);

        $response = $this->client->request('POST', '/merchant/dev-settings/app-secret/reset', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testIpWhitelistValidMixedArraySavedAndReflectedInGet()
    {
        $merchant = $this->createMerchant();
        $token = $this->loginAndGetToken($merchant);
        $ips = ['1.2.3.4', '::1', '2001:db8::1'];

        $response = $this->client->request('PUT', '/merchant/dev-settings/ip-whitelist', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'json' => $ips,
        ]);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($ips, $body['ip_whitelist']);

        $get = $this->client->request('GET', '/merchant/dev-settings', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $getBody = json_decode((string) $get->getBody(), true);
        $this->assertSame($ips, $getBody['ip_whitelist']);
    }

    public function testIpWhitelistWithInvalidEntryIsRejectedAndDoesNotPartiallyUpdate()
    {
        $merchant = $this->createMerchant();
        $token = $this->loginAndGetToken($merchant);

        // 先成功写入一份已知基线。
        $baseline = ['9.9.9.9'];
        $baselineResponse = $this->client->request('PUT', '/merchant/dev-settings/ip-whitelist', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'json' => $baseline,
        ]);
        $this->assertSame(200, $baselineResponse->getStatusCode());

        $invalidResponse = $this->client->request('PUT', '/merchant/dev-settings/ip-whitelist', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'json' => ['1.2.3.4', 'not-an-ip'],
        ]);

        $this->assertGreaterThanOrEqual(400, $invalidResponse->getStatusCode());
        $this->assertLessThan(500, $invalidResponse->getStatusCode());

        // 校验失败的请求没有把基线部分或整体覆盖掉。
        $stored = Merchant::find($merchant->id);
        $this->assertSame($baseline, $stored->ip_whitelist);
    }

    public function testEveryRouteWithoutAuthorizationHeaderReturns401()
    {
        $getResponse = $this->client->request('GET', '/merchant/dev-settings');
        $this->assertSame(401, $getResponse->getStatusCode());

        $generateResponse = $this->client->request('POST', '/merchant/dev-settings/app-key');
        $this->assertSame(401, $generateResponse->getStatusCode());

        $resetResponse = $this->client->request('POST', '/merchant/dev-settings/app-secret/reset');
        $this->assertSame(401, $resetResponse->getStatusCode());

        $whitelistResponse = $this->client->request('PUT', '/merchant/dev-settings/ip-whitelist', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => [],
        ]);
        $this->assertSame(401, $whitelistResponse->getStatusCode());
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
