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
use App\Service\Merchant\RechargeRequestService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 商户管理后台（web/merchant）「充值：提交申请 / 查看记录」（requirements.md 4.3、8.2），
 * docs/modules.md 第 7 节。结构模板取自 App\Controller\Merchant\DevSettingsController，
 * 但这里两个路由各自单独挂方法级 `#[Middleware(MerchantAuthMiddleware::class)]`
 * 而不是类级——跟 DevSettingsController 全部方法都需要登录态不同，这个约定纯粹
 * 是本任务描述里明确写出的形状，方法级/类级两种写法在 Hyperf 这个版本都支持
 * （见 DevSettingsController 类注释），不影响实际鉴权效果。
 *
 * 商户永远只能看到/操作自己名下的申请：Controller 层从
 * `$request->getAttribute('merchant')`（MerchantAuthMiddleware 挂进去的、
 * 由 token 解出的商户模型）取商户身份，不接受请求体/查询参数里任何形式的
 * merchant_id 覆盖，杜绝 IDOR。
 */
#[Controller(prefix: '/merchant/recharge-requests')]
class RechargeRequestController extends AbstractController
{
    #[Inject]
    protected RechargeRequestService $rechargeRequestService;

    #[Middleware(MerchantAuthMiddleware::class)]
    #[PostMapping(path: '')]
    public function submit(): array
    {
        return $this->rechargeRequestService->submit(
            $this->currentMerchant(),
            $this->request->input('amount'),
            $this->request->input('proof_image'),
            $this->request->input('transfer_no')
        );
    }

    #[Middleware(MerchantAuthMiddleware::class)]
    #[GetMapping(path: '')]
    public function index(): array
    {
        $page = (int) $this->request->input('page', 1);
        $perPage = (int) $this->request->input('per_page', 15);

        return $this->rechargeRequestService->list($this->currentMerchant(), $page, $perPage);
    }

    private function currentMerchant(): Merchant
    {
        /* @var Merchant $merchant */
        return $this->request->getAttribute('merchant');
    }
}
