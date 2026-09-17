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
use App\Model\MerchantLevel;
use App\Model\MerchantLevelBusinessRate;
use App\Model\Product;
use App\Service\Product\RebateCalculator;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * 系统管理后台「商户等级：CRUD / 各业务线比例设置」（requirements.md 5.2），
 * docs/modules.md 第 8 节。结构跟 SupplierControllerTest 一致，另加一条跟
 * App\Service\Product\RebateCalculator 的联调断言：后台写入的比例能被计算器读到。
 *
 * @internal
 * @coversNothing
 */
class MerchantLevelControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private const VIEW_PERMISSION_CODE = 'merchant_level.view';

    private const MANAGE_PERMISSION_CODE = 'merchant_level.manage';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $levelIds = [];

    private array $productIds = [];

    protected function tearDown(): void
    {
        foreach ($this->adminUserIds as $id) {
            AdminUser::destroy($id);
        }
        foreach ($this->roleIds as $id) {
            AdminRolePermission::where('role_id', $id)->delete();
            AdminRole::destroy($id);
        }
        foreach ($this->permissionIds as $id) {
            AdminPermission::destroy($id);
        }
        foreach ($this->levelIds as $id) {
            MerchantLevelBusinessRate::where('level_id', $id)->delete();
            MerchantLevel::destroy($id);
        }
        foreach ($this->productIds as $id) {
            Product::destroy($id);
        }
        $this->adminUserIds = [];
        $this->roleIds = [];
        $this->permissionIds = [];
        $this->levelIds = [];
        $this->productIds = [];

        parent::tearDown();
    }

    public function testCreateStoresLevel()
    {
        $token = $this->manageToken();
        $name = $this->uniqueName();

        $response = $this->jsonRequest('POST', '/admin/merchant-levels', $token, [
            'name' => $name,
            'remark' => '测试等级',
        ]);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->levelIds[] = $body['id'];
        $this->assertSame($name, $body['name']);

        $level = MerchantLevel::query()->find($body['id']);
        $this->assertNotNull($level);
        $this->assertSame($name, $level->name);
        $this->assertSame('测试等级', $level->remark);
    }

    public function testCreateWithEmptyNameReturns422()
    {
        $response = $this->jsonRequest('POST', '/admin/merchant-levels', $this->manageToken(), ['name' => '  ']);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testCreateWithDuplicateNameReturnsCleanError()
    {
        $existing = $this->createLevel();

        $response = $this->jsonRequest('POST', '/admin/merchant-levels', $this->manageToken(), [
            'name' => $existing->name,
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(1, MerchantLevel::where('name', $existing->name)->count());
    }

    public function testUpdateChangesFields()
    {
        $level = $this->createLevel();
        $newName = $this->uniqueName();

        $response = $this->jsonRequest('PUT', '/admin/merchant-levels/' . $level->id, $this->manageToken(), [
            'name' => $newName,
            'remark' => '改过的备注',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $level->refresh();
        $this->assertSame($newName, $level->name);
        $this->assertSame('改过的备注', $level->remark);
    }

    public function testUpdateKeepingSameNameIsAllowed()
    {
        $level = $this->createLevel();

        $response = $this->jsonRequest('PUT', '/admin/merchant-levels/' . $level->id, $this->manageToken(), [
            'name' => $level->name,
            'remark' => '只改备注',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('只改备注', $level->refresh()->remark);
    }

    public function testUpdateNonexistentLevelReturns404()
    {
        $response = $this->jsonRequest('PUT', '/admin/merchant-levels/999999999', $this->manageToken(), [
            'name' => $this->uniqueName(),
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateRenameToDuplicateNameReturns422()
    {
        $first = $this->createLevel();
        $second = $this->createLevel();

        $response = $this->jsonRequest('PUT', '/admin/merchant-levels/' . $second->id, $this->manageToken(), [
            'name' => $first->name,
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotSame($first->name, $second->refresh()->name);
    }

    public function testListReturnsCreatedLevels()
    {
        $level = $this->createLevel();

        $response = $this->jsonRequest('GET', '/admin/merchant-levels', $this->viewToken());
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains($level->id, array_column($body['data'], 'id'));
        $this->assertSame(count($body['data']), $body['total']);
    }

    public function testDetailDistinguishesUnsetRateFromZeroRate()
    {
        $level = $this->createLevel();
        $token = $this->manageToken();

        $this->assertSame(200, $this->setRate($token, $level->id, 'recharge', '0.9000')->getStatusCode());
        $this->assertSame(200, $this->setRate($token, $level->id, 'card', '0')->getStatusCode());

        $response = $this->jsonRequest('GET', '/admin/merchant-levels/' . $level->id, $this->viewToken());
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($level->name, $body['name']);
        $this->assertSame(['recharge', 'card', 'movie', 'express'], array_keys($body['rates']));
        $this->assertSame('0.9000', $body['rates']['recharge']);
        // 明确设置为 0 → 字符串 '0.0000'；从未设置 → null。
        $this->assertSame('0.0000', $body['rates']['card']);
        $this->assertNull($body['rates']['movie']);
        $this->assertNull($body['rates']['express']);
    }

    public function testDetailForNonexistentLevelReturns404()
    {
        $response = $this->jsonRequest('GET', '/admin/merchant-levels/999999999', $this->viewToken());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSetRateCreatesThenUpdatesInPlace()
    {
        $level = $this->createLevel();
        $token = $this->manageToken();

        $first = $this->setRate($token, $level->id, 'movie', '0.8');
        $this->assertSame(200, $first->getStatusCode());
        $rows = MerchantLevelBusinessRate::where('level_id', $level->id)->where('business_line', 'movie')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('0.8000', $rows->first()->rebate_rate);
        $firstId = $rows->first()->id;

        // 5.5 只要求超过 100% 时提示，不禁止：1.2 照样保存。
        $second = $this->setRate($token, $level->id, 'movie', 1.2);
        $body = json_decode((string) $second->getBody(), true);
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame('1.2000', $body['rebate_rate']);

        $rows = MerchantLevelBusinessRate::where('level_id', $level->id)->where('business_line', 'movie')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($firstId, $rows->first()->id);
        $this->assertSame('1.2000', $rows->first()->rebate_rate);
    }

    public function testSetRateWithInvalidBusinessLineReturns422()
    {
        $level = $this->createLevel();

        $response = $this->setRate($this->manageToken(), $level->id, 'not_a_line', '0.5');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, MerchantLevelBusinessRate::where('level_id', $level->id)->count());
    }

    public function testSetRateWithInvalidRateValuesReturns422()
    {
        $level = $this->createLevel();
        $token = $this->manageToken();

        foreach (['-0.1', -1, '', 'abc', '0.12345', '1e2', '100', null] as $invalid) {
            $response = $this->setRate($token, $level->id, 'recharge', $invalid);
            $this->assertSame(422, $response->getStatusCode(), 'rate: ' . var_export($invalid, true));
        }

        $this->assertSame(0, MerchantLevelBusinessRate::where('level_id', $level->id)->count());
    }

    public function testSetRateForNonexistentLevelReturns404()
    {
        $response = $this->setRate($this->manageToken(), 999999999, 'recharge', '0.5');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRateSetViaAdminApiIsUsedByRebateCalculator()
    {
        $level = $this->createLevel();
        $product = Product::create([
            'business_line' => 'recharge',
            'name' => '移动 100 元快充',
            'operator' => 'mobile',
            'face_value' => '100.00',
            'sale_price' => '99.20',
            'rebate_amount' => '0.50',
            'status' => 'on_shelf',
        ]);
        $this->productIds[] = $product->id;

        $calculator = make(RebateCalculator::class);
        // 还没设置比例 → 按 5.3 第 3 步视为 0%。
        $this->assertSame('0.00', $calculator->calculate($product, $level->id));

        $token = $this->manageToken();
        $this->assertSame(200, $this->setRate($token, $level->id, 'recharge', '0.75')->getStatusCode());

        // 5.3 例 1 银牌：0.50 × 75% = 0.375 → 向下取整 0.37。
        $result = $calculator->calculateDetailed($product, $level->id);
        $this->assertSame('0.37', $result->amount);
        $this->assertSame('level', $result->rateSource);
        $this->assertSame('0.7500', $result->rate);

        // 改比例后计算器读到的是新值。
        $this->assertSame(200, $this->setRate($token, $level->id, 'recharge', '1')->getStatusCode());
        $this->assertSame('0.50', $calculator->calculate($product, $level->id));
    }

    public function testViewOnlyAdminGets403OnManageActions()
    {
        $level = $this->createLevel();
        $token = $this->viewToken();

        $this->assertSame(
            403,
            $this->jsonRequest('POST', '/admin/merchant-levels', $token, ['name' => $this->uniqueName()])->getStatusCode()
        );
        $this->assertSame(
            403,
            $this->jsonRequest('PUT', '/admin/merchant-levels/' . $level->id, $token, ['name' => $this->uniqueName()])->getStatusCode()
        );
        $this->assertSame(403, $this->setRate($token, $level->id, 'recharge', '0.5')->getStatusCode());
        $this->assertSame(0, MerchantLevelBusinessRate::where('level_id', $level->id)->count());

        // view 权限本身能正常读。
        $this->assertSame(200, $this->jsonRequest('GET', '/admin/merchant-levels', $token)->getStatusCode());
        $this->assertSame(200, $this->jsonRequest('GET', '/admin/merchant-levels/' . $level->id, $token)->getStatusCode());
    }

    public function testNoTokenAtAllReturns401OnEveryRoute()
    {
        $level = $this->createLevel();
        $base = '/admin/merchant-levels';

        $this->assertSame(401, $this->client->request('GET', $base)->getStatusCode());
        $this->assertSame(401, $this->client->request('GET', $base . '/' . $level->id)->getStatusCode());
        $this->assertSame(401, $this->client->request('POST', $base)->getStatusCode());
        $this->assertSame(401, $this->client->request('PUT', $base . '/' . $level->id)->getStatusCode());
        $this->assertSame(401, $this->client->request('PUT', $base . '/' . $level->id . '/rates/recharge')->getStatusCode());
    }

    private function setRate(string $token, int $levelId, string $businessLine, mixed $rate)
    {
        return $this->jsonRequest(
            'PUT',
            '/admin/merchant-levels/' . $levelId . '/rates/' . $businessLine,
            $token,
            ['rebate_rate' => $rate]
        );
    }

    private function jsonRequest(string $method, string $path, string $token, array $data = [])
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
        ];
        if ($method !== 'GET') {
            $options['json'] = $data;
        }

        return $this->client->request($method, $path, $options);
    }

    private function manageToken(): string
    {
        return $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));
    }

    private function viewToken(): string
    {
        return $this->loginAs($this->createAdminWithPermissions([self::VIEW_PERMISSION_CODE]));
    }

    private function uniqueName(): string
    {
        // merchant_levels.name 最长 32 字符。
        return 'lv_' . bin2hex(random_bytes(8));
    }

    private function createLevel(): MerchantLevel
    {
        $level = MerchantLevel::create(['name' => $this->uniqueName()]);
        $this->levelIds[] = $level->id;

        return $level;
    }

    private function loginAs(AdminUser $admin): string
    {
        $login = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => [
                'username' => $admin->username,
                'password' => self::PASSWORD,
            ],
        ]);

        return json_decode((string) $login->getBody(), true)['token'];
    }

    private function createAdminWithPermissions(array $codes): AdminUser
    {
        $role = AdminRole::create([
            'name' => 'role_' . uniqid('', true),
            'is_system' => false,
        ]);
        $this->roleIds[] = $role->id;

        foreach ($codes as $code) {
            $permission = AdminPermission::firstOrCreate(
                ['code' => $code],
                ['module' => 'merchant_level', 'name' => $code, 'type' => 'action']
            );
            if ($permission->wasRecentlyCreated) {
                $this->permissionIds[] = $permission->id;
            }

            AdminRolePermission::create([
                'role_id' => $role->id,
                'permission_id' => $permission->id,
            ]);
        }

        $admin = AdminUser::create([
            'username' => 'admin_' . uniqid('', true),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'real_name' => 'Test Admin',
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $this->adminUserIds[] = $admin->id;

        return $admin;
    }
}
