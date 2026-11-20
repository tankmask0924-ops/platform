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
use App\Model\CardBrand;
use App\Model\CardCategory;
use App\Model\Product;
use HyperfTest\HttpTestCase;

/**
 * 系统管理后台「卡券分类」：增删改查、重名、下面有品牌的不能删、权限。
 *
 * @internal
 * @coversNothing
 */
class CardCategoryControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $brandIds = [];

    private array $categoryIds = [];

    private array $productIds = [];

    private array $adminIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    protected function tearDown(): void
    {
        Product::destroy($this->productIds);
        CardBrand::destroy($this->brandIds);
        CardCategory::destroy($this->categoryIds);
        AdminUser::destroy($this->adminIds);
        foreach ($this->roleIds as $id) {
            AdminRolePermission::where('role_id', $id)->delete();
            AdminRole::destroy($id);
        }
        AdminPermission::destroy($this->permissionIds);

        parent::tearDown();
    }

    public function testCrudAndCategoryWithBrandsCannotBeDeleted()
    {
        $token = $this->token(['product.view', 'product.manage']);
        $name = '影音会员_' . uniqid();

        $created = $this->json('POST', '/admin/card-categories', $token, ['name' => ' ' . $name . ' ', 'sort' => 3]);
        $this->categoryIds[] = $created['id'];
        $this->assertSame($name, $created['name']);
        $this->assertSame('active', $created['status']);
        $this->assertSame(0, $created['brand_count']);
        $this->assertSame(422, $this->request('POST', '/admin/card-categories', $token, ['name' => $name])->getStatusCode(), '重名');

        $updated = $this->json('PUT', '/admin/card-categories/' . $created['id'], $token, ['status' => 'disabled']);
        $this->assertSame('disabled', $updated['status']);
        $this->assertContains($created['id'], array_column($this->json('GET', '/admin/card-categories?status=disabled', $token), 'id'));

        $brand = CardBrand::create(['category_id' => $created['id'], 'name' => '爱奇艺_' . uniqid(), 'sort' => 0, 'status' => 'active']);
        $this->brandIds[] = $brand->id;
        $row = array_values(array_filter($this->json('GET', '/admin/card-categories', $token), fn ($c) => $c['id'] === $created['id']))[0];
        $this->assertSame(1, $row['brand_count']);

        $response = $this->request('DELETE', '/admin/card-categories/' . $created['id'], $token);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('停用', (string) $response->getBody());

        CardBrand::destroy($brand->id);
        $this->assertSame(200, $this->request('DELETE', '/admin/card-categories/' . $created['id'], $token)->getStatusCode());
        $this->assertNull(CardCategory::find($created['id']));
    }

    public function testListMatchingNothingAndPermissions()
    {
        $viewer = $this->token(['product.view']);
        $this->assertSame(200, $this->request('GET', '/admin/card-categories', $viewer)->getStatusCode());
        $this->assertSame(403, $this->request('POST', '/admin/card-categories', $viewer, ['name' => 'x' . uniqid()])->getStatusCode());
        $this->assertSame(401, $this->client->request('GET', '/admin/card-categories')->getStatusCode());
        $this->assertSame(422, $this->request('GET', '/admin/card-categories?status=bogus', $viewer)->getStatusCode());
    }

    private function createCategory(string $status = 'active'): CardCategory
    {
        $category = CardCategory::create(['name' => '分类_' . uniqid('', true), 'sort' => 0, 'status' => $status]);
        $this->categoryIds[] = $category->id;

        return $category;
    }

    private function json(string $method, string $path, string $token, array $data = []): array
    {
        $response = $this->request($method, $path, $token, $data);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function request(string $method, string $path, string $token, array $data = [])
    {
        return $this->client->request($method, $path, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'json' => $data,
        ]);
    }

    private function token(array $codes): string
    {
        $role = AdminRole::create(['name' => 'role_' . uniqid('', true), 'is_system' => false]);
        $this->roleIds[] = $role->id;
        foreach ($codes as $code) {
            $permission = AdminPermission::firstOrCreate(['code' => $code], ['module' => 'product', 'name' => $code, 'type' => 'action']);
            if ($permission->wasRecentlyCreated) {
                $this->permissionIds[] = $permission->id;
            }
            AdminRolePermission::create(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }
        $admin = AdminUser::create([
            'username' => 'admin_' . uniqid('', true), 'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'real_name' => 'Test Admin', 'role_id' => $role->id, 'status' => 'active',
        ]);
        $this->adminIds[] = $admin->id;
        $login = $this->client->request('POST', '/admin/auth/login', ['form_params' => ['username' => $admin->username, 'password' => self::PASSWORD]]);

        return json_decode((string) $login->getBody(), true)['token'];
    }
}
