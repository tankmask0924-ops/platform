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
use App\Service\Merchant\DisputeService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 商户管理后台「售后：未到账争议提交与查看」，逻辑见 App\Service\Merchant\DisputeService。
 * 商户身份只从登录态取。
 */
#[Controller(prefix: '/merchant/disputes')]
class DisputeController extends AbstractController
{
    #[Inject]
    protected DisputeService $disputeService;

    #[Middleware(MerchantAuthMiddleware::class)]
    #[PostMapping(path: '')]
    public function store(): array
    {
        return $this->disputeService->submit($this->currentMerchant(), $this->request->input('order_no'));
    }

    #[Middleware(MerchantAuthMiddleware::class)]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->disputeService->list($this->currentMerchant(), $this->request->all());
    }

    #[Middleware(MerchantAuthMiddleware::class)]
    #[GetMapping(path: '{id:\d+}')]
    public function show(int $id): array
    {
        return $this->disputeService->detail($this->currentMerchant(), $id);
    }

    private function currentMerchant(): Merchant
    {
        /* @var Merchant $merchant */
        return $this->request->getAttribute('merchant');
    }
}
