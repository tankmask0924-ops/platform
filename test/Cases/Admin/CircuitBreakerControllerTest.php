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

use App\Crypto\Encryptor;
use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use App\Model\AdminUser;
use App\Model\Product;
use App\Model\Supplier;
use App\Model\SupplierCircuitBreaker;
use App\Model\SupplierProduct;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * 系统后台「供应商管理 - 熔断状态」（requirements.md 6.6）：查看、手动暂停、手动恢复。
 * 判定逻辑本身见 HyperfTest\Cases\Service\Supplier\CircuitBreakerServiceTest。
 *
 * @internal
 * @coversNothing
 */
class CircuitBreakerControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    private array $supplierIds = [];

    private array $productIds = [];

    private array $supplierProductIds = [];

    protected function tearDown(): void
    {
        SupplierCircuitBreaker::whereIn('supplier_id', $this->supplierIds)->delete();
        SupplierProduct::destroy($this->supplierProductIds);
        Product::destroy($this->productIds);
        Supplier::destroy($this->supplierIds);
        AdminUser::destroy($this->adminUserIds);
        AdminRolePermission::whereIn('role_id', $this->roleIds)->delete();
        AdminRole::destroy($this->roleIds);
        AdminPermission::destroy($this->permissionIds);

        parent::tearDown();
    }

    public function testShowsThresholdsAndEmptyStateForAHealthySupplier()
    {
        $supplier = $this->createSupplier();
        $token = $this->loginWith(['supplier.view']);

        $body = $this->getJson('/admin/suppliers/' . $supplier->id . '/circuit-breakers', $token);

        $this->assertSame([], $body['data']);
        $this->assertFalse($body['supplier_paused']);
        // 只断言四个阈值都返回了、类型对；不断言具体数值——它们来自共享的 system_settings，
        // 开发环境里运营改过就会让测试无谓地红
        $this->assertIsInt($body['thresholds']['window_minutes']);
        $this->assertIsInt($body['thresholds']['min_orders']);
        $this->assertIsInt($body['thresholds']['pause_minutes']);
        $this->assertIsString($body['thresholds']['fail_rate_percent']);
    }

    public function testManualPauseAndResumeForTheWholeSupplier()
    {
        $supplier = $this->createSupplier();
        $token = $this->loginWith(['supplier.view', 'supplier.manage']);

        $paused = $this->postJson('/admin/suppliers/' . $supplier->id . '/circuit-breakers/pause', $token, [
            'minutes' => 30,
            'remark' => '供应商通知在做系统升级',
        ]);

        $this->assertTrue($paused['supplier_paused']);
        $this->assertCount(1, $paused['data']);
        $row = $paused['data'][0];
        $this->assertNull($row['product_id'], 'product_id 为空表示整个供应商');
        $this->assertSame('paused', $row['status']);
        $this->assertTrue($row['manual']);
        $this->assertStringContainsString('系统升级', $row['triggered_reason']);
        $this->assertNotNull($row['paused_until']);

        $resumed = $this->postJson('/admin/suppliers/' . $supplier->id . '/circuit-breakers/resume', $token, []);

        $this->assertFalse($resumed['supplier_paused']);
        // 行保留下来，能看到"这家曾经熔断过"
        $this->assertCount(1, $resumed['data']);
        $this->assertSame('normal', $resumed['data'][0]['status']);
    }

    public function testManualPauseWithoutMinutesIsIndefinite()
    {
        $supplier = $this->createSupplier();
        $token = $this->loginWith(['supplier.view', 'supplier.manage']);

        $body = $this->postJson('/admin/suppliers/' . $supplier->id . '/circuit-breakers/pause', $token, [
            'remark' => '等供应商回复',
        ]);

        $this->assertTrue($body['supplier_paused']);
        $this->assertNull($body['data'][0]['paused_until'], '没给时长就是无限期，只能人工恢复');
    }

    public function testProductScopedPauseRequiresAnExistingMapping()
    {
        $supplier = $this->createSupplier();
        $mapped = $this->createProduct();
        $unmapped = $this->createProduct();
        $this->createMapping($supplier, $mapped);
        $token = $this->loginWith(['supplier.view', 'supplier.manage']);

        $ok = $this->postJson('/admin/suppliers/' . $supplier->id . '/circuit-breakers/pause', $token, [
            'product_id' => $mapped->id,
            'minutes' => 10,
            'remark' => '这个商品一直失败',
        ]);
        $this->assertSame($mapped->id, $ok['data'][0]['product_id']);
        $this->assertSame($mapped->name, $ok['data'][0]['product_name']);
        $this->assertFalse($ok['supplier_paused'], '只熔断了一个商品，整家不算熔断');

        // 没映射到这家供应商的商品不能单独熔断：建了也永远不会生效
        $response = $this->post('/admin/suppliers/' . $supplier->id . '/circuit-breakers/pause', $token, [
            'product_id' => $unmapped->id,
            'remark' => '不该成功',
        ]);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testRejectsBadInput()
    {
        $supplier = $this->createSupplier();
        $token = $this->loginWith(['supplier.view', 'supplier.manage']);

        // remark 必填：熔断会直接切掉流量，必须留下是谁为什么关的
        $this->assertSame(422, $this->post('/admin/suppliers/' . $supplier->id . '/circuit-breakers/pause', $token, [])->getStatusCode());
        $this->assertSame(422, $this->post('/admin/suppliers/' . $supplier->id . '/circuit-breakers/pause', $token, [
            'remark' => '时长超上限', 'minutes' => 99999,
        ])->getStatusCode());
        $this->assertSame(404, $this->post('/admin/suppliers/999999999/circuit-breakers/pause', $token, [
            'remark' => '供应商不存在',
        ])->getStatusCode());
        // 没有熔断记录时恢复给 404，而不是静默成功
        $this->assertSame(404, $this->post('/admin/suppliers/' . $supplier->id . '/circuit-breakers/resume', $token, [])->getStatusCode());
    }

    public function testViewPermissionCannotPauseOrResume()
    {
        $supplier = $this->createSupplier();
        $viewer = $this->loginWith(['supplier.view']);

        $this->assertSame(200, $this->client->request('GET', '/admin/suppliers/' . $supplier->id . '/circuit-breakers', [
            'headers' => ['Authorization' => 'Bearer ' . $viewer],
        ])->getStatusCode());
        $this->assertSame(403, $this->post('/admin/suppliers/' . $supplier->id . '/circuit-breakers/pause', $viewer, [
            'remark' => '只读权限不该能暂停',
        ])->getStatusCode());
        $this->assertSame(403, $this->post('/admin/suppliers/' . $supplier->id . '/circuit-breakers/resume', $viewer, [])->getStatusCode());
    }

    private function getJson(string $path, string $token): array
    {
        $response = $this->client->request('GET', $path, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function postJson(string $path, string $token, array $data): array
    {
        $response = $this->post($path, $token, $data);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function post(string $path, string $token, array $data)
    {
        return $this->client->request('POST', $path, [
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
            $permission = AdminPermission::firstOrCreate(['code' => $code], ['module' => 'supplier', 'name' => $code, 'type' => 'action']);
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

    private function createSupplier(): Supplier
    {
        $supplier = Supplier::create([
            'name' => '熔断后台测试供应商',
            'code' => 'cba_' . substr(md5(uniqid('', true)), 0, 20),
            'business_line' => 'recharge',
            'driver' => 'kasushou',
            'config' => make(Encryptor::class)->encrypt(json_encode(['base_url' => 'https://api.example.com'])),
            'status' => 'active',
        ]);
        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }

    private function createProduct(): Product
    {
        $product = Product::create([
            'business_line' => 'recharge',
            'name' => '熔断后台测试商品 ' . uniqid('', true),
            'operator' => 'mobile',
            'face_value' => '100.00',
            'sale_price' => '98.00',
            'rebate_amount' => '0.00',
            'status' => 'on_shelf',
        ]);
        $this->productIds[] = $product->id;

        return $product;
    }

    private function createMapping(Supplier $supplier, Product $product): SupplierProduct
    {
        $mapping = SupplierProduct::create([
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_product_code' => 'GOODS-' . uniqid('', true),
            'cost_price' => '95.00',
            'priority' => 1,
            'status' => 'active',
        ]);
        $this->supplierProductIds[] = $mapping->id;

        return $mapping;
    }
}
