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

namespace App\Controller\Admin;

use App\Annotation\RequiresPermission;
use App\Controller\AbstractController;
use App\Middleware\AdminAuthMiddleware;
use App\Middleware\AdminPermissionMiddleware;
use App\Model\AdminUser;
use App\Service\Product\PricingRuleService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 系统管理后台「价格设置：电影票 / 快递加价规则 + 价格预览」（requirements.md 8.3、5.1）。
 *
 * 两个权限编码：`pricing.view` 看规则和预览，`pricing.manage` 改规则。分档的理由跟商品
 * 定价一致——看价格是运营日常，改加价规则直接改所有商户的售价，要收窄。
 * 记得同步维护 App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS。
 *
 * 没有新建和删除接口：业务线是固定的两条（电影票、快递），每条只有一条当前生效的规则，
 * 只能改不能删——删掉规则会让下单直接失败（PricingRuleService::salePriceFor() 抛错），
 * 而"不想加价"应该显式设成加价 0，不是把规则删了。
 */
#[Controller(prefix: '/admin/pricing-rules')]
class PricingRuleController extends AbstractController
{
    #[Inject]
    protected PricingRuleService $pricingRuleService;

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('pricing.view')]
    #[GetMapping(path: '')]
    public function index(): array
    {
        return $this->pricingRuleService->list();
    }

    /**
     * 价格预览。不传 rule_type/value 就按已保存的规则算，传了就算"改成这样会是多少"。
     */
    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('pricing.view')]
    #[GetMapping(path: 'preview')]
    public function preview(): array
    {
        return $this->pricingRuleService->preview($this->request->all());
    }

    #[Middleware(AdminAuthMiddleware::class)]
    #[Middleware(AdminPermissionMiddleware::class)]
    #[RequiresPermission('pricing.manage')]
    #[PutMapping(path: '{businessLine}')]
    public function update(string $businessLine): array
    {
        return $this->pricingRuleService->save($this->currentAdmin(), $businessLine, $this->request->all());
    }

    private function currentAdmin(): AdminUser
    {
        /* @var AdminUser $admin */
        return $this->request->getAttribute('admin');
    }
}
