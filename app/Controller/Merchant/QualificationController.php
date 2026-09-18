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
use App\Service\Merchant\QualificationService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 商户后台「资质资料提交与审核状态」（requirements.md 4.1、8.2）。
 * 待审核、被驳回的商户也能访问（MerchantAuthMiddleware 只拦禁用）。
 */
#[Controller(prefix: '/merchant/qualification')]
class QualificationController extends AbstractController
{
    #[Inject]
    protected QualificationService $qualificationService;

    #[Middleware(MerchantAuthMiddleware::class)]
    #[GetMapping(path: '')]
    public function show(): array
    {
        return $this->qualificationService->current($this->merchant());
    }

    /**
     * 被驳回后重新提交，字段同注册接口的资质部分（可以换商户类型）。
     */
    #[Middleware(MerchantAuthMiddleware::class)]
    #[PostMapping(path: '')]
    public function resubmit(): array
    {
        return $this->qualificationService->resubmit($this->merchant(), (array) $this->request->getParsedBody());
    }

    private function merchant(): Merchant
    {
        /** @var Merchant $merchant */
        return $this->request->getAttribute('merchant');
    }
}
