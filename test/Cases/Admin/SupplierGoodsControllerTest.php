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
use App\Model\SupplierGoods;
use App\Model\SupplierProduct;
use HyperfTest\HttpTestCase;

/**
 * 系统管理后台「供应商商品库」列表：按供应商或按业务线查、关键字筛选、
 * 每行带上已经用到它的本地商品。
 *
 * @internal
 * @coversNothing
 */
class SupplierGoodsControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $supplierIds = [];

    private array $productIds = [];

    protected function tearDown(): void
    {
        foreach ($this->productIds as $id) {
            SupplierProduct::where('product_id', $id)->delete();
            Product::destroy($id);
        }
        SupplierGoods::whereIn('supplier_id', $this->supplierIds)->delete();
        foreach ($this->supplierIds as $id) {
            Supplier::destroy($id);
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
        $this->productIds = [];
        $this->supplierIds = [];
        $this->adminUserIds = [];
        $this->roleIds = [];
        $this->permissionIds = [];

        parent::tearDown();
    }

    public function testListBySupplierIncludesUsedByProducts()
    {
        $supplier = $this->createSupplier('recharge');
        $used = $this->createGoods($supplier->id, ['code' => 'A-1', 'name' => '移动 100 元快充']);
        $this->createGoods($supplier->id, ['code' => 'A-2', 'name' => '联通 100 元快充']);
        $product = Product::create([
            'business_line' => 'recharge', 'name' => '本地移动 100', 'operator' => 'mobile',
            'face_value' => '100.00', 'sale_price' => '99.20', 'rebate_amount' => '0.50', 'status' => 'off_shelf',
        ]);
        $this->productIds[] = $product->id;
        SupplierProduct::create([
            'product_id' => $product->id, 'supplier_id' => $supplier->id, 'supplier_product_code' => 'A-1',
            'cost_price' => '98.50', 'priority' => 0, 'status' => 'active',
        ]);

        $body = $this->get(['supplier_id' => $supplier->id]);

        $this->assertSame(2, $body['total']);
        $this->assertSame(['A-1', 'A-2'], array_column($body['data'], 'code'));
        $this->assertSame($used->id, $body['data'][0]['id']);
        $this->assertSame($supplier->name, $body['data'][0]['supplier_name']);
        $this->assertSame([['id' => $product->id, 'name' => '本地移动 100']], $body['data'][0]['used_by']);
        $this->assertSame([], $body['data'][1]['used_by']);
    }

    public function testListByBusinessLineOnlyShowsThatLineAndFiltersByKeyword()
    {
        $recharge = $this->createSupplier('recharge');
        $card = $this->createSupplier('card');
        $mark = uniqid('', true);
        $this->createGoods($recharge->id, ['code' => 'R-' . $mark, 'name' => '移动 ' . $mark]);
        $this->createGoods($card->id, ['code' => 'C-' . $mark, 'name' => '爱奇艺 ' . $mark]);

        $body = $this->get(['business_line' => 'recharge', 'keyword' => $mark]);
        $this->assertSame(['R-' . $mark], array_column($body['data'], 'code'));

        // 编码按精确匹配
        $body = $this->get(['business_line' => 'card', 'keyword' => 'C-' . $mark]);
        $this->assertSame(['C-' . $mark], array_column($body['data'], 'code'));
    }

    public function testListMatchingNothingReturnsEmpty()
    {
        $supplier = $this->createSupplier('recharge');

        $body = $this->get(['supplier_id' => $supplier->id]);
        $this->assertSame(['data' => [], 'total' => 0, 'page' => 1, 'per_page' => 20], $body);

        $body = $this->get(['supplier_id' => $supplier->id, 'business_line' => 'card']);
        $this->assertSame([], $body['data']);
    }

    public function testListRequiresSupplierOrBusinessLineAndPermission()
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['product_mapping.view']));
        $response = $this->client->request('GET', '/admin/supplier-goods', ['headers' => ['Authorization' => 'Bearer ' . $token]]);
        $this->assertSame(422, $response->getStatusCode());

        $token = $this->loginAs($this->createAdminWithPermissions([]));
        $response = $this->client->request('GET', '/admin/supplier-goods?business_line=recharge', ['headers' => ['Authorization' => 'Bearer ' . $token]]);
        $this->assertSame(403, $response->getStatusCode());

        $this->assertSame(401, $this->client->request('GET', '/admin/supplier-goods?business_line=recharge')->getStatusCode());
    }

    private function get(array $query): array
    {
        $token = $this->loginAs($this->createAdminWithPermissions(['product_mapping.view']));
        $response = $this->client->request('GET', '/admin/supplier-goods?' . http_build_query($query), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true);
    }

    private function createSupplier(string $businessLine): Supplier
    {
        $supplier = Supplier::create([
            'name' => '测试供应商_' . uniqid('', true),
            'code' => 'supplier_' . uniqid('', true),
            'business_line' => $businessLine,
            'driver' => 'kasushou',
            'config' => 'encrypted-placeholder',
            'status' => 'active',
        ]);
        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }

    private function createGoods(int $supplierId, array $overrides = []): SupplierGoods
    {
        return SupplierGoods::create(array_merge([
            'supplier_id' => $supplierId,
            'code' => 'G-' . uniqid('', true),
            'name' => '供应商商品',
            'face_value' => '100.00',
            'cost_price' => '98.50',
            'status' => 'active',
        ], $overrides));
    }

    private function loginAs(AdminUser $admin): string
    {
        $login = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => ['username' => $admin->username, 'password' => self::PASSWORD],
        ]);

        return json_decode((string) $login->getBody(), true)['token'];
    }

    private function createAdminWithPermissions(array $codes): AdminUser
    {
        $role = AdminRole::create(['name' => 'role_' . uniqid('', true), 'is_system' => false]);
        $this->roleIds[] = $role->id;

        foreach ($codes as $code) {
            $permission = AdminPermission::firstOrCreate(
                ['code' => $code],
                ['module' => 'product_mapping', 'name' => $code, 'type' => 'action']
            );
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

        return $admin;
    }
}
