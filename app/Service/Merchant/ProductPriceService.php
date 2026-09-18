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

namespace App\Service\Merchant;

use App\Dao\MerchantLevelBusinessRateDao;
use App\Dao\ProductDao;
use App\Model\Merchant;
use App\Model\Product;
use App\Service\AbstractService;
use App\Service\Product\RebateCalculator;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户后台「商品价格」（requirements.md 8.2）：已开通业务线的在架商品、售价和按商户当前等级算出的每单返佣
 * （跟开放 API 商品列表同一个 RebateCalculator）；电影票、快递没有固定商品，显示本等级在该业务线的返佣比例。
 * 没开通的业务线不返回商品，页面引导去申请开通。返佣基数、比例来源是平台内部数据，不返回。
 */
class ProductPriceService extends AbstractService
{
    /** 有本地商品库的业务线；电影票、快递按比例返佣 */
    private const CATALOG_BUSINESS_LINES = ['recharge', 'card'];

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected RebateCalculator $rebateCalculator;

    #[Inject]
    protected MerchantLevelBusinessRateDao $levelRateDao;

    #[Inject]
    protected SubscriptionService $subscriptionService;

    /**
     * @return array<string, mixed>
     */
    public function list(Merchant $merchant, mixed $businessLine): array
    {
        if (! is_string($businessLine) || ! in_array($businessLine, SubscriptionService::BUSINESS_LINES, true)) {
            throw new HttpException(422, 'business_line 不合法');
        }

        $subscribed = $this->subscriptionService->isSubscribed((int) $merchant->id, $businessLine);
        $levelId = $merchant->level_id !== null ? (int) $merchant->level_id : null;
        $levelRate = $levelId !== null ? $this->levelRateDao->findForLevelAndBusinessLine($levelId, $businessLine)?->rebate_rate : null;

        $products = [];
        if ($subscribed && in_array($businessLine, self::CATALOG_BUSINESS_LINES, true)) {
            $products = $this->productDao->listOnShelfByBusinessLine($businessLine)
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'operator' => $product->operator,
                    'province' => $product->province,
                    'charge_speed' => $product->charge_speed,
                    'card_type' => $product->card_type,
                    'face_value' => $product->face_value,
                    'sale_price' => $product->sale_price,
                    'rebate' => $this->rebateCalculator->calculate($product, $levelId),
                ])
                ->values()
                ->all();
        }

        return [
            'business_line' => $businessLine,
            'available' => in_array($businessLine, SubscriptionService::OPEN_BUSINESS_LINES, true),
            'subscribed' => $subscribed,
            // 本等级在该业务线的默认返佣比例（1 = 100%）；个别商品可能单独设置了比例，以商品的每单返佣为准
            'level_rate' => $levelRate,
            'data' => $products,
        ];
    }
}
