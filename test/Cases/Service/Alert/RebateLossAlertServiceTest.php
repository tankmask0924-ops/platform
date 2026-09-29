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

use App\Model\Alert;
use App\Model\Order;
use App\Service\Alert\AlertService;
use App\Service\Alert\RebateLossAlertService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\TestCase;
use Mockery;
use Mockery\MockInterface;

use function Hyperf\Support\make;

/**
 * App\Service\Alert\RebateLossAlertService：毛利 + 供应商返佣 − 商户返佣 < 0 时报返佣后亏本。
 * 纯计算，订单不落库，AlertService mock 掉。话费接入点的真实链路见 OrderResultApplierRebateTest。
 *
 * @internal
 * @coversNothing
 */
class RebateLossAlertServiceTest extends TestCase
{
    private MockInterface $alertService;

    protected function setUp(): void
    {
        parent::setUp();
        ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
        ApplicationContext::getContainer()->get(ApplicationInterface::class);
        $this->alertService = Mockery::mock(AlertService::class);
        ApplicationContext::getContainer()->set(AlertService::class, $this->alertService);
    }

    protected function tearDown(): void
    {
        // Mockery 的 once() / shouldNotReceive() 在这里校验，PHPUnit 不计入断言数，各用例自己补一条
        Mockery::close();
        parent::tearDown();
    }

    /**
     * requirements.md 5.3 例 2 的电影票，把商户比例调到 150%：3.00 × 150% = 4.50，
     * 毛利 2.00 + 供应商返佣 3.00 − 4.50 = 0.50，仍然赚钱，不报。
     */
    public function testMovieRatioAbove100PercentButStillProfitableIsNotReported()
    {
        $this->alertService->shouldNotReceive('raise');

        $this->service()->checkOrder($this->order('40.00', '38.00'), '4.50', '3.00');
        $this->addToAssertionCount(1);
    }

    public function testMovieLossIsReportedOnMerchant()
    {
        $this->alertService->shouldReceive('raise')->once()->with(
            Alert::TYPE_REBATE_LOSS,
            Alert::LEVEL_WARNING,
            '商户 #77 返佣后亏本：订单 P-TEST 毛利 0.50 + 供应商返佣 3.00 − 商户返佣 4.50 = -1.00',
            'merchant',
            77
        );

        $this->service()->checkOrder($this->order('40.00', '39.50'), '4.50', '3.00');
        $this->addToAssertionCount(1);
    }

    public function testBreakEvenIsNotReported()
    {
        $this->alertService->shouldNotReceive('raise');

        $this->service()->checkOrder($this->order('10.00', '9.70'), '0.30', '0.00', 5);
        $this->addToAssertionCount(1);
    }

    public function testRechargeLossIsReportedOnProduct()
    {
        $this->alertService->shouldReceive('raise')->once()->with(
            Alert::TYPE_REBATE_LOSS,
            Alert::LEVEL_WARNING,
            Mockery::on(static fn (string $message) => str_starts_with($message, '商品 #5 返佣后亏本') && str_ends_with($message, '= -0.01')),
            'product',
            5
        );

        $this->service()->checkOrder($this->order('10.00', '9.71'), '0.30', '0.00', 5);
        $this->addToAssertionCount(1);
    }

    private function service(): RebateLossAlertService
    {
        return make(RebateLossAlertService::class);
    }

    private function order(string $salePrice, string $costPrice): Order
    {
        return (new Order())->forceFill([
            'order_no' => 'P-TEST',
            'merchant_id' => 77,
            'sale_price' => $salePrice,
            'cost_price' => $costPrice,
        ]);
    }
}
