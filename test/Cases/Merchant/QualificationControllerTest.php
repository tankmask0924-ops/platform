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
use App\Model\MerchantLevel;
use App\Model\MerchantQualification;
use App\Service\Admin\MerchantAdminService;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * 商户后台「资质资料提交与审核状态」`GET/POST /merchant/qualification`（requirements.md 4.1、8.2）：
 * 注册 → 驳回 → 重新提交（可换类型）→ 审核通过的整条链路，审核动作直接调 MerchantAdminService。
 *
 * @internal
 * @coversNothing
 */
class QualificationControllerTest extends HttpTestCase
{
    private const PASSWORD = 'password123';

    private array $merchantIds = [];

    private array $levelIds = [];

    protected function tearDown(): void
    {
        MerchantQualification::whereIn('merchant_id', $this->merchantIds)->delete();
        Merchant::destroy($this->merchantIds);
        MerchantLevel::destroy($this->levelIds);
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testPendingMerchantSeesMaskedQualificationAndCannotResubmit()
    {
        [$merchantId, $token] = $this->registerIndividual('110101199001011234');

        $body = $this->show($token);
        $this->assertSame('pending', $body['status']);
        $this->assertFalse($body['can_resubmit']);
        $this->assertNull($body['level_name']);
        $this->assertSame('individual', $body['qualification']['type']);
        $this->assertSame('**************1234', $body['qualification']['id_card_no_masked']);
        $this->assertArrayNotHasKey('id_card_no', $body['qualification']);
        $this->assertCount(1, $body['history']);

        $response = $this->submit($token, $this->companyData());
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(1, MerchantQualification::where('merchant_id', $merchantId)->count());
    }

    public function testRejectedMerchantResubmitsAsCompanyAndGetsApproved()
    {
        [$merchantId, $token] = $this->registerIndividual('110101199001011234');
        $admin = make(MerchantAdminService::class);
        $admin->reject($merchantId, '身份证号与姓名不一致', 1);

        $body = $this->show($token);
        $this->assertSame('rejected', $body['status']);
        $this->assertTrue($body['can_resubmit']);
        $this->assertSame('身份证号与姓名不一致', $body['qualification']['reject_reason']);

        // 缺必填字段：不落库、状态不变
        $invalid = $this->companyData();
        unset($invalid['legal_person_name']);
        $this->assertSame(422, $this->submit($token, $invalid)->getStatusCode());
        $this->assertSame('rejected', Merchant::find($merchantId)->status);

        $response = $this->submit($token, $this->companyData());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('pending', $body['status']);
        $this->assertSame('company', $body['type']);
        $this->assertSame('Acme Inc', $body['qualification']['company_name']);
        $this->assertNull($body['qualification']['id_card_no_masked']);
        $this->assertSame(['pending', 'rejected'], array_column($body['history'], 'status'));

        $merchant = Merchant::find($merchantId);
        $this->assertSame('pending', $merchant->status);
        $this->assertSame('company', $merchant->type);

        // 审核针对的是新提交的那条
        $level = MerchantLevel::create(['name' => 'qualification_test_' . uniqid()]);
        $this->levelIds[] = $level->id;
        $admin->approve($merchantId, $level->id, 1);

        $body = $this->show($token);
        $this->assertSame('active', $body['status']);
        $this->assertSame($level->name, $body['level_name']);
        $this->assertSame(['approved', 'rejected'], array_column($body['history'], 'status'));
        $this->assertSame(409, $this->submit($token, $this->companyData())->getStatusCode());

        $detail = $admin->detail($merchantId);
        $this->assertSame('approved', $detail['qualification']['status']);
        $this->assertSame('Acme Inc', $detail['qualification']['company_name']);
        $this->assertCount(2, $detail['qualification_history']);
    }

    public function testNoTokenReturns401()
    {
        $this->assertSame(401, $this->client->request('GET', '/merchant/qualification')->getStatusCode());
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function registerIndividual(string $idCardNo): array
    {
        $phone = '137' . random_int(10000000, 99999999);
        $response = $this->client->request('POST', '/merchant/auth/register', [
            'form_params' => [
                'type' => 'individual',
                'phone' => $phone,
                'password' => self::PASSWORD,
                'id_card_name' => '张三',
                'id_card_no' => $idCardNo,
                'contact_phone' => $phone,
            ],
        ]);
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->merchantIds[] = $body['id'];

        $login = $this->client->request('POST', '/merchant/auth/login', [
            'form_params' => ['username' => $phone, 'password' => self::PASSWORD],
        ]);

        return [$body['id'], (string) json_decode((string) $login->getBody(), true)['token']];
    }

    private function companyData(): array
    {
        return [
            'type' => 'company',
            'company_name' => 'Acme Inc',
            'business_license_no' => 'BL' . uniqid(),
            'legal_person_name' => '王五',
            'contact_name' => '李四',
            'contact_phone' => '13900000001',
        ];
    }

    private function show(string $token): array
    {
        $response = $this->client->request('GET', '/merchant/qualification', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function submit(string $token, array $data)
    {
        return $this->client->request('POST', '/merchant/qualification', [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'json' => $data,
        ]);
    }
}
