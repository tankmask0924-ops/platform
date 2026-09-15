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

namespace App\Service\OpenApi;

use App\Dao\ProductDao;
use App\Model\Merchant;
use App\Service\AbstractService;
use App\Service\Product\RebateCalculator;
use Hyperf\Di\Annotation\Inject;

/**
 * 开放 API「话费商品列表」（requirements.md 8.1："话费、卡券 | 商品列表 |
 * 商户已开通的商品（话费按运营商区分）、售价、该商户等级的每单返佣"）。
 *
 * 已知、刻意的范围限制：requirements.md 8.1 原文是"商户已开通的商品"，也就是只列出
 * 商户在 4.2 节"开通服务"流程里被运营审核通过的业务线。但那套开通/审核机制
 * （merchant_business_subscriptions 表已建，见
 * migrations/2026_09_14_090900_create_merchant_business_subscriptions_table.php）
 * 目前完全没有 Model/Dao/审核后台，是 docs/modules.md 第 7 节"服务开通：查看可开通业务线 /
 * 提交申请 / 查看状态"这一整行独立、未开工的功能（三个状态列都是 ⬜）。这里没有办法
 * 去校验一个不存在的东西，所以这个方法只要求商户已通过 OpenApiSignatureMiddleware
 * 鉴权（意味着商户存在且 status = active），就返回该业务线全部在架商品——等
 * "服务开通"那一整行功能落地后，再在这里补上按 merchant_business_subscriptions
 * 过滤的逻辑。这跟 App\Controller\OpenApi\BalanceController / OrderController
 * 先把接口本身做成真实可用、再等下游功能补上门槛检查的做法是同一个模式，不是新的偷懒方式。
 */
class ProductListService extends AbstractService
{
    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected RebateCalculator $rebateCalculator;

    /**
     * @return array<int, array{name: string, operator: null|string, province: null|string,
     *     charge_speed: null|string, face_value: string, sale_price: string, rebate: string}>
     */
    public function list(Merchant $merchant, string $businessLine): array
    {
        $products = $this->productDao->listOnShelfByBusinessLine($businessLine);

        $result = [];
        foreach ($products as $product) {
            $result[] = [
                'name' => $product->name,
                'operator' => $product->operator,
                'province' => $product->province,
                'charge_speed' => $product->charge_speed,
                'face_value' => $product->face_value,
                'sale_price' => $product->sale_price,
                'rebate' => $this->rebateCalculator->calculate($product, $merchant->level_id),
            ];
        }

        return $result;
    }
}
