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

namespace HyperfTest\Cases\OpenApi;

use App\Crypto\Encryptor;
use App\Express\ExpressChannelCodec;
use App\Model\Merchant;
use App\Model\MerchantBusinessSubscription;
use App\Model\PricingRule;
use App\Model\Supplier;
use App\OpenApi\ErrorCode;
use App\Signature\SignatureSigner;
use App\Supplier\SupplierDriverFactory;
use App\Supplier\Yunyang\YunyangDriver;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\Client;
use HyperfTest\HttpTestCase;
use Mockery;
use RuntimeException;

use function Hyperf\Support\make;

/**
 * 开放 API「快递查价」（requirements.md 7.2、8.1，docs/modules.md 第 6 节）端到端：
 * 走完整中间件栈（签名、限流），云洋驱动全部 mock，不发真实请求。
 *
 * `pricing_rules` 是全局配置表，这些用例要设置快递加价规则才能算售价：setUp 备份并清空、
 * tearDown 原样还原，理由同 test/Cases/Admin/PricingRuleControllerTest.php。
 *
 * @internal
 * @coversNothing
 */
class ExpressQuoteControllerTest extends HttpTestCase
{
    private const SECRET = 'express-quote-secret';

    private array $merchantIds = [];

    private array $supplierIds = [];

    /** @var list<array<string, mixed>> */
    private array $existingRules = [];

    protected function setUp(): void
    {
        $this->existingRules = PricingRule::query()->get()
            ->map(static fn (PricingRule $rule) => [
                'business_line' => $rule->business_line,
                'rule_type' => $rule->rule_type,
                'value' => $rule->value,
                'updated_by' => $rule->updated_by,
                'created_at' => $rule->created_at?->toDateTimeString(),
                'updated_at' => $rule->updated_at?->toDateTimeString(),
            ])
            ->all();
        PricingRule::query()->delete();
        // 运费成本 + 2 元（requirements.md 7.2 结算举例用的那条规则）
        PricingRule::create(['business_line' => 'express', 'rule_type' => 'fixed', 'value' => '2']);

        // 每个用例换一个新容器，替换进去的驱动工厂不影响别的测试
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->client = make(Client::class);
    }

    protected function tearDown(): void
    {
        MerchantBusinessSubscription::whereIn('merchant_id', $this->merchantIds ?: [0])->delete();
        Merchant::destroy($this->merchantIds);
        Supplier::destroy($this->supplierIds);
        $this->merchantIds = $this->supplierIds = [];

        PricingRule::query()->delete();
        foreach ($this->existingRules as $rule) {
            PricingRule::query()->insert($rule);
        }
        $this->existingRules = [];

        parent::tearDown();
    }

    /**
     * 运费加价、保价费和耗材费按成本转、渠道编号换成平台编号，三件事一起验。
     */
    public function testQuoteMarksUpFreightOnlyAndHidesSupplierChannelId()
    {
        $merchant = $this->createMerchant();
        $supplier = $this->createSupplier();
        $this->bindChannels($supplier, [
            $this->channel('CH-SF', '顺丰', freight: '15.00', insured: '2.00', haocai: '1.00'),
        ]);

        $body = $this->quote($merchant);

        $this->assertSame(0, $body['code'], (string) json_encode($body));
        $channel = $body['data']['channels'][0];
        $this->assertSame('17.00', $channel['freight'], '运费成本 15 + 加价 2');
        $this->assertSame('2.00', $channel['insured_fee'], '保价费按成本转给商户');
        $this->assertSame('1.00', $channel['material_fee'], '耗材费按成本转给商户');
        $this->assertSame('20.00', $channel['total_price']);
        $this->assertSame('顺丰', $channel['company_name']);

        $this->assertStringStartsWith('EX', $channel['channel_code']);
        $this->assertStringNotContainsString('CH-SF', json_encode($body), '供应商渠道 ID 不能外泄');
        $this->assertSame(
            make(ExpressChannelCodec::class)->encode((int) $supplier->id, 'CH-SF'),
            $channel['channel_code'],
            '编号是确定性的，下单时重新查价能对回同一个渠道'
        );
    }

