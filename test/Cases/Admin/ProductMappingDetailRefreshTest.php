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
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\SupplierDriverFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\Testing\Client;
use HyperfTest\HttpTestCase;
use HyperfTest\RebuildsContainer;
use Mockery;
use RuntimeException;

use function Hyperf\Support\make;

/**
 * 建映射前查供应商商品详情（下单模板、购买数量只有详情接口有）：没查过或超过一小时就查一次，
 * 查到的结果写回供应商商品库再校验；查不到报 502，不建映射。驱动用 Mockery 双重。
 *
 * @internal
 * @coversNothing
 */
class ProductMappingDetailRefreshTest extends HttpTestCase
{
    use RebuildsContainer;

    private const PASSWORD = 'correct-password';

    private ?KasushouDriver $driver = null;

    private array $cleanup = ['products' => [], 'suppliers' => [], 'admins' => [], 'roles' => [], 'permissions' => []];

    protected function setUp(): void
    {
        $this->rebuildContainer();
        $this->client = make(Client::class);

        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('build')->andReturnUsing(fn () => $this->driver ?? throw new RuntimeException('no driver double'));
        ApplicationContext::getContainer()->set(SupplierDriverFactory::class, $factory);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup['products'] as $id) {
            SupplierProduct::where('product_id', $id)->delete();
            Product::destroy($id);
        }
        SupplierGoods::whereIn('supplier_id', $this->cleanup['suppliers'])->delete();
        Supplier::destroy($this->cleanup['suppliers']);
        AdminUser::destroy($this->cleanup['admins']);
        foreach ($this->cleanup['roles'] as $id) {
            AdminRolePermission::where('role_id', $id)->delete();
            AdminRole::destroy($id);
        }
        AdminPermission::destroy($this->cleanup['permissions']);
        Mockery::close();

        parent::tearDown();
    }

    public function testNeverFetchedDetailIsQueriedSavedAndUsedForParamMapping()
    {
        [$product, $goods] = $this->productAndGoods(['detail_synced_at' => null, 'attach_template' => null]);
        $this->driver = Mockery::mock(KasushouDriver::class);
        $this->driver->shouldReceive('queryProductDetail')->once()->with($goods->code)->andReturn([
            'supplier_product_code' => $goods->code, 'cost_price' => '98.40', 'status' => 'active', 'stock' => 5,
            'name' => '移动 100 元快充', 'face_value' => '100.00', 'goods_type' => 'virtual',
            'sale_restrictions' => ['can_buy' => '京东', 'can_no_buy' => '抖音', 'can_price' => null, 'need_balance' => null],
            'attach_template' => [['key' => 'phone', 'name' => '手机号', 'type' => 'text']],
            'min_quantity' => 1, 'max_quantity' => 1, 'can_back' => false,
        ]);

        $response = $this->createMapping($product, $goods);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $mapping = SupplierProduct::find(json_decode((string) $response->getBody(), true)['id']);
        $this->assertSame(['recharge_account' => 'phone'], $mapping->param_mapping);
        $this->assertSame('98.40', $mapping->cost_price, '用刚查到的成本价');
        $this->assertSame('抖音', $mapping->sale_restrictions['can_no_buy']);

        $goods->refresh();
        $this->assertSame([['key' => 'phone', 'name' => '手机号', 'type' => 'text']], $goods->attach_template);
        $this->assertNotNull($goods->detail_synced_at);
    }

    public function testDetailQueryFailureBlocksMapping()
    {
        [$product, $goods] = $this->productAndGoods(['detail_synced_at' => date('Y-m-d H:i:s', time() - 7200)]);
        $this->driver = Mockery::mock(KasushouDriver::class);
        $this->driver->shouldReceive('queryProductDetail')->once()->andThrow(new RuntimeException('kasushou: network error or timeout'));

        $response = $this->createMapping($product, $goods);
        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringContainsString('详情失败', (string) $response->getBody());
        $this->assertSame(0, SupplierProduct::where('product_id', $product->id)->count());
    }

    public function testFreshDetailIsNotQueriedAgain()
    {
        [$product, $goods] = $this->productAndGoods(['detail_synced_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->driver = Mockery::mock(KasushouDriver::class);
        $this->driver->shouldNotReceive('queryProductDetail');

        $this->assertSame(200, $this->createMapping($product, $goods)->getStatusCode());
    }

    /**
     * @return array{0: Product, 1: SupplierGoods}
     */
    private function productAndGoods(array $goodsOverrides): array
    {
        $product = Product::create([
            'business_line' => 'recharge', 'name' => 'refresh_test_' . uniqid('', true), 'operator' => 'mobile',
            'face_value' => '100.00', 'sale_price' => '99.20', 'rebate_amount' => '0.00', 'status' => 'off_shelf',
        ]);
        $this->cleanup['products'][] = $product->id;
        $supplier = Supplier::create([
            'name' => 'refresh_test_' . uniqid('', true), 'code' => 'refresh_' . uniqid('', true), 'business_line' => 'recharge',
            'driver' => 'kasushou', 'config' => 'encrypted-placeholder', 'status' => 'active',
        ]);
        $this->cleanup['suppliers'][] = $supplier->id;
        $goods = SupplierGoods::create(array_merge([
            'supplier_id' => $supplier->id, 'code' => 'R-' . uniqid(), 'name' => '移动 100 元快充', 'cost_price' => '98.50', 'status' => 'active',
            'attach_template' => [['key' => 'recharge_account', 'name' => '充值账号', 'type' => 'text']], 'min_quantity' => 1,
        ], $goodsOverrides));

        return [$product, $goods];
    }

    private function createMapping(Product $product, SupplierGoods $goods)
    {
        return $this->client->request('POST', '/admin/product-mappings', [
            'headers' => ['Authorization' => 'Bearer ' . $this->token(), 'Content-Type' => 'application/json'],
            'json' => ['product_id' => $product->id, 'supplier_goods_id' => $goods->id, 'priority' => 0],
        ]);
    }

    private function token(): string
    {
        $role = AdminRole::create(['name' => 'role_' . uniqid('', true), 'is_system' => false]);
        $this->cleanup['roles'][] = $role->id;
        $permission = AdminPermission::firstOrCreate(['code' => 'product_mapping.manage'], ['module' => 'product_mapping', 'name' => 'product_mapping.manage', 'type' => 'action']);
        if ($permission->wasRecentlyCreated) {
            $this->cleanup['permissions'][] = $permission->id;
        }
        AdminRolePermission::create(['role_id' => $role->id, 'permission_id' => $permission->id]);
        $admin = AdminUser::create([
            'username' => 'admin_' . uniqid('', true), 'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'real_name' => 'Test Admin', 'role_id' => $role->id, 'status' => 'active',
        ]);
        $this->cleanup['admins'][] = $admin->id;

        $login = $this->client->request('POST', '/admin/auth/login', ['form_params' => ['username' => $admin->username, 'password' => self::PASSWORD]]);

        return json_decode((string) $login->getBody(), true)['token'];
    }
}
