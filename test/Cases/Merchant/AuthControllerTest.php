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
use App\Model\MerchantQualification;
use HyperfTest\HttpTestCase;

/**
 * 真实 HTTP 派发的端到端测试，用法跟 test/Cases/OpenApi/BalanceControllerTest.php 一致
 * （用 test/HttpTestCase.php 包的 Hyperf\Testing\Client，走真实路由 + 中间件栈，
 * 不能用 Hyperf\Testing\TestCase 自带的 get()，原因见 BalanceControllerTest 的类注释——
 * /merchant/auth/me 是通过方法级 #[Middleware] 挂载的，同样会踩到那个坑）。
 *
 * @internal
 * @coversNothing
 */
class AuthControllerTest extends HttpTestCase
{
    private array $merchantIds = [];

    protected function tearDown(): void
    {
        foreach ($this->merchantIds as $id) {
            MerchantQualification::where('merchant_id', $id)->delete();
            Merchant::destroy($id);
        }
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testRegisterCompanySucceeds()
    {
        $unique = uniqid('company_', true);

        $response = $this->client->request('POST', '/merchant/auth/register', [
            'form_params' => [
                'type' => 'company',
                'phone' => '138' . substr(preg_replace('/\D/', '', $unique), 0, 8),
                'password' => 'password123',
                'company_name' => 'Acme Inc',
                'business_license_no' => 'BL' . $unique,
                'legal_person_name' => 'Zhang San',
                'contact_name' => 'Li Si',
                'contact_phone' => '13900000001',
            ],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pending', $body['status']);
        $this->assertIsInt($body['id']);
        $this->merchantIds[] = $body['id'];

        $merchant = Merchant::find($body['id']);
        $this->assertNotNull($merchant);
        $this->assertSame('pending', $merchant->status);
        $this->assertSame('company', $merchant->type);

        $qualification = MerchantQualification::where('merchant_id', $body['id'])->first();
        $this->assertNotNull($qualification);
        $this->assertSame('pending', $qualification->status);
        $this->assertSame('Acme Inc', $qualification->company_name);
    }

    public function testRegisterIndividualSucceeds()
    {
        $unique = uniqid('individual_', true);

        $response = $this->client->request('POST', '/merchant/auth/register', [
            'form_params' => [
                'type' => 'individual',
                'email' => $unique . '@example.com',
                'password' => 'password123',
                'id_card_name' => 'Wang Wu',
                'id_card_no' => '110101199001011234',
                'contact_phone' => '13900000002',
            ],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pending', $body['status']);
        $this->merchantIds[] = $body['id'];

        $qualification = MerchantQualification::where('merchant_id', $body['id'])->first();
        $this->assertNotNull($qualification);
        $this->assertSame('pending', $qualification->status);
        // id_card_no 加密存储，不应该是明文。
        $this->assertNotSame('110101199001011234', $qualification->id_card_no);
    }

    public function testRegisterMissingRequiredFieldForTypeReturnsCleanError()
    {
        $response = $this->client->request('POST', '/merchant/auth/register', [
            'form_params' => [
                'type' => 'company',
                'email' => uniqid('missing_field_', true) . '@example.com',
                'password' => 'password123',
                // 缺 company_name / business_license_no / legal_person_name / contact_name / contact_phone
            ],
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());
    }

    public function testRegisterDuplicatePhoneReturnsCleanValidationErrorNotServerError()
    {
        $phone = '139' . substr((string) random_int(10000000, 99999999), 0, 8);

        $first = $this->client->request('POST', '/merchant/auth/register', [
            'form_params' => [
                'type' => 'individual',
                'phone' => $phone,
                'password' => 'password123',
                'id_card_name' => 'First Owner',
                'id_card_no' => '110101199001011111',
                'contact_phone' => '13900000003',
            ],
        ]);
        $firstBody = json_decode((string) $first->getBody(), true);
        $this->assertSame(200, $first->getStatusCode());
        $this->merchantIds[] = $firstBody['id'];

        $second = $this->client->request('POST', '/merchant/auth/register', [
            'form_params' => [
                'type' => 'individual',
                'phone' => $phone,
                'password' => 'password123',
                'id_card_name' => 'Second Owner',
                'id_card_no' => '110101199001012222',
                'contact_phone' => '13900000004',
            ],
        ]);

        $this->assertGreaterThanOrEqual(400, $second->getStatusCode());
        $this->assertLessThan(500, $second->getStatusCode());
    }

    public function testLoginWithCorrectPhoneAndPasswordSucceeds()
    {
        $merchant = $this->createActiveMerchant(['phone' => '137' . random_int(10000000, 99999999)]);

        $response = $this->client->request('POST', '/merchant/auth/login', [
            'form_params' => [
                'username' => $merchant->phone,
                'password' => 'correct-password',
            ],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($body['token']);
        $this->assertSame($merchant->phone, $body['username']);
    }

    public function testLoginWithCorrectEmailAndPasswordSucceeds()
    {
        $merchant = $this->createActiveMerchant(['email' => uniqid('login_email_', true) . '@example.com']);

        $response = $this->client->request('POST', '/merchant/auth/login', [
            'form_params' => [
                'username' => $merchant->email,
                'password' => 'correct-password',
            ],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($merchant->email, $body['username']);
    }

    public function testLoginWithWrongPasswordAndUnknownUsernameShareTheSameGenericMessage()
    {
        $merchant = $this->createActiveMerchant(['phone' => '136' . random_int(10000000, 99999999)]);

        $wrongPassword = $this->client->request('POST', '/merchant/auth/login', [
            'form_params' => [
                'username' => $merchant->phone,
                'password' => 'totally-wrong-password',
            ],
        ]);

        $unknownUsername = $this->client->request('POST', '/merchant/auth/login', [
            'form_params' => [
                'username' => 'no-such-user-' . uniqid('', true),
                'password' => 'whatever',
            ],
        ]);

        $this->assertSame(401, $wrongPassword->getStatusCode());
        $this->assertSame(401, $unknownUsername->getStatusCode());
        $this->assertSame(
            (string) $wrongPassword->getBody(),
            (string) $unknownUsername->getBody()
        );
    }

    public function testLoginWithDisabledMerchantIsBlockedDespiteCorrectPassword()
    {
        $merchant = $this->createActiveMerchant([
            'phone' => '135' . random_int(10000000, 99999999),
            'status' => 'disabled',
        ]);

        $response = $this->client->request('POST', '/merchant/auth/login', [
            'form_params' => [
                'username' => $merchant->phone,
                'password' => 'correct-password',
            ],
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMeWithValidTokenReturnsExpectedFieldsWithoutSensitiveOnes()
    {
        $merchant = $this->createActiveMerchant(['phone' => '134' . random_int(10000000, 99999999)]);

        $login = $this->client->request('POST', '/merchant/auth/login', [
            'form_params' => [
                'username' => $merchant->phone,
                'password' => 'correct-password',
            ],
        ]);
        $token = json_decode((string) $login->getBody(), true)['token'];

        $response = $this->client->request('GET', '/merchant/auth/me', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($merchant->id, $body['id']);
        $this->assertSame($merchant->phone, $body['phone']);
        $this->assertArrayNotHasKey('password', $body);
        $this->assertArrayNotHasKey('app_secret', $body);
        $this->assertArrayNotHasKey('id_card_no', $body);
    }

    public function testMeWithoutAuthorizationHeaderReturns401()
    {
        $response = $this->client->request('GET', '/merchant/auth/me');

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testMeWithGarbageTokenReturns401()
    {
        $response = $this->client->request('GET', '/merchant/auth/me', [
            'headers' => ['Authorization' => 'Bearer garbage-token-' . uniqid('', true)],
        ]);

        $this->assertSame(401, $response->getStatusCode());
    }

    private function createActiveMerchant(array $overrides = []): Merchant
    {
        $merchant = Merchant::create(array_merge([
            'type' => 'company',
            'password' => password_hash('correct-password', PASSWORD_BCRYPT),
            'status' => 'active',
        ], $overrides));

        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }
}
