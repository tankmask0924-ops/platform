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

namespace HyperfTest\Cases\Service\Alert;

use App\Dao\OrderDao;
use App\Model\Alert;
use App\Model\Merchant;
use App\Model\Order;
use App\Model\SystemSetting;
use App\Service\Alert\AlertPatrolService;
use App\Service\Alert\AlertService;
use App\Service\Merchant\BalanceService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\TestCase;
use Mockery;

use function Hyperf\Support\make;

/**
 * App\Service\Alert\AlertPatrolService：商户欠款超预警线、异常单积压两类巡检告警。
 *
 * 两类都是扫全库（测试库共享，别人的欠款商户、异常单也在里面），所以 AlertService 一律 mock，
 * 只断言本用例的数据有没有被报、报的内容对不对，不往共享库写告警。
 *
 * @internal
 * @coversNothing
 */
class AlertPatrolServiceTest extends TestCase
{
    private array $merchantIds = [];

    private ?array $backlogSettingBackup = null;

    /**
     * @var list<array{type: string, level: string, message: string, related_type: null|string, related_id: null|int}>
     */
    private array $raised = [];

    protected function setUp(): void
    {
        parent::setUp();
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);

        $alertService = Mockery::mock(AlertService::class);
        $alertService->shouldReceive('raise')->andReturnUsing(function (string $type, string $level, string $message, ?string $relatedType = null, ?int $relatedId = null) {
            $this->raised[] = ['type' => $type, 'level' => $level, 'message' => $message, 'related_type' => $relatedType, 'related_id' => $relatedId];
        });
        ApplicationContext::getContainer()->set(AlertService::class, $alertService);

        $this->backlogSettingBackup = SystemSetting::where('key', AlertPatrolService::ABNORMAL_BACKLOG_THRESHOLD_SETTING_KEY)->first()?->getAttributes();
    }

    protected function tearDown(): void
    {
        Order::whereIn('merchant_id', $this->merchantIds ?: [0])->delete();
        Merchant::destroy($this->merchantIds);
        $this->merchantIds = $this->raised = [];

        SystemSetting::where('key', AlertPatrolService::ABNORMAL_BACKLOG_THRESHOLD_SETTING_KEY)->delete();
        if ($this->backlogSettingBackup !== null) {
            SystemSetting::insert($this->backlogSettingBackup);
        }
        Mockery::close();

        parent::tearDown();
    }

    public function testMerchantWithDebtBeyondThresholdIsReported()
    {
        $threshold = make(BalanceService::class)->debtWarningThreshold();
        $over = $this->createMerchant(bcsub(bcmul($threshold, '-1', 2), '0.01', 2));
        $atLine = $this->createMerchant(bcmul($threshold, '-1', 2));
        $positive = $this->createMerchant('5.00');

        $this->service()->checkMerchantDebt();

        $mine = $this->raisedFor(Alert::TYPE_MERCHANT_DEBT_EXCEEDED);
        $this->assertSame([$over->id], array_column($mine, 'related_id'), '正好等于预警线不算超，余额为正更不算');
        $this->assertSame('merchant', $mine[0]['related_type']);
        $this->assertSame(Alert::LEVEL_WARNING, $mine[0]['level']);
        $this->assertStringContainsString(sprintf('欠款 %s 元，超过预警线 %s 元', bcadd($threshold, '0.01', 2), $threshold), $mine[0]['message']);
        $this->assertNotContains($atLine->id, array_column($mine, 'related_id'));
        $this->assertNotContains($positive->id, array_column($mine, 'related_id'));
    }

    public function testAbnormalBacklogReachingThresholdRaisesGlobalAlert()
    {
        $merchant = $this->createMerchant('0.00');
        $existing = make(OrderDao::class)->abnormalBacklog()['count'];
        $this->createOrder($merchant, 'abnormal');
        $this->createOrder($merchant, 'abnormal');
        $this->createOrder($merchant, 'processing');
        $this->setBacklogThreshold($existing + 2);

        [$count, $raised] = $this->service()->checkAbnormalBacklog();

        $this->assertSame($existing + 2, $count, '只数异常单，处理中的不算');
        $this->assertTrue($raised);
        $alerts = $this->raisedFor(Alert::TYPE_ABNORMAL_ORDER_BACKLOG);
        $this->assertCount(1, $alerts);
        $this->assertNull($alerts[0]['related_type'], '积压是全局告警');
        $this->assertNull($alerts[0]['related_id']);
        $this->assertStringContainsString(sprintf('异常单积压 %d 笔（阈值 %d 笔）', $existing + 2, $existing + 2), $alerts[0]['message']);
    }

    public function testAbnormalBacklogBelowThresholdRaisesNothing()
    {
        $merchant = $this->createMerchant('0.00');
        $existing = make(OrderDao::class)->abnormalBacklog()['count'];
        $this->createOrder($merchant, 'abnormal');
        $this->setBacklogThreshold($existing + 2);

        [, $raised] = $this->service()->checkAbnormalBacklog();

        $this->assertFalse($raised);
        $this->assertSame([], $this->raisedFor(Alert::TYPE_ABNORMAL_ORDER_BACKLOG));
    }

    public function testBacklogThresholdFallsBackToDefaultWhenSettingIsInvalid()
    {
        $this->setBacklogThreshold(0);

        $this->assertSame(AlertPatrolService::DEFAULT_ABNORMAL_BACKLOG_THRESHOLD, $this->service()->abnormalBacklogThreshold());
    }

    private function service(): AlertPatrolService
    {
        return make(AlertPatrolService::class);
    }

    /**
     * 只看本用例建的商户 / 全局告警，别人的欠款商户在共享库里也会被报。
     */
    private function raisedFor(string $type): array
    {
        return array_values(array_filter(
            $this->raised,
            fn (array $alert) => $alert['type'] === $type && ($alert['related_id'] === null || in_array($alert['related_id'], $this->merchantIds, true))
        ));
    }

    private function setBacklogThreshold(int $value): void
    {
        SystemSetting::query()->updateOrInsert(
            ['key' => AlertPatrolService::ABNORMAL_BACKLOG_THRESHOLD_SETTING_KEY],
            ['value' => (string) $value, 'updated_at' => date('Y-m-d H:i:s')]
        );
    }

    private function createMerchant(string $availableBalance): Merchant
    {
        $unique = uniqid('alert_patrol_test_', true);
        $merchant = Merchant::create([
            'type' => 'company',
            'email' => $unique . '@example.com',
            'password' => 'hashed-password',
            'status' => 'active',
            'available_balance' => $availableBalance,
            'frozen_balance' => '0.00',
        ]);
        $this->merchantIds[] = $merchant->id;

        return $merchant;
    }

    private function createOrder(Merchant $merchant, string $status): Order
    {
        return Order::create([
            'order_no' => 'AP' . str_replace('.', '', uniqid('', true)),
            'merchant_id' => $merchant->id,
            'merchant_order_no' => uniqid('m', true),
            'business_line' => 'recharge',
            'status' => $status,
            'sale_price' => '10.00',
            'cost_price' => '0.00',
            'frozen_amount' => '10.00',
            'refunded_amount' => '0.00',
            'callback_url' => 'https://example.com/callback',
        ]);
    }
}
