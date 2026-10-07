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
use App\Service\Order\RechargeStatsService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 商户后台「话费统计」：自己话费订单的耗时与成功率，口径见 App\Service\Order\RechargeStatsService。
 * 商户只能看自己的订单：商户 id 取自登录态，请求里的 merchant_id 一律不认。
 */
#[Controller(prefix: '/merchant/recharge-stats')]
class RechargeStatsController extends AbstractController
{
    #[Inject]
    protected RechargeStatsService $statsService;

    #[Middleware(MerchantAuthMiddleware::class)]
    #[GetMapping(path: '')]
    public function index(): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->request->getAttribute('merchant');

        return $this->statsService->stats($this->request->all(), (int) $merchant->id);
    }
}
