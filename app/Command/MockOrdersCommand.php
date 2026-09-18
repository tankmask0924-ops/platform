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

namespace App\Command;

use App\Service\Dev\MockOrderService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;

/**
 * 前端联调造数据：`docker exec pf php bin/hyperf.php dev:mock-orders --merchant=11073`，
 * 每跑一次给商户新增一批各种状态的话费模拟订单，详见 App\Service\Dev\MockOrderService。
 */
#[Command]
class MockOrdersCommand extends HyperfCommand
{
    public function __construct(private readonly MockOrderService $mockOrderService)
    {
        parent::__construct('dev:mock-orders');
    }

    public function configure(): void
    {
        $this->setDescription('给商户造一批话费模拟订单（仅开发/测试环境，不调用供应商）')
            ->addOption('merchant', null, InputOption::VALUE_REQUIRED, '商户 ID');

        parent::configure();
    }

    public function handle(): int
    {
        try {
            $orders = $this->mockOrderService->create((int) $this->input->getOption('merchant'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['平台单号', '状态', '金额', '说明'], array_map(array_values(...), $orders));

        return self::SUCCESS;
    }
}
