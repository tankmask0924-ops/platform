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
use App\OpenApi\ErrorCode;
use HyperfTest\HttpTestCase;

/**
 * 商户后台接口文档的错误码表 `GET /merchant/api-docs/error-codes`：跟 ErrorCode 枚举同源。
 *
 * @internal
 * @coversNothing
 */
class ApiDocControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $merchantIds = [];

    protected function tearDown(): void
    {
        Merchant::destroy($this->merchantIds);

        parent::tearDown();
    }

    public function testListsEveryErrorCode()
    {
        $merchant = Merchant::create([
            'type' => 'company',
            'phone' => '186' . random_int(10000000, 99999999),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'status' => 'pending',
        ]);
        $this->merchantIds[] = $merchant->id;
        $login = $this->client->request('POST', '/merchant/auth/login', [
            'form_params' => ['username' => $merchant->phone, 'password' => self::PASSWORD],
        ]);
        $token = json_decode((string) $login->getBody(), true)['token'];

        $response = $this->client->request('GET', '/merchant/api-docs/error-codes', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->assertSame(200, $response->getStatusCode());

        $rows = array_column(json_decode((string) $response->getBody(), true)['data'], null, 'code');
        $this->assertCount(count(ErrorCode::cases()), $rows);
        $this->assertSame(['code' => 40005, 'message' => '签名错误', 'http_status' => 401, 'order_failure' => false], $rows[40005]);
        $this->assertSame(200, $rows[42007]['http_status']);
        $this->assertTrue($rows[43001]['order_failure']);

        $this->assertSame(401, $this->client->request('GET', '/merchant/api-docs/error-codes')->getStatusCode());
    }
}
