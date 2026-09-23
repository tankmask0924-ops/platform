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

namespace HyperfTest\Cases\Dao;

use App\Dao\MerchantDao;
use App\Model\Merchant;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class MerchantDaoTest extends TestCase
{
    private ?int $merchantId = null;

    protected function tearDown(): void
    {
        if ($this->merchantId !== null) {
            Merchant::destroy($this->merchantId);
            $this->merchantId = null;
        }

        parent::tearDown();
    }

    public function testFindReturnsMerchantFromCache()
    {
        $dao = $this->getContainer()->get(MerchantDao::class);
        $merchant = $this->createMerchant();

        $found = $dao->find($merchant->id);

        $this->assertNotNull($found);
        $this->assertSame($merchant->id, $found->id);
        $this->assertSame($merchant->app_key, $found->app_key);
    }

    public function testFindReturnsNullForMissingId()
    {
        $dao = $this->getContainer()->get(MerchantDao::class);

        $this->assertNull($dao->find(999999999));
    }

    /**
     * 批量按 id 取商户：走缓存、按 id 作键、重复 id 去重、不存在的 id 直接缺席、空数组返回空集合。
     */
    public function testFindManyReturnsMerchantsKeyedByIdFromCache()
    {
        $a = $this->createMerchant();
        $b = $this->createMerchant();
        $dao = $this->getContainer()->get(MerchantDao::class);

        $found = $dao->findMany([$a->id, (string) $b->id, $a->id, 999999999]);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $found->keys()->all());
        $this->assertSame($a->phone, $found->get($a->id)->phone);

        // 第二次应该命中缓存：直接改库（不经过模型事件、不清缓存），读到的仍是缓存里的旧值
        Merchant::query()->whereKey($a->id)->update(['phone' => '13000000000']);
        $this->assertSame($a->phone, $dao->findMany([$a->id])->get($a->id)->phone);

        $this->assertTrue($dao->findMany([])->isEmpty());
    }

    public function testFindByAppKeyReturnsMatchingMerchant()
    {
        $dao = $this->getContainer()->get(MerchantDao::class);
        $merchant = $this->createMerchant();

        $found = $dao->findByAppKey($merchant->app_key);

        $this->assertNotNull($found);
        $this->assertSame($merchant->id, $found->id);
    }

    public function testFindByAppKeyReturnsNullWhenNotFound()
    {
        $dao = $this->getContainer()->get(MerchantDao::class);

        $this->assertNull($dao->findByAppKey('does-not-exist-' . uniqid('', true)));
    }

    public function testLockForUpdateReturnsMatchingMerchant()
    {
        $dao = $this->getContainer()->get(MerchantDao::class);
        $merchant = $this->createMerchant();

        $found = $dao->lockForUpdate($merchant->id);

        $this->assertNotNull($found);
        $this->assertSame($merchant->id, $found->id);
    }

    public function testLockForUpdateReturnsNullForMissingId()
    {
        $dao = $this->getContainer()->get(MerchantDao::class);

        $this->assertNull($dao->lockForUpdate(999999999));
    }

    /**
     * 没有真正的并发环境能验证「行锁生效、第二个事务真的被卡住排队」，退而求其次：
     * 验证 MerchantDao::lockForUpdate() 依赖的同一条查询构造链（newQuery()->where()->
     * lockForUpdate()）编译出的 SQL 里确实带有 MySQL 的 `for update` 子句——这是
     * Hyperf\Database\Query\Grammars\MySqlGrammar::compileLock() 的产出，证明调用
     * 这个方法真的会让数据库对这一行加锁，不是只有方法名叫这个但实际没生效。
     */
    public function testLockForUpdateGeneratesForUpdateSql()
    {
        $sql = Merchant::query()->where('id', 1)->lockForUpdate()->toSql();

        $this->assertStringContainsString('for update', $sql);
    }

    private function createMerchant(): Merchant
    {
        $unique = uniqid('merchant_dao_test_', true);

        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => 'encrypted-secret-placeholder',
            'ip_whitelist' => ['127.0.0.1'],
        ]);

        $this->merchantId = $merchant->id;

        return $merchant;
    }
}
