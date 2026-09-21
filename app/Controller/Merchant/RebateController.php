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

namespace App\Controller\Merchant;

use App\Controller\AbstractController;
use App\Middleware\MerchantAuthMiddleware;
use App\Model\Merchant;
use App\Service\Product\RebateQueryService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 商户后台「返佣明细查询与导出」（requirements.md 8.2），只能看自己的返佣。
 */
#[Controller(prefix: '/merchant/rebates')]
class RebateController extends AbstractController
{
    #[Inject]
    protected RebateQueryService $rebateQueryService;

    #[Middleware(MerchantAuthMiddleware::class)]
    #[GetMapping(path: '')]
    public function index(): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->request->getAttribute('merchant');

        return $this->rebateQueryService->listForMerchant($merchant, $this->request->all());
    }

    /**
     * 导出：同一套筛选、不分页，返回 JSON 由前端拼 CSV，
     * 原因见 App\Service\Merchant\BalanceLogService::export()。
     */
    #[Middleware(MerchantAuthMiddleware::class)]
    #[GetMapping(path: 'export')]
    public function export(): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->request->getAttribute('merchant');

        return $this->rebateQueryService->exportForMerchant($merchant, $this->request->all());
    }
}
