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

namespace App\Controller\OpenApi;

use App\Middleware\OpenApiSignatureMiddleware;
use App\Model\Merchant;
use App\Service\Order\CardOrderPlacementService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 卡券下单（requirements.md 8.1「下单」卡券一侧，话费参数不同、单独设计，见
 * `App\Controller\OpenApi\RechargeOrderController`）。中间件挂载方式跟其它
 * OpenApi Controller 一致（`#[Middleware]` 类级注解，原因见
 * `App\Controller\OpenApi\BalanceController` 类注释）。
 *
 * 本 Controller 只做输入的「有没有传、形状对不对」这类浅层校验；`recharge_account`
 * 该不该必填这条业务规则（取决于商品的 `card_type`）需要先查一次商品才能判断，
 * 这里管不了，留给 `App\Service\Order\CardOrderPlacementService` 抛
 * `HttpException(422)`——跟商品是否存在/是否卡券业务线/是否上架同一套既有约定
 * （不经过这里的 `fail()` envelope）。这里只负责一件事："传了就必须是非空字符串"，
 * 不判断"该不该传"。
 *
 * 错误码占位（跟 `RechargeOrderController` 的 40020 同一个风格，不是平台统一
 * 错误码）：
 *   40021 必填参数缺失或形状不对（merchant_order_no/product_id/callback_url 缺失，
 *          或 recharge_account 传了但不是非空字符串）
 */
#[Controller(prefix: '/open-api')]
#[Middleware(OpenApiSignatureMiddleware::class)]
class CardOrderController extends AbstractOpenApiController
{
    private const CODE_INVALID_PARAMS = 40021;

    #[Inject]
    protected CardOrderPlacementService $placementService;

    #[PostMapping(path: 'orders/card')]
    public function create(): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->request->getAttribute('merchant');

        $merchantOrderNo = $this->normalizeString($this->request->input('merchant_order_no'));
        $productId = $this->request->input('product_id');
        $callbackUrl = $this->normalizeString($this->request->input('callback_url'));

        // recharge_account 是否必填取决于商品的 card_type，这里管不了；但"传了就
        // 必须是非空字符串"是纯粹的输入形状校验，跟其它必填字段同一层。字段完全
        // 不存在于请求里时视为"没传"（null），交给 Service 按 card_type 判断是否
        // 允许缺失；传了但不是非空字符串则直接当成格式错误拒绝。
        $rechargeAccountProvided = $this->request->input('recharge_account');
        $rechargeAccount = null;
        if ($rechargeAccountProvided !== null && $rechargeAccountProvided !== '') {
            $rechargeAccount = $this->normalizeString($rechargeAccountProvided);
            if ($rechargeAccount === null) {
                return $this->fail(
                    self::CODE_INVALID_PARAMS,
                    'recharge_account is malformed'
                );
            }
        }

        if ($merchantOrderNo === null
            || $callbackUrl === null
            || ! $this->isPositiveIntLike($productId)
            || filter_var($callbackUrl, FILTER_VALIDATE_URL) === false
        ) {
            return $this->fail(
                self::CODE_INVALID_PARAMS,
                'merchant_order_no/product_id/callback_url is missing or malformed'
            );
        }

        $result = $this->placementService->place(
            $merchant,
            $merchantOrderNo,
            (int) $productId,
            $rechargeAccount,
            $callbackUrl
        );

        return $this->success($result);
    }

    private function normalizeString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isPositiveIntLike(mixed $value): bool
    {
        return is_numeric($value) && (int) $value > 0 && (string) (int) $value === (string) $value;
    }
}
