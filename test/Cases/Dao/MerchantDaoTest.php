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
