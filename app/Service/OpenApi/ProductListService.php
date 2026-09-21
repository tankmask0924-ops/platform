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
 * 开放 API「话费、卡券商品列表」（requirements.md 8.1："话费、卡券 | 商品列表 |
 * 商户已开通的商品（话费按运营商区分）、售价、该商户等级的每单返佣"）。
 *
 * 只列出商户已开通（requirements.md 4.2 开通审核通过）的业务线的商品，没开通返回
 * ErrorCode::BusinessNotSubscribed，不返回空列表——空列表会让商户误以为平台没有商品。
 *
 * **两条业务线返回同一组字段，用不上的给 null**（话费的 `card_type` 为 null，卡券的
 * `operator`/`province`/`charge_speed` 为 null），跟商户后台「商品价格」页
 * App\Service\Merchant\ProductPriceService::list() 返回的字段完全一致——商户在后台
 * 页面上看到的和从开放 API 拿到的是同一份数据，不用对着两份字段表做映射。给话费加上
 * `card_type: null` 是纯新增字段，老调用方忽略未知字段即可，不破坏已上线的话费列表契约。
 *
 * **`card_type` 对卡券是必要字段，不是锦上添花**：`direct`（直充）下单必须传
 * `recharge_account`，`card_secret`（卡密）必须不传，传了直接拒绝
 * （见 App\Service\Order\CardOrderPlacementService 类注释）。商品列表不给出这个字段，
 * 商户就没有任何办法知道该不该传充值账号。
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
     * @param string $businessLine 已由 Controller 校验，只会是 recharge / card
     * @return array<int, array{id: int, name: string, operator: null|string, province: null|string,
     *     charge_speed: null|string, card_type: null|string, face_value: string,
     *     sale_price: string, rebate: string}>
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
                // 卡券专用：direct（直充，下单必传 recharge_account）/ card_secret（卡密，不传）
                'card_type' => $product->card_type,
                'face_value' => $product->face_value,
                'sale_price' => $product->sale_price,
                'rebate' => $this->rebateCalculator->calculate($product, $merchant->level_id),
            ];
        }

        return $result;
    }
}
