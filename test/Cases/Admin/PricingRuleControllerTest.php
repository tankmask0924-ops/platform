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

use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use App\Model\AdminUser;
use App\Model\PricingRule;
use App\Service\Product\PricingRuleService;
use HyperfTest\HttpTestCase;
use RuntimeException;

use function Hyperf\Support\make;

/**
 * 系统管理后台「价格设置」（requirements.md 8.3、5.1）：电影票/快递加价规则、价格预览，
 * 以及下单链路用的 PricingRuleService::salePriceFor()。
 *
 * `pricing_rules` 每条业务线只有一行，是**全局配置**，这些用例必须改它才能测。共享的
 * 测试库里可能已经配了真实规则，所以 setUp 先把现有行原样备份并清空、tearDown 再原样还原：
 * 既让用例跑在确定的空状态上，也不会把别人配好的加价规则跑没了（踩过：先写成直接
 * 删表里所有行，联调时在后台配的两条规则被下一次跑测试删掉了）。
 *
 * @internal
 * @coversNothing
 */
class PricingRuleControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    /**
     * 用例开始前已经存在的规则行，tearDown 原样还原，见类注释。
     *
     * @var list<array<string, mixed>>
     */
    private array $existingRules = [];

    protected function setUp(): void
    {
        $this->existingRules = PricingRule::query()->get()
            ->map(static fn (PricingRule $rule) => [
                'business_line' => $rule->business_line,
                'rule_type' => $rule->rule_type,
                'value' => $rule->value,
                'updated_by' => $rule->updated_by,
                'created_at' => $rule->created_at?->toDateTimeString(),
                'updated_at' => $rule->updated_at?->toDateTimeString(),
            ])
            ->all();
        PricingRule::query()->delete();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        PricingRule::query()->delete();
        foreach ($this->existingRules as $rule) {
            PricingRule::query()->insert($rule);
        }
        $this->existingRules = [];
        AdminUser::destroy($this->adminUserIds);
        AdminRolePermission::whereIn('role_id', $this->roleIds ?: [0])->delete();
        AdminRole::destroy($this->roleIds);
        AdminPermission::destroy($this->permissionIds);

        parent::tearDown();
    }

    /**
     * 没配过的业务线返回 null 规则，不是 0——前端要能分清"没设置"和"加价 0 元"。
     */
    public function testUnsetBusinessLinesComeBackAsNullNotZero()
    {
        $token = $this->loginWith(['pricing.view']);

        $rules = array_column($this->getJson('/admin/pricing-rules', $token)['data'], null, 'business_line');

        $this->assertCount(2, $rules, '电影票、快递两条固定业务线');
        $this->assertNull($rules['express']['rule_type']);
        $this->assertNull($rules['express']['value']);
        $this->assertNull($rules['movie']['updated_at']);
    }

    public function testSavingAFixedRuleRecordsTheOperator()
    {
        $token = $this->loginWith(['pricing.view', 'pricing.manage']);

        $list = $this->putJson('/admin/pricing-rules/express', $token, ['rule_type' => 'fixed', 'value' => '2']);

        $express = array_column($list['data'], null, 'business_line')['express'];
        $this->assertSame('fixed', $express['rule_type']);
        $this->assertSame('2.00', $express['value'], '固定金额按元显示两位小数');
        $this->assertSame('Test Admin', $express['updated_by']);
        $this->assertNotNull($express['updated_at']);
    }

    public function testSavingTwiceUpdatesInPlaceInsteadOfInsertingASecondRow()
    {
        $token = $this->loginWith(['pricing.view', 'pricing.manage']);

        $this->putJson('/admin/pricing-rules/movie', $token, ['rule_type' => 'fixed', 'value' => '1.50']);
        $this->putJson('/admin/pricing-rules/movie', $token, ['rule_type' => 'percentage', 'value' => '0.08']);

        $this->assertSame(1, PricingRule::query()->where('business_line', 'movie')->count());
        $movie = array_column($this->getJson('/admin/pricing-rules', $token)['data'], null, 'business_line')['movie'];
        $this->assertSame('percentage', $movie['rule_type']);
        $this->assertSame('0.0800', $movie['value'], '百分比保留 4 位（它是比例）');
    }

    /**
     * requirements.md 5.1「按百分比算出的售价四舍五入到分」——跟商户返佣的"向下取整"
     * 方向相反，这里专门验一次进位。
     */
    public function testPercentageSalePriceIsRoundedHalfUpToCents()
    {
        $token = $this->loginWith(['pricing.view', 'pricing.manage']);
        $this->putJson('/admin/pricing-rules/express', $token, ['rule_type' => 'percentage', 'value' => '0.05']);

        // 0.10 × 1.05 = 0.105 → 进位到 0.11（截断的话会是 0.10）
        $this->assertSame('0.11', $this->preview($token, 'express', '0.10')['sale_price']);
        // 10.05 × 1.05 = 10.5525 → 10.55
        $this->assertSame('10.55', $this->preview($token, 'express', '10.05')['sale_price']);
        // 9.99 × 1.03 用未保存的规则预览
        $this->assertSame('10.29', $this->preview($token, 'express', '9.99', 'percentage', '0.03')['sale_price']);
    }

    public function testFixedRulePreviewShowsSalePriceAndGrossProfit()
    {
        $token = $this->loginWith(['pricing.view', 'pricing.manage']);
        $this->putJson('/admin/pricing-rules/express', $token, ['rule_type' => 'fixed', 'value' => '2']);

        // requirements.md 7.2 的结算举例：运费成本 10 元 → 售价 12 元
        $preview = $this->preview($token, 'express', '10.00');

        $this->assertSame('12.00', $preview['sale_price']);
        $this->assertSame('2.00', $preview['gross_profit']);
        $this->assertSame('fixed', $preview['rule_type']);
    }

    /**
     * 预览未保存的规则不会把它存进去——运营调参数试算时不该改到线上价格。
     */
    public function testPreviewingAnUnsavedRuleDoesNotPersistIt()
    {
        $token = $this->loginWith(['pricing.view']);

        $preview = $this->preview($token, 'movie', '38.00', 'fixed', '2');

        $this->assertSame('40.00', $preview['sale_price']);
        $this->assertSame(0, PricingRule::query()->where('business_line', 'movie')->count());
    }

    public function testPreviewWithoutARuleIsRejected()
    {
        $token = $this->loginWith(['pricing.view']);

        $response = $this->client->request('GET', '/admin/pricing-rules/preview?business_line=movie&cost=10', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testInvalidRulesAreRejected()
    {
        $token = $this->loginWith(['pricing.view', 'pricing.manage']);

        $cases = [
            ['express', ['rule_type' => 'nope', 'value' => '1']],
            ['express', ['rule_type' => 'fixed', 'value' => 'abc']],
            // 加价为负一定低于成本价（5.5）
            ['express', ['rule_type' => 'fixed', 'value' => '-1']],
            ['express', ['rule_type' => 'fixed', 'value' => '10000']],
            ['express', ['rule_type' => 'percentage', 'value' => '11']],
            // 话费、卡券的售价在商品上直接设置，不走这张表
            ['recharge', ['rule_type' => 'fixed', 'value' => '1']],
        ];
        foreach ($cases as [$businessLine, $payload]) {
            $response = $this->put('/admin/pricing-rules/' . $businessLine, $token, $payload);
            $this->assertSame(422, $response->getStatusCode(), $businessLine . ':' . json_encode($payload));
        }

        foreach (['business_line=recharge&cost=1', 'business_line=express&cost=-1', 'business_line=express&cost=abc'] as $query) {
            $response = $this->client->request('GET', '/admin/pricing-rules/preview?' . $query, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);
            $this->assertSame(422, $response->getStatusCode(), $query);
        }
    }

    public function testViewPermissionCannotChangeRules()
    {
        $viewer = $this->loginWith(['pricing.view']);

        $this->assertSame(200, $this->client->request('GET', '/admin/pricing-rules', [
            'headers' => ['Authorization' => 'Bearer ' . $viewer],
        ])->getStatusCode());
        $this->assertSame(403, $this->put('/admin/pricing-rules/express', $viewer, ['rule_type' => 'fixed', 'value' => '2'])->getStatusCode());
    }

    /**
     * 下单链路用的入口：没配规则必须抛错，绝不能"没配就按成本卖"。
     */
    public function testSalePriceForThrowsWhenNoRuleIsConfigured()
    {
        $service = make(PricingRuleService::class);

        $this->expectException(RuntimeException::class);
        $service->salePriceFor('express', '10.00');
    }

    public function testSalePriceForUsesTheSavedRule()
    {
        $token = $this->loginWith(['pricing.manage']);
        $this->putJson('/admin/pricing-rules/express', $token, ['rule_type' => 'fixed', 'value' => '2']);

        $this->assertSame('12.00', make(PricingRuleService::class)->salePriceFor('express', '10.00'));
    }

    /**
     * @return array<string, mixed>
     */
    private function preview(string $token, string $businessLine, string $cost, ?string $ruleType = null, ?string $value = null): array
    {
        $query = ['business_line' => $businessLine, 'cost' => $cost];
        if ($ruleType !== null) {
            $query['rule_type'] = $ruleType;
            $query['value'] = $value;
        }

        return $this->getJson('/admin/pricing-rules/preview?' . http_build_query($query), $token);
    }

    private function getJson(string $path, string $token): array
    {
        $response = $this->client->request('GET', $path, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function putJson(string $path, string $token, array $data): array
    {
        $response = $this->put($path, $token, $data);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function put(string $path, string $token, array $data)
    {
        return $this->client->request('PUT', $path, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'json' => $data,
        ]);
    }

    /**
     * @param list<string> $codes
     */
    private function loginWith(array $codes): string
    {
        $role = AdminRole::create(['name' => 'role_' . uniqid('', true), 'is_system' => false]);
        $this->roleIds[] = $role->id;
        foreach ($codes as $code) {
            $permission = AdminPermission::firstOrCreate(['code' => $code], ['module' => 'pricing', 'name' => $code, 'type' => 'action']);
            if ($permission->wasRecentlyCreated) {
                $this->permissionIds[] = $permission->id;
            }
            AdminRolePermission::create(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }

        $admin = AdminUser::create([
            'username' => 'admin_' . uniqid('', true),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'real_name' => 'Test Admin',
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $this->adminUserIds[] = $admin->id;

        $login = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => ['username' => $admin->username, 'password' => self::PASSWORD],
        ]);

        return json_decode((string) $login->getBody(), true)['token'];
    }
}
