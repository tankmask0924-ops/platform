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
use App\Service\Merchant\SubscriptionService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 商户后台「服务开通」（requirements.md 4.2、8.2）：查看可开通的业务线、提交申请、查看审核状态。
 */
#[Controller(prefix: '/merchant/subscriptions')]
class SubscriptionController extends AbstractController
{
    #[Inject]
    protected SubscriptionService $subscriptionService;

    #[Middleware(MerchantAuthMiddleware::class)]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return ['data' => $this->subscriptionService->list($this->merchant())];
    }

    #[Middleware(MerchantAuthMiddleware::class)]
    #[PostMapping(path: '')]
    public function apply(): array
    {
        return ['data' => $this->subscriptionService->apply($this->merchant(), $this->request->input('business_line'))];
    }

    private function merchant(): Merchant
    {
        /** @var Merchant $merchant */
        return $this->request->getAttribute('merchant');
    }
}
