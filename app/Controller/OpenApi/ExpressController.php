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
use App\OpenApi\ErrorCode;
use App\Service\OpenApi\ExpressOrderService;
use App\Service\OpenApi\ExpressQuoteService;
use App\Service\Order\ExpressOrderPlacementService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 开放 API 快递接口（requirements.md 7.2、8.1，docs/modules.md 第 6 节）。
 * 中间件挂载方式沿用 App\Controller\OpenApi\BalanceController 定下的模式。
 *
 * 查价用 POST 而不是 GET：入参是寄收件地址、重量、体积十来个字段，而地址属于个人信息，
 * 不该出现在 URL 和访问日志里。它本身是只读的，不产生订单、不冻结任何金额。
 * 参数是扁平的标量字段（`sender_province` 这种），因为开放 API 的签名只能拼标量，
 * 理由见 ExpressQuoteService::validate()。
 *
 * 下单（`express/order`）、取消（`express/cancel`）同样用 POST；轨迹（`express/trace`）只带订单号，
 * 用 GET，跟订单查询 `GET /open-api/order` 一样按平台单号或商户单号二选一。
 *
 * 口径和取舍见 App\Service\OpenApi\ExpressQuoteService、
 * App\Service\Order\ExpressOrderPlacementService、App\Service\OpenApi\ExpressOrderService 类注释。
 */
#[Controller(prefix: '/open-api')]
#[Middleware(OpenApiSignatureMiddleware::class)]
class ExpressController extends AbstractOpenApiController
{
    #[Inject]
    protected ExpressQuoteService $expressQuoteService;

    #[Inject]
    protected ExpressOrderPlacementService $placementService;

    #[Inject]
    protected ExpressOrderService $expressOrderService;

    #[PostMapping(path: 'express/quote')]
    public function quote(): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->request->getAttribute('merchant');

        return $this->success($this->expressQuoteService->quote($merchant, $this->request->all()));
    }

    #[PostMapping(path: 'express/order')]
    public function order(): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->request->getAttribute('merchant');

        $merchantOrderNo = $this->normalizeString($this->request->input('merchant_order_no'));
        $channelCode = $this->normalizeString($this->request->input('channel_code'));
        $callbackUrl = $this->normalizeString($this->request->input('callback_url'));
        if ($merchantOrderNo === null
            || mb_strlen($merchantOrderNo) > 64
            || $channelCode === null
            || $callbackUrl === null
            || filter_var($callbackUrl, FILTER_VALIDATE_URL) === false
        ) {
            return $this->fail(ErrorCode::InvalidParams, 'merchant_order_no/channel_code/callback_url 缺失或格式错误');
        }

        return $this->success($this->placementService->place(
            $merchant,
            $merchantOrderNo,
            $channelCode,
            $callbackUrl,
            $this->request->all()
        ));
    }

    #[PostMapping(path: 'express/cancel')]
    public function cancel(): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->request->getAttribute('merchant');
        [$orderNo, $merchantOrderNo] = $this->orderIdentifiers();
        if ($orderNo === null && $merchantOrderNo === null) {
            return $this->fail(ErrorCode::InvalidParams, 'order_no 和 merchant_order_no 必须传一个');
        }

        return $this->success($this->expressOrderService->cancel($merchant, $orderNo, $merchantOrderNo));
    }

    #[GetMapping(path: 'express/trace')]
    public function trace(): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->request->getAttribute('merchant');
        [$orderNo, $merchantOrderNo] = $this->orderIdentifiers();
        if ($orderNo === null && $merchantOrderNo === null) {
            return $this->fail(ErrorCode::InvalidParams, 'order_no 和 merchant_order_no 必须传一个');
        }

        return $this->success($this->expressOrderService->trace($merchant, $orderNo, $merchantOrderNo));
    }

    /**
     * @return array{0: null|string, 1: null|string}
     */
    private function orderIdentifiers(): array
    {
        return [
            $this->normalizeString($this->request->input('order_no')),
            $this->normalizeString($this->request->input('merchant_order_no')),
        ];
    }

    private function normalizeString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