    public function testChannelsAreSortedByTotalPrice()
    {
        $merchant = $this->createMerchant();
        $supplier = $this->createSupplier();
        $this->bindChannels($supplier, [
            $this->channel('CH-SF', '顺丰', freight: '15.00'),
            $this->channel('CH-ZTO', '中通', freight: '8.00'),
            $this->channel('CH-YTO', '圆通', freight: '9.50'),
        ]);

        $channels = $this->quote($merchant)['data']['channels'];

        $this->assertSame(['中通', '圆通', '顺丰'], array_column($channels, 'company_name'));
        $this->assertSame('10.00', $channels[0]['total_price']);
    }

    /**
     * 商户要保价时，不支持保价的渠道不该出现——否则他会选中一个下单时才发现不能保价的渠道。
     */
    public function testChannelsWithoutInsuranceAreHiddenWhenInsuredAmountIsGiven()
    {
        $merchant = $this->createMerchant();
        $supplier = $this->createSupplier();
        $this->bindChannels($supplier, [
            $this->channel('CH-SF', '顺丰', freight: '15.00', allowInsured: true),
            $this->channel('CH-ZTO', '中通', freight: '8.00', allowInsured: false),
        ]);

        $withInsurance = $this->quote($merchant, ['insured_amount' => '1000.00'])['data']['channels'];
        $this->assertSame(['顺丰'], array_column($withInsurance, 'company_name'));

        $withoutInsurance = $this->quote($merchant)['data']['channels'];
        $this->assertCount(2, $withoutInsurance, '不保价时两个渠道都给');
    }

    /**
     * 供应商真的返回 0 个渠道 = "这个地址和重量没有可用渠道"，是一个正常答案，返回空列表。
     */
    public function testNoChannelForThisAddressIsAnEmptyListNotAnError()
    {
        $merchant = $this->createMerchant();
        $supplier = $this->createSupplier();
        $this->bindChannels($supplier, []);

        $body = $this->quote($merchant);

        $this->assertSame(0, $body['code']);
        $this->assertSame([], $body['data']['channels']);
    }

    /**
     * 一家供应商都没查成（没有启用中的快递供应商 / 驱动异常）是平台侧的问题，
     * 必须跟"没有可用渠道"分开，否则商户会去改地址。
     */
    public function testNothingQueriedIsReportedAsChannelUnavailable()
    {
        $merchant = $this->createMerchant();

        // 一个快递供应商都没有
        $this->assertSame(ErrorCode::ExpressChannelUnavailable->value, $this->quote($merchant)['code']);

        // 有供应商但驱动建不起来
        $supplier = $this->createSupplier();
        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('buildYunyang')->andThrow(new RuntimeException('config is broken'));
        ApplicationContext::getContainer()->set(SupplierDriverFactory::class, $factory);

        $this->assertSame(ErrorCode::ExpressChannelUnavailable->value, $this->quote($merchant)['code']);
    }

    /**
     * 多家供应商时单家失败不影响整体，能查到的照常返回。
     */
    public function testOneFailingSupplierDoesNotSinkTheWholeQuote()
    {
        $merchant = $this->createMerchant();
        $healthy = $this->createSupplier();
        $broken = $this->createSupplier();

        $driver = Mockery::mock(YunyangDriver::class);
        $driver->shouldReceive('checkChannels')->andReturn([$this->channel('CH-ZTO', '中通', freight: '8.00')]);
        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('buildYunyang')->andReturnUsing(static fn (Supplier $s) => (int) $s->id === (int) $healthy->id
            ? $driver
            : throw new RuntimeException('config is broken'));
        ApplicationContext::getContainer()->set(SupplierDriverFactory::class, $factory);

        $channels = $this->quote($merchant)['data']['channels'];

        $this->assertCount(1, $channels);
        $this->assertSame('中通', $channels[0]['company_name']);
    }

    public function testMerchantWithoutExpressSubscriptionIsRejected()
    {
        $merchant = $this->createMerchant(subscribed: false);
        $supplier = $this->createSupplier();
        $this->bindChannels($supplier, [$this->channel('CH-SF', '顺丰', freight: '15.00')]);

        $this->assertSame(ErrorCode::BusinessNotSubscribed->value, $this->quote($merchant)['code']);
    }

    public function testInvalidParamsAreRejectedWithTheParamErrorCode()
    {
        $merchant = $this->createMerchant();
        $supplier = $this->createSupplier();
        $this->bindChannels($supplier, [$this->channel('CH-SF', '顺丰', freight: '15.00')]);

        $cases = [
            ['sender_province' => ''],
            ['receiver_city' => ''],
            ['weight' => '0'],
            ['weight' => '1.5'],
            ['weight' => 'abc'],
            // 三边只传一边
            ['length' => '30'],
            ['insured_amount' => '0'],
            ['insured_amount' => '-5'],
        ];
        foreach ($cases as $override) {
            $body = $this->quote($merchant, $override);
            $this->assertSame(ErrorCode::InvalidParams->value, $body['code'], json_encode($override));
        }
    }

