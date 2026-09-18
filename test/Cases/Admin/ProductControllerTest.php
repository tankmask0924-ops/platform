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
use App\Model\ProductLevelRebate;
use App\Service\Product\RebateCalculator;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * 系统管理后台「本地商品库：CRUD + 各等级比例覆盖」（requirements.md 5.2/8.3），
 * docs/modules.md 第 8 节。结构跟 MerchantLevelControllerTest 一致，额外覆盖
 * 「设置覆盖 → 删除覆盖」这条这个任务才引入的、跟 RebateCalculator 的联调路径
 * （merchant-level 任务没有等价用例：那边没有删除接口）。
 *
 * @internal
 * @coversNothing
 */
class ProductControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private const VIEW_PERMISSION_CODE = 'product.view';

    private const MANAGE_PERMISSION_CODE = 'product.manage';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $productIds = [];

    private array $levelIds = [];

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
        foreach ($this->productIds as $id) {
            ProductLevelRebate::where('product_id', $id)->delete();
            Product::destroy($id);
        }
        foreach ($this->levelIds as $id) {
            MerchantLevelBusinessRate::where('level_id', $id)->delete();
            MerchantLevel::destroy($id);
        }
        $this->adminUserIds = [];
        $this->roleIds = [];
        $this->permissionIds = [];
        $this->productIds = [];
        $this->levelIds = [];

        parent::tearDown();
    }

    public function testCreateRechargeProductDefaultsToOffShelf()
    {
        $response = $this->jsonRequest('POST', '/admin/products', $this->manageToken(), $this->rechargePayload());
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->productIds[] = $body['id'];
        $this->assertSame('recharge', $body['business_line']);
        $this->assertSame('mobile', $body['operator']);
        $this->assertNull($body['card_type']);
        // 新建商品默认下架，见 ProductAdminService::DEFAULT_STATUS 类注释。
        $this->assertSame('off_shelf', $body['status']);

        $product = Product::query()->find($body['id']);
        $this->assertSame('off_shelf', $product->status);
    }

    public function testCreateCardProduct()
    {
        $response = $this->jsonRequest('POST', '/admin/products', $this->manageToken(), $this->cardPayload());
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->productIds[] = $body['id'];
        $this->assertSame('card', $body['business_line']);
        $this->assertSame('direct', $body['card_type']);
        $this->assertNull($body['operator']);
        $this->assertNull($body['charge_speed']);
    }

    public function testCreateWithInvalidBusinessLineReturns422()
    {
        $payload = $this->rechargePayload();
        $payload['business_line'] = 'movie';

        $response = $this->jsonRequest('POST', '/admin/products', $this->manageToken(), $payload);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testCreateRechargeWithCardTypeReturns422()
    {
        $payload = $this->rechargePayload();
        $payload['card_type'] = 'direct';

        $response = $this->jsonRequest('POST', '/admin/products', $this->manageToken(), $payload);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testCreateCardWithOperatorReturns422()
    {
        $payload = $this->cardPayload();
        $payload['operator'] = 'mobile';

        $response = $this->jsonRequest('POST', '/admin/products', $this->manageToken(), $payload);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testCreateMissingRequiredFieldForBusinessLineReturns422()
    {
        $payload = $this->rechargePayload();
        unset($payload['operator']);

        $response = $this->jsonRequest('POST', '/admin/products', $this->manageToken(), $payload);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testCreateWithNegativeSalePriceReturns422()
    {
        $payload = $this->rechargePayload();
        $payload['sale_price'] = '-1.00';

        $response = $this->jsonRequest('POST', '/admin/products', $this->manageToken(), $payload);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testCreateWithNegativeRebateAmountReturns422()
    {
        $payload = $this->rechargePayload();
        $payload['rebate_amount'] = '-0.01';

        $response = $this->jsonRequest('POST', '/admin/products', $this->manageToken(), $payload);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testUpdatePartialChangesOnlyGivenFields()
    {
        $product = $this->createRechargeProduct();

        $response = $this->jsonRequest('PUT', '/admin/products/' . $product->id, $this->manageToken(), [
            'sale_price' => '88.00',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $product->refresh();
        $this->assertSame('88.00', $product->sale_price);
        $this->assertSame('mobile', $product->operator);
        $this->assertSame('recharge', $product->business_line);
        $this->assertSame('100.00', $product->face_value);
    }

    public function testUpdateNonexistentProductReturns404()
    {
        $response = $this->jsonRequest('PUT', '/admin/products/999999999', $this->manageToken(), [
            'sale_price' => '1.00',
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDetailShowsEmptyOverridesList()
    {
        $product = $this->createRechargeProduct();

        $response = $this->jsonRequest('GET', '/admin/products/' . $product->id, $this->viewToken());
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $body['level_rebates']);
    }

    public function testDetailShowsLevelRebateOverridesWithJoinedNames()
    {
        $product = $this->createRechargeProduct();
        $level = $this->createLevel();
        $token = $this->manageToken();

        $this->assertSame(200, $this->setLevelRebate($token, $product->id, $level->id, '0.8000')->getStatusCode());

        $response = $this->jsonRequest('GET', '/admin/products/' . $product->id, $this->viewToken());
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['level_rebates']);
        $this->assertSame($level->id, $body['level_rebates'][0]['level_id']);
        $this->assertSame($level->name, $body['level_rebates'][0]['level_name']);
        $this->assertSame('0.8000', $body['level_rebates'][0]['rebate_rate']);
    }

    public function testDetailForNonexistentProductReturns404()
    {
        $response = $this->jsonRequest('GET', '/admin/products/999999999', $this->viewToken());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testListFiltersByBusinessLineAndStatus()
    {
        $recharge = $this->createRechargeProduct();
        $card = $this->createCardProduct();

        $response = $this->jsonRequest('GET', '/admin/products?business_line=card', $this->viewToken());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $ids = array_column($body['data'], 'id');
        $this->assertContains($card->id, $ids);
        $this->assertNotContains($recharge->id, $ids);

        $response = $this->jsonRequest('GET', '/admin/products?status=off_shelf', $this->viewToken());
        $body = json_decode((string) $response->getBody(), true);
        $ids = array_column($body['data'], 'id');
        $this->assertContains($recharge->id, $ids);
        $this->assertContains($card->id, $ids);

        $response = $this->jsonRequest('GET', '/admin/products?status=on_shelf', $this->viewToken());
        $body = json_decode((string) $response->getBody(), true);
        $ids = array_column($body['data'], 'id');
        $this->assertNotContains($recharge->id, $ids);
        $this->assertNotContains($card->id, $ids);
    }

    public function testStatusToggle()
    {
        $product = $this->createRechargeProduct();
        $token = $this->manageToken();

        $response = $this->jsonRequest('POST', '/admin/products/' . $product->id . '/status', $token, ['status' => 'on_shelf']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('on_shelf', $product->refresh()->status);

        $response = $this->jsonRequest('POST', '/admin/products/' . $product->id . '/status', $token, ['status' => 'off_shelf']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('off_shelf', $product->refresh()->status);
    }

    public function testStatusInvalidValueReturns422()
    {
        $product = $this->createRechargeProduct();

        $response = $this->jsonRequest(
            'POST',
            '/admin/products/' . $product->id . '/status',
            $this->manageToken(),
            ['status' => 'not_a_status']
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('off_shelf', $product->refresh()->status);
    }

    public function testSetLevelRebateCreatesThenUpdatesInPlace()
    {
        $product = $this->createRechargeProduct();
        $level = $this->createLevel();
        $token = $this->manageToken();

        $first = $this->setLevelRebate($token, $product->id, $level->id, '0.8000');
        $this->assertSame(200, $first->getStatusCode());
        $rows = ProductLevelRebate::where('product_id', $product->id)->where('level_id', $level->id)->get();
        $this->assertCount(1, $rows);
        $firstId = $rows->first()->id;

        $second = $this->setLevelRebate($token, $product->id, $level->id, '0.9000');
        $this->assertSame(200, $second->getStatusCode());
        $rows = ProductLevelRebate::where('product_id', $product->id)->where('level_id', $level->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($firstId, $rows->first()->id);
        $this->assertSame('0.9000', $rows->first()->rebate_rate);
    }

    public function testSetLevelRebateWithNonexistentLevelReturns404()
    {
        $product = $this->createRechargeProduct();

        $response = $this->setLevelRebate($this->manageToken(), $product->id, 999999999, '0.5');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDeleteLevelRebateRemovesRow()
    {
        $product = $this->createRechargeProduct();
        $level = $this->createLevel();
        $token = $this->manageToken();

        $this->assertSame(200, $this->setLevelRebate($token, $product->id, $level->id, '0.8000')->getStatusCode());

        $response = $this->deleteLevelRebate($token, $product->id, $level->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, ProductLevelRebate::where('product_id', $product->id)->where('level_id', $level->id)->count());
    }

    public function testDeleteNonexistentLevelRebateReturns404()
    {
        $product = $this->createRechargeProduct();
        $level = $this->createLevel();

        $response = $this->deleteLevelRebate($this->manageToken(), $product->id, $level->id);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testLevelRebateOverrideFeedsRebateCalculatorAndFallsBackAfterDelete()
    {
        $product = $this->createRechargeProduct(['rebate_amount' => '0.50']);
        $level = $this->createLevel();
        $token = $this->manageToken();

        // 等级在该业务线的通用比例：75%（requirements.md 5.3 例 1 银牌档位）。
        MerchantLevelBusinessRate::create([
            'level_id' => $level->id,
            'business_line' => 'recharge',
            'rebate_rate' => '0.7500',
        ]);

        $calculator = make(RebateCalculator::class);
        $before = $calculator->calculateDetailed($product, $level->id);
        $this->assertSame('level', $before->rateSource);
        $this->assertSame('0.37', $before->amount);

        // 商品对该等级单独设置比例为 90%。
        $this->assertSame(200, $this->setLevelRebate($token, $product->id, $level->id, '0.9000')->getStatusCode());

        $duringOverride = $calculator->calculateDetailed($product, $level->id);
        $this->assertSame('product_level', $duringOverride->rateSource);
        $this->assertSame('0.45', $duringOverride->amount);

        // 删除商品单独设置的比例，回退到该等级在该业务线的通用比例。
        $deleteResponse = $this->deleteLevelRebate($token, $product->id, $level->id);
        $this->assertSame(200, $deleteResponse->getStatusCode());

        $afterDelete = $calculator->calculateDetailed($product, $level->id);
        $this->assertSame('level', $afterDelete->rateSource);
        $this->assertSame('0.37', $afterDelete->amount);
    }

    public function testViewOnlyAdminGets403OnMutations()
    {
        $product = $this->createRechargeProduct();
        $level = $this->createLevel();
        $token = $this->viewToken();

        $this->assertSame(
            403,
            $this->jsonRequest('POST', '/admin/products', $token, $this->rechargePayload())->getStatusCode()
        );
        $this->assertSame(
            403,
            $this->jsonRequest('PUT', '/admin/products/' . $product->id, $token, ['sale_price' => '1.00'])->getStatusCode()
        );
        $this->assertSame(
            403,
            $this->jsonRequest(
                'POST',
                '/admin/products/' . $product->id . '/status',
                $token,
                ['status' => 'on_shelf']
            )->getStatusCode()
        );
        $this->assertSame(403, $this->setLevelRebate($token, $product->id, $level->id, '0.5')->getStatusCode());
        $this->assertSame(403, $this->deleteLevelRebate($token, $product->id, $level->id)->getStatusCode());
        $this->assertSame(0, ProductLevelRebate::where('product_id', $product->id)->count());

        // view 权限本身能正常读。
        $this->assertSame(200, $this->jsonRequest('GET', '/admin/products', $token)->getStatusCode());
        $this->assertSame(200, $this->jsonRequest('GET', '/admin/products/' . $product->id, $token)->getStatusCode());
    }

    public function testNoTokenAtAllReturns401OnEveryRoute()
    {
        $product = $this->createRechargeProduct();
        $level = $this->createLevel();
        $base = '/admin/products';

        $this->assertSame(401, $this->client->request('GET', $base)->getStatusCode());
        $this->assertSame(401, $this->client->request('GET', $base . '/' . $product->id)->getStatusCode());
        $this->assertSame(401, $this->client->request('POST', $base)->getStatusCode());
        $this->assertSame(401, $this->client->request('PUT', $base . '/' . $product->id)->getStatusCode());
        $this->assertSame(401, $this->client->request('POST', $base . '/' . $product->id . '/status')->getStatusCode());
        $this->assertSame(
            401,
            $this->client->request('PUT', $base . '/' . $product->id . '/level-rebates/' . $level->id)->getStatusCode()
        );
        $this->assertSame(
            401,
            $this->client->request('DELETE', $base . '/' . $product->id . '/level-rebates/' . $level->id)->getStatusCode()
        );
    }

    private function rechargePayload(): array
    {
        return [
            'business_line' => 'recharge',
            'name' => '移动测试商品_' . bin2hex(random_bytes(4)),
            'operator' => 'mobile',
            'face_value' => '100.00',
            'sale_price' => '99.00',
            'rebate_amount' => '0.50',
        ];
    }

    private function cardPayload(): array
    {
        return [
            'business_line' => 'card',
            'name' => '卡券测试商品_' . bin2hex(random_bytes(4)),
            'card_type' => 'direct',
            'face_value' => '50.00',
            'sale_price' => '48.00',
            'rebate_amount' => '0.20',
        ];
    }

    private function createRechargeProduct(array $overrides = []): Product
    {
        $product = Product::create(array_merge([
            'business_line' => 'recharge',
            'name' => '移动测试商品_' . bin2hex(random_bytes(4)),
            'operator' => 'mobile',
            'face_value' => '100.00',
            'sale_price' => '99.00',
            'rebate_amount' => '0.50',
            'status' => 'off_shelf',
        ], $overrides));
        $this->productIds[] = $product->id;

        return $product;
    }

    private function createCardProduct(): Product
    {
        $product = Product::create([
            'business_line' => 'card',
            'name' => '卡券测试商品_' . bin2hex(random_bytes(4)),
            'card_type' => 'direct',
            'face_value' => '50.00',
            'sale_price' => '48.00',
            'rebate_amount' => '0.20',
            'status' => 'off_shelf',
        ]);
        $this->productIds[] = $product->id;

        return $product;
    }

    private function createLevel(): MerchantLevel
    {
        $level = MerchantLevel::create(['name' => 'lv_' . bin2hex(random_bytes(8))]);
        $this->levelIds[] = $level->id;

        return $level;
    }

    private function setLevelRebate(string $token, int $productId, int $levelId, mixed $rate)
    {
        return $this->jsonRequest(
            'PUT',
            '/admin/products/' . $productId . '/level-rebates/' . $levelId,
            $token,
            ['rebate_rate' => $rate]
        );
    }

    private function deleteLevelRebate(string $token, int $productId, int $levelId)
    {
        return $this->client->request(
            'DELETE',
            '/admin/products/' . $productId . '/level-rebates/' . $levelId,
            ['headers' => ['Authorization' => 'Bearer ' . $token]]
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
                ['module' => 'product', 'name' => $code, 'type' => 'action']
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
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'real_name' => 'Test Admin',
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $this->adminUserIds[] = $admin->id;

        return $admin;
    }
}
