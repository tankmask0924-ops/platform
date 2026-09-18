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
use Hyperf\Context\ApplicationContext;
use Hyperf\Redis\Redis;
use HyperfTest\HttpTestCase;

/**
 * 修改密码 / 找回密码。验证码只以哈希存在 Redis 里，测试直接检查和预置 Redis 里的验证码，
 * 不去替换发送器。
 *
 * @internal
 * @coversNothing
 */
class PasswordControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $merchantIds = [];

    protected function tearDown(): void
    {
        foreach ($this->merchantIds as $id) {
            $this->redis()->del("merchant:pwd_reset:code:{$id}");
            Merchant::destroy($id);
        }
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testChangePasswordInvalidatesOldTokensAndReturnsWorkingNewOne()
    {
        $merchant = $this->createMerchant();
        $oldToken = $this->login($merchant->phone, self::PASSWORD);

        $response = $this->changePassword($oldToken, self::PASSWORD, 'brand-new-password');
        $newToken = json_decode((string) $response->getBody(), true)['token'] ?? null;

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(401, $this->me($oldToken)->getStatusCode());
        $this->assertSame(200, $this->me($newToken)->getStatusCode());
        $this->assertNull($this->login($merchant->phone, self::PASSWORD));
        $this->assertNotNull($this->login($merchant->phone, 'brand-new-password'));
    }

    public function testChangePasswordValidation()
    {
        $merchant = $this->createMerchant();
        $token = $this->login($merchant->phone, self::PASSWORD);

        $this->assertSame(422, $this->changePassword($token, 'wrong-old-password', 'brand-new-password')->getStatusCode());
        $this->assertSame(422, $this->changePassword($token, self::PASSWORD, 'short')->getStatusCode());
        $this->assertSame(422, $this->changePassword($token, self::PASSWORD, self::PASSWORD)->getStatusCode());
        $this->assertSame(401, $this->client->request('PUT', '/merchant/auth/password')->getStatusCode());
    }

    public function testSendResetCodeStoresCodeAndRespectsCooldown()
    {
        $merchant = $this->createMerchant();

        $this->assertSame(200, $this->sendCode($merchant->phone)->getStatusCode());
        $this->assertSame(1, $this->redis()->exists("merchant:pwd_reset:code:{$merchant->id}"));
        $this->assertSame(429, $this->sendCode($merchant->phone)->getStatusCode());
    }

    public function testSendResetCodeForUnknownAccountLooksTheSame()
    {
        $known = $this->sendCode($this->createMerchant()->phone);
        $unknown = $this->sendCode('199' . random_int(10000000, 99999999));

        $this->assertSame(200, $unknown->getStatusCode());
        $this->assertSame((string) $known->getBody(), (string) $unknown->getBody());
    }

    public function testSendResetCodeRequiresPhoneNumber()
    {
        $this->assertSame(422, $this->sendCode('someone@example.com')->getStatusCode());
        $this->assertSame(422, $this->sendCode('12345')->getStatusCode());
    }

    public function testResetWithCorrectCodeChangesPasswordOnceAndInvalidatesTokens()
    {
        $merchant = $this->createMerchant();
        $oldToken = $this->login($merchant->phone, self::PASSWORD);
        $this->seedCode($merchant->id, '123456');

        $this->assertSame(200, $this->reset($merchant->phone, '123456', 'reset-password-1')->getStatusCode());
        $this->assertSame(401, $this->me($oldToken)->getStatusCode());
        $this->assertNotNull($this->login($merchant->phone, 'reset-password-1'));
        // 验证码只能用一次
        $this->assertSame(422, $this->reset($merchant->phone, '123456', 'reset-password-2')->getStatusCode());
    }

    public function testResetWithWrongCodeLocksAfterMaxAttempts()
    {
        $merchant = $this->createMerchant();
        $this->seedCode($merchant->id, '123456');

        for ($i = 0; $i < 5; ++$i) {
            $this->assertSame(422, $this->reset($merchant->phone, '000000', 'reset-password-1')->getStatusCode());
        }
        // 输错 5 次后验证码作废，正确的也不行了
        $this->assertSame(422, $this->reset($merchant->phone, '123456', 'reset-password-1')->getStatusCode());
        $this->assertNotNull($this->login($merchant->phone, self::PASSWORD));
    }

    private function seedCode(int $merchantId, string $code): void
    {
        $this->redis()->set("merchant:pwd_reset:code:{$merchantId}", json_encode([
            'hash' => hash('sha256', $code),
            'attempts' => 0,
        ]), ['ex' => 600]);
    }

    private function redis(): Redis
    {
        return ApplicationContext::getContainer()->get(Redis::class);
    }

    private function createMerchant(): Merchant
    {
        $merchant = Merchant::create([
            'type' => 'company',
            'phone' => '188' . random_int(10000000, 99999999),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'status' => 'active',
        ]);
        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function login(string $username, string $password): ?string
    {
        $response = $this->client->request('POST', '/merchant/auth/login', [
            'form_params' => ['username' => $username, 'password' => $password],
        ]);

        return json_decode((string) $response->getBody(), true)['token'] ?? null;
    }

    private function me(string $token)
    {
        return $this->client->request('GET', '/merchant/auth/me', ['headers' => ['Authorization' => 'Bearer ' . $token]]);
    }

    private function changePassword(string $token, string $old, string $new)
    {
        return $this->client->request('PUT', '/merchant/auth/password', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['old_password' => $old, 'new_password' => $new],
        ]);
    }

    private function sendCode(string $username)
    {
        return $this->client->request('POST', '/merchant/auth/password/reset-code', ['form_params' => ['phone' => $username]]);
    }

    private function reset(string $username, string $code, string $newPassword)
    {
        return $this->client->request('POST', '/merchant/auth/password/reset', [
            'form_params' => ['phone' => $username, 'code' => $code, 'new_password' => $newPassword],
        ]);
    }
}