    /**
     * 没配快递加价规则时不能"按成本卖"：PricingRuleService 抛错，接口报系统错误而不是
     * 悄悄把成本价当售价返回。
     */
    public function testMissingPricingRuleDoesNotSellAtCost()
    {
        PricingRule::query()->where('business_line', 'express')->delete();
        $merchant = $this->createMerchant();
        $supplier = $this->createSupplier();
        $this->bindChannels($supplier, [$this->channel('CH-SF', '顺丰', freight: '15.00')]);

        $response = $this->postQuote($merchant, []);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(ErrorCode::InternalError->value, $body['code']);
        $this->assertStringNotContainsString('15.00', (string) $response->getBody(), '绝不能把成本价当售价返回');
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function quote(Merchant $merchant, array $override = []): array
    {
        return json_decode((string) $this->postQuote($merchant, $override)->getBody(), true);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function postQuote(Merchant $merchant, array $override)
    {
        $params = [
            'sender_province' => '广东省',
            'sender_city' => '深圳市',
            'sender_district' => '南山区',
            'sender_address' => '科技园 1 号',
            'receiver_province' => '北京市',
            'receiver_city' => '北京市',
            'receiver_district' => '朝阳区',
            'weight' => '3',
        ] + $override;
        // 传空串表示"就是要传这个非法值"，不要被默认值顶掉
        foreach ($override as $key => $value) {
            $params[$key] = $value;
        }

        return $this->client->request('POST', '/open-api/express/quote', [
            'form_params' => $this->signedParams($merchant->app_key, self::SECRET, $params),
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function signedParams(string $appKey, string $secret, array $extra): array
    {
        $params = [
            'app_key' => $appKey,
            'timestamp' => (string) time(),
            'nonce' => uniqid('nonce_', true),
        ] + $extra;
        $params['sign'] = (new SignatureSigner())->sign($params, $secret);

        return $params;
    }

    /**
     * @return array<string, mixed>
     */
    private function channel(
        string $channelId,
        string $name,
        string $freight,
        string $insured = '0.00',
        string $haocai = '0.00',
        bool $allowInsured = true
    ): array {
        return [
            'channel_id' => $channelId,
            'channel_name' => $name,
            'freight' => $freight,
            'freight_insured' => $insured,
            'freight_haocai' => $haocai,
            'total_freight' => $freight,
            'official_price' => null,
            'billing_rule' => '首重 1kg，续重每 kg',
            'allow_insured' => $allowInsured,
            'appointment_times' => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $channels
     */
    private function bindChannels(Supplier $supplier, array $channels): void
    {
        $driver = Mockery::mock(YunyangDriver::class);
        $driver->shouldReceive('checkChannels')->andReturn($channels);

        $factory = Mockery::mock(SupplierDriverFactory::class);
        $factory->shouldReceive('buildYunyang')->andReturnUsing(static fn (Supplier $s) => (int) $s->id === (int) $supplier->id
            ? $driver
            : throw new RuntimeException('not a supplier of this test'));
        ApplicationContext::getContainer()->set(SupplierDriverFactory::class, $factory);
    }

    private function createSupplier(): Supplier
    {
        $unique = uniqid('express_quote_supplier_', true);
        $supplier = Supplier::create([
            'name' => $unique,
            'code' => substr(md5($unique), 0, 24),
            'business_line' => 'express',
            'driver' => 'yunyang',
            'config' => 'unused-in-test-driver-factory-is-overridden',
            'status' => 'active',
        ]);
        $this->supplierIds[] = $supplier->id;

        return $supplier;
    }

    private function createMerchant(bool $subscribed = true): Merchant
    {
        $unique = uniqid('express_quote_', true);
        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'app_key' => 'app_key_' . $unique,
            'app_secret' => (new Encryptor())->encrypt(self::SECRET),
            'available_balance' => '100.00',
            'frozen_balance' => '0.00',
        ]);
        $this->merchantIds[] = $merchant->id;

        if ($subscribed) {
            MerchantBusinessSubscription::create([
                'merchant_id' => $merchant->id,
                'business_line' => 'express',
                'status' => 'approved',
                'applied_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $merchant;
    }
}
