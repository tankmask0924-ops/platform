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

use App\Service\Dev\MockExpressMovieService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;

/**
 * 前端联调造数据：`docker exec pf php bin/hyperf.php dev:mock-express-movie --merchant=11073`，
 * 给商户造快递、电影票订单和快递工单、卡速售售后争议、返佣对账差异，详见 App\Service\Dev\MockExpressMovieService。
 */
#[Command]
class MockExpressMovieCommand extends HyperfCommand
{
    public function __construct(private readonly MockExpressMovieService $service)
    {
        parent::__construct('dev:mock-express-movie');
    }

    public function configure(): void
    {
        $this->setDescription('给商户造快递、电影票模拟订单及工单/售后/对账数据（仅开发/测试环境，不调用供应商）')
            ->addOption('merchant', null, InputOption::VALUE_REQUIRED, '商户 ID');

        parent::configure();
    }

    public function handle(): int
    {
        try {
            $rows = $this->service->create((int) $this->input->getOption('merchant'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['平台单号', '业务', '状态', '说明'], array_map(array_values(...), $rows));

        return self::SUCCESS;
    }
}
