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
use App\Model\Product;
use App\Model\Supplier;
use App\Model\SupplierProduct;
use App\Model\SupplierProductPriceHistory;
use HyperfTest\HttpTestCase;

/**
 * 系统管理后台（web/admin）「商品映射与成本价」（requirements.md 6.4），
 * docs/modules.md 第 8 节「供应商管理：商品映射」这一行。结构跟
 * test/Cases/Admin/SupplierControllerTest.php 一致：两个独立权限编码
 * （product_mapping.view / product_mapping.manage）各自验证生效，加上本任务
 * 最核心的一条断言——人工改价（`updateCostPrice`）真的经过
 * `SupplierProductDao` 的"改价必留痕"逻辑，写一条 `source=manual` 的历史行。
 *
 * @internal
 * @coversNothing
 */
class ProductMappingControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private const VIEW_PERMISSION_CODE = 'product_mapping.view';

    private const MANAGE_PERMISSION_CODE = 'product_mapping.manage';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $productIds = [];

    private array $supplierIds = [];

    private array $mappingIds = [];

    protected function tearDown(): void
    {
        foreach ($this->mappingIds as $id) {
            SupplierProductPriceHistory::where('supplier_product_id', $id)->delete();
            SupplierProduct::destroy($id);
        }
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
            Product::destroy($id);
        }
        foreach ($this->supplierIds as $id) {
            Supplier::destroy($id);
        }
        $this->mappingIds = [];
        $this->adminUserIds = [];
        $this->roleIds = [];
        $this->permissionIds = [];
        $this->productIds = [];
        $this->supplierIds = [];

        parent::tearDown();
    }

    public function testCreateWithValidPayloadPersistsMapping()
    {
        $product = $this->createProduct(['business_line' => 'recharge']);
        $supplier = $this->createSupplier(['business_line' => 'recharge']);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->jsonRequest('POST', '/admin/product-mappings', $token, [
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_product_code' => 'RC-100',
            'cost_price' => '9.50',
            'priority' => 1,
            'param_mapping' => ['mobile' => 'recharge_account'],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('id', $body);
        $this->mappingIds[] = $body['id'];

        $row = SupplierProduct::find($body['id']);
        $this->assertNotNull($row);
        $this->assertSame($product->id, $row->product_id);
        $this->assertSame($supplier->id, $row->supplier_id);
        $this->assertSame('RC-100', $row->supplier_product_code);
        $this->assertSame('9.50', $row->cost_price);
        $this->assertSame(1, $row->priority);
        $this->assertSame('active', $row->status);
        $this->assertSame(['mobile' => 'recharge_account'], $row->param_mapping);
    }

    public function testCreateWithMismatchedBusinessLineReturns422AndCreatesNoRow()
    {
        $product = $this->createProduct(['business_line' => 'recharge']);
        $supplier = $this->createSupplier(['business_line' => 'card']);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->jsonRequest('POST', '/admin/product-mappings', $token, [
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_product_code' => 'RC-100',
            'cost_price' => '9.50',
            'priority' => 1,
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, SupplierProduct::where('product_id', $product->id)->count());
    }

    public function testCreateDuplicateMappingReturnsCleanErrorNotRawDbError()
    {
        $product = $this->createProduct(['business_line' => 'recharge']);
        $supplier = $this->createSupplier(['business_line' => 'recharge']);
        $existing = $this->createMapping($product->id, $supplier->id);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->jsonRequest('POST', '/admin/product-mappings', $token, [
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_product_code' => 'RC-200',
            'cost_price' => '5.00',
            'priority' => 2,
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());
        $this->assertSame(1, SupplierProduct::where('product_id', $product->id)
            ->where('supplier_id', $supplier->id)->count());
        unset($existing);
    }

    public function testCreateWithNonexistentProductOrSupplierReturns422()
    {
        $supplier = $this->createSupplier(['business_line' => 'recharge']);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->jsonRequest('POST', '/admin/product-mappings', $token, [
            'product_id' => 999999999,
            'supplier_id' => $supplier->id,
            'supplier_product_code' => 'RC-100',
            'cost_price' => '9.50',
            'priority' => 1,
        ]);
        $this->assertSame(404, $response->getStatusCode());

        $product = $this->createProduct(['business_line' => 'recharge']);
        $response = $this->jsonRequest('POST', '/admin/product-mappings', $token, [
            'product_id' => $product->id,
            'supplier_id' => 999999999,
            'supplier_product_code' => 'RC-100',
            'cost_price' => '9.50',
            'priority' => 1,
        ]);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testCreateWithInvalidCostPriceOrPriorityReturns422()
    {
        $product = $this->createProduct(['business_line' => 'recharge']);
        $supplier = $this->createSupplier(['business_line' => 'recharge']);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->jsonRequest('POST', '/admin/product-mappings', $token, [
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_product_code' => 'RC-100',
            'cost_price' => '-1.00',
            'priority' => 1,
        ]);
        $this->assertSame(422, $response->getStatusCode());

        $response = $this->jsonRequest('POST', '/admin/product-mappings', $token, [
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_product_code' => 'RC-100',
            'cost_price' => '9.50',
            'priority' => -1,
        ]);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testListOrdersByPriorityAndIncludesSupplierNameAndCode()
    {
        $product = $this->createProduct(['business_line' => 'recharge']);
        $supplierA = $this->createSupplier(['business_line' => 'recharge', 'name' => '供应商甲']);
        $supplierB = $this->createSupplier(['business_line' => 'recharge', 'name' => '供应商乙']);
        $mappingLow = $this->createMapping($product->id, $supplierA->id, ['priority' => 5]);
        $mappingHigh = $this->createMapping($product->id, $supplierB->id, ['priority' => 1]);
        $token = $this->loginAs($this->createAdminWithPermissions([self::VIEW_PERMISSION_CODE]));

        $response = $this->client->request('GET', '/admin/product-mappings?product_id=' . $product->id, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame([$mappingHigh->id, $mappingLow->id], array_column($body, 'id'));
        $this->assertSame($supplierB->name, $body[0]['supplier_name']);
        $this->assertSame($supplierB->code, $body[0]['supplier_code']);
        $this->assertSame($supplierA->name, $body[1]['supplier_name']);
    }

    public function testUpdateCostPriceLogsManualHistory()
    {
        $product = $this->createProduct(['business_line' => 'recharge']);
        $supplier = $this->createSupplier(['business_line' => 'recharge']);
        $mapping = $this->createMapping($product->id, $supplier->id, ['cost_price' => '10.00']);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/product-mappings/' . $mapping->id . '/cost-price', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['cost_price' => '18.88'],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $mapping->refresh();
        $this->assertSame('18.88', $mapping->cost_price);

        $history = SupplierProductPriceHistory::where('supplier_product_id', $mapping->id)->get();
        $this->assertCount(1, $history);
        $this->assertSame('10.00', $history->first()->old_price);
        $this->assertSame('18.88', $history->first()->new_price);
        $this->assertSame('manual', $history->first()->source);
    }

    public function testUpdateCostPriceToSamePriceSkipsHistoryLogging()
    {
        $product = $this->createProduct(['business_line' => 'recharge']);
        $supplier = $this->createSupplier(['business_line' => 'recharge']);
        $mapping = $this->createMapping($product->id, $supplier->id, ['cost_price' => '10.00']);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/product-mappings/' . $mapping->id . '/cost-price', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['cost_price' => '10.00'],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $history = SupplierProductPriceHistory::where('supplier_product_id', $mapping->id)->get();
        $this->assertCount(0, $history);
    }

    public function testUpdatePriorityChangesValue()
    {
        $product = $this->createProduct(['business_line' => 'recharge']);
        $supplier = $this->createSupplier(['business_line' => 'recharge']);
        $mapping = $this->createMapping($product->id, $supplier->id, ['priority' => 1]);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/product-mappings/' . $mapping->id . '/priority', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['priority' => 9],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $mapping->refresh();
        $this->assertSame(9, $mapping->priority);
    }

    public function testSetStatusToggle()
    {
        $product = $this->createProduct(['business_line' => 'recharge']);
        $supplier = $this->createSupplier(['business_line' => 'recharge']);
        $mapping = $this->createMapping($product->id, $supplier->id, ['status' => 'active']);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/product-mappings/' . $mapping->id . '/status', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['status' => 'paused'],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $mapping->refresh();
        $this->assertSame('paused', $mapping->status);
    }

    public function testSetStatusWithInvalidValueReturns422()
    {
        $product = $this->createProduct(['business_line' => 'recharge']);
        $supplier = $this->createSupplier(['business_line' => 'recharge']);
        $mapping = $this->createMapping($product->id, $supplier->id, ['status' => 'active']);
        $token = $this->loginAs($this->createAdminWithPermissions([self::MANAGE_PERMISSION_CODE]));

        $response = $this->client->request('POST', '/admin/product-mappings/' . $mapping->id . '/status', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['status' => 'not_a_real_status'],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $mapping->refresh();
        $this->assertSame('active', $mapping->status);
    }

    /**
     * 关键用例：证明 'product_mapping.view' 不能替代 'product_mapping.manage'。
     */
    public function testViewOnlyAdminGets403OnManageActions()
    {
        $product = $this->createProduct(['business_line' => 'recharge']);
        $supplier = $this->createSupplier(['business_line' => 'recharge']);
        $mapping = $this->createMapping($product->id, $supplier->id);
        $token = $this->loginAs($this->createAdminWithPermissions([self::VIEW_PERMISSION_CODE]));

        $createResponse = $this->jsonRequest('POST', '/admin/product-mappings', $token, [
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_product_code' => 'RC-999',
            'cost_price' => '1.00',
            'priority' => 1,
        ]);
        $this->assertSame(403, $createResponse->getStatusCode());

        $priceResponse = $this->client->request('POST', '/admin/product-mappings/' . $mapping->id . '/cost-price', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['cost_price' => '2.00'],
        ]);
        $this->assertSame(403, $priceResponse->getStatusCode());

        $statusResponse = $this->client->request('POST', '/admin/product-mappings/' . $mapping->id . '/status', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'form_params' => ['status' => 'paused'],
        ]);
        $this->assertSame(403, $statusResponse->getStatusCode());
    }

    public function testNoTokenAtAllReturns401OnEveryRoute()
    {
        $product = $this->createProduct(['business_line' => 'recharge']);
        $supplier = $this->createSupplier(['business_line' => 'recharge']);
        $mapping = $this->createMapping($product->id, $supplier->id);

        $this->assertSame(
            401,
            $this->client->request('GET', '/admin/product-mappings?product_id=' . $product->id)->getStatusCode()
        );
        $this->assertSame(401, $this->client->request('POST', '/admin/product-mappings')->getStatusCode());
        $this->assertSame(
            401,
            $this->client->request('POST', '/admin/product-mappings/' . $mapping->id . '/cost-price')
                ->getStatusCode()
        );
        $this->assertSame(
            401,
            $this->client->request('POST', '/admin/product-mappings/' . $mapping->id . '/priority')
                ->getStatusCode()
        );
        $this->assertSame(
            401,
            $this->client->request('POST', '/admin/product-mappings/' . $mapping->id . '/status')->getStatusCode()
        );
        $this->assertSame(
            401,
            $this->client->request('PUT', '/admin/product-mappings/' . $mapping->id)->getStatusCode()
        );
    }

    private function jsonRequest(string $method, string $path, string $token, array $data)
    {
        return $this->client->request($method, $path, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'json' => $data,
        ]);
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
                ['module' => 'product_mapping', 'name' => $code, 'type' => 'action']
            );
            if ($permission->wasRecentlyCreated) {
                $this->permissionIds[] = $permission->id;
            }

            AdminRolePermission::create([
                'role_id' => $role->id,
                'permission_id' => $permission->id,
            ]);
        }

        return $this->createAdmin($role->id);
    }

    private function createAdmin(int $roleId): AdminUser
    {
        $admin = AdminUser::create([
            'username' => 'admin_' . uniqid('', true),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'real_name' => 'Test Admin',
            'role_id' => $roleId,
            'status' => 'active',
        ]);

        $this->adminUserIds[] = $admin->id;

        return $admin;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createProduct(array $overrides = []): Product
    {
        $product = Product::create(array_merge([
            'business_line' => 'recharge',
            'name' => '测试商品_' . uniqid('', true),
            'face_value' => '100.00',
            'sale_price' => '99.00',
            'rebate_amount' => '1.00',
            'status' => 'on_shelf',
        ], $overrides));
        $this->productIds[] = $product->id;

        return $product;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createSupplier(array $overrides = []): Supplier
    {
        $supplier = Supplier::create(array_merge([
            'name' => '测试供应商_' . uniqid('', true),
            'code' => 'supplier_' . uniqid('', true),
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => 'encrypted-placeholder',
            'status' => 'active',
        ], $overrides));
        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createMapping(int $productId, int $supplierId, array $overrides = []): SupplierProduct
    {
        $mapping = SupplierProduct::create(array_merge([
            'product_id' => $productId,
            'supplier_id' => $supplierId,
            'supplier_product_code' => 'CODE-' . uniqid('', true),
            'cost_price' => '10.00',
            'priority' => 1,
            'status' => 'active',
        ], $overrides));
        $this->mappingIds[] = $mapping->id;

        return $mapping;
    }
}
