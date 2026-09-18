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
use App\Exception\OpenApiException;
use App\Model\Merchant;
use App\OpenApi\ErrorCode;
use App\Service\AbstractService;
use App\Service\Merchant\SubscriptionService;
use App\Service\Product\RebateCalculator;
use Hyperf\Di\Annotation\Inject;

/**
 * 开放 API「话费商品列表」（requirements.md 8.1："话费、卡券 | 商品列表 |
 * 商户已开通的商品（话费按运营商区分）、售价、该商户等级的每单返佣"）。
 *
 * 只列出商户已开通（requirements.md 4.2 开通审核通过）的业务线的商品，没开通返回
 * ErrorCode::BusinessNotSubscribed，不返回空列表——空列表会让商户误以为平台没有商品。
 */
class ProductListService extends AbstractService
{
    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected RebateCalculator $rebateCalculator;

    #[Inject]
    protected SubscriptionService $subscriptionService;

    /**
     * @return array<int, array{id: int, name: string, operator: null|string, province: null|string,
     *     charge_speed: null|string, face_value: string, sale_price: string, rebate: string}>
     */
    public function list(Merchant $merchant, string $businessLine): array
    {
        if (! $this->subscriptionService->isSubscribed((int) $merchant->id, $businessLine)) {
            throw new OpenApiException(ErrorCode::BusinessNotSubscribed);
        }

        $products = $this->productDao->listOnShelfByBusinessLine($businessLine);

        $result = [];
        foreach ($products as $product) {
            $result[] = [
                'id' => $product->id,
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
