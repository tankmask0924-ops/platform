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

namespace HyperfTest\Cases\Service\Merchant;

use App\Dao\MerchantRateLimitDao;
use App\Dao\SystemSettingDao;
use App\Model\MerchantRateLimit;
use App\Service\Merchant\RateLimitSettingService;
use Hyperf\Testing\TestCase;
use Mockery;
use ReflectionProperty;

use function Hyperf\Support\make;

/**
 * 单独配置走真实 merchant_rate_limits 表（表上没有外键，用随机大 id 不需要真的建商户）；
 * 全局默认值换成 mock 的 SystemSettingDao，不去改共享库里其它用例也在读的 system_settings。
 *
 * @internal
 * @coversNothing
 */
class RateLimitSettingServiceTest extends TestCase
{
    private array $merchantIds = [];

    protected function tearDown(): void
    {
        foreach ($this->merchantIds as $id) {
            MerchantRateLimit::where('merchant_id', $id)->delete();
        }
        $this->merchantIds = [];

        parent::tearDown();
    }

    public function testCustomLimitWinsOverDefault()
    {
        $merchantId = $this->randomMerchantId();
        make(MerchantRateLimitDao::class)->upsertLimit($merchantId, 7);

        $this->assertSame(
            ['limit_per_second' => 7, 'is_custom' => true],
            $this->serviceWithDefault(80)->effectiveLimit($merchantId)
        );
    }

    public function testWithoutCustomLimitUsesConfiguredDefault()
    {
        $this->assertSame(
            ['limit_per_second' => 80, 'is_custom' => false],
            $this->serviceWithDefault(80)->effectiveLimit($this->randomMerchantId())
        );
    }

    public function testNumericStringDefaultIsAccepted()
    {
        $this->assertSame(
            ['limit_per_second' => 120, 'is_custom' => false],
            $this->serviceWithDefault('120')->effectiveLimit($this->randomMerchantId())
        );
    }

    public function testInvalidDefaultFallsBackToCodeDefault()
    {
        foreach ([0, -5, 'abc', null, ['x']] as $bad) {
            $this->assertSame(
                ['limit_per_second' => RateLimitSettingService::DEFAULT_LIMIT_PER_SECOND, 'is_custom' => false],
                $this->serviceWithDefault($bad)->effectiveLimit($this->randomMerchantId()),
                var_export($bad, true)
            );
        }
    }

    private function serviceWithDefault(mixed $default): RateLimitSettingService
    {
        $settings = Mockery::mock(SystemSettingDao::class);
        $settings->shouldReceive('getValue')
            ->with(RateLimitSettingService::DEFAULT_SETTING_KEY, RateLimitSettingService::DEFAULT_LIMIT_PER_SECOND)
            ->andReturn($default);

        $service = make(RateLimitSettingService::class);
        (new ReflectionProperty($service, 'systemSettingDao'))->setValue($service, $settings);

        return $service;
    }

    private function randomMerchantId(): int
    {
        $id = random_int(900_000_000, 999_999_999);
        $this->merchantIds[] = $id;

        return $id;
    }
}
