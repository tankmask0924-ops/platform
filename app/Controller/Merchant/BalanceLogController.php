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
use App\Service\Merchant\BalanceLogService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;

/**
 * 商户管理后台（web/merchant）「资金流水：查询」（requirements.md 4.4/4.5、7.2），
 * docs/modules.md 第 7 节。结构模板取自
 * App\Controller\Merchant\RechargeRequestController：方法级
 * `#[Middleware(MerchantAuthMiddleware::class)]`，商户身份从
 * `$request->getAttribute('merchant')` 取，不接受请求体/查询参数里任何形式的
 * merchant_id 覆盖，杜绝 IDOR。
 *
 * 单独开一个 Controller 而不是塞进 RechargeRequestController：资金流水覆盖的是
 * 全部余额变动类型（充值只是其中一种），语义上是一个独立的模块
 * （docs/modules.md 第 7 节本身也是单独一行"资金流水：查询与导出"）。
 */
#[Controller(prefix: '/merchant/balance-logs')]
class BalanceLogController extends AbstractController
{
    #[Inject]
    protected BalanceLogService $balanceLogService;

    #[Middleware(MerchantAuthMiddleware::class)]
    #[GetMapping(path: '')]
    public function index(): array
    {
        $page = (int) $this->request->input('page', 1);
        $perPage = (int) $this->request->input('per_page', 15);
        $type = $this->request->input('type');

        return $this->balanceLogService->list($this->currentMerchant(), $page, $perPage, $type);
    }

    private function currentMerchant(): Merchant
    {
        /* @var Merchant $merchant */
        return $this->request->getAttribute('merchant');
    }
}
