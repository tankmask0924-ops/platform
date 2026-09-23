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

namespace App\Controller;

use App\Exception\CallbackOrderNotFoundException;
use App\Exception\InvalidSupplierCallbackSignatureException;
use App\Exception\SupplierNotFoundException;
use App\Service\Admin\DisputeSupplierAftersaleService;
use App\Service\Order\MovieCallbackService;
use App\Service\Order\SupplierCallbackService;
use App\Service\Supplier\ProductSyncService;
use App\Service\Supplier\SupplierNotifyAddressService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * 供应商回调统一入口（requirements.md 6.8，docs/modules.md 第 1 节"供应商回调入口
 * 与验签框架"）：`POST /notify/{code}/{token}`（订单结果回调）和 `POST /notify/{code}/{token}/goods`
 * （商品变更通知），`{code}` 是 `suppliers.code`，`{token}` 是 `suppliers.notify_token`
 * （地址由 App\Service\Supplier\SupplierNotifyAddressService 生成）。这是这个
 * 代码库里第三套、独立于 `App\Controller\OpenApi\*`（商户 HMAC 签名）和
 * `App\Controller\Admin\*`（管理员 JWT）的路由命名空间——**故意不挂
 * `App\Middleware\OpenApiSignatureMiddleware` 或任何既有鉴权中间件**：
 * 这条路由的调用方是供应商而不是本平台的商户/管理员，platform 完全不知道供应商
 * 会用什么方式认证自己，"验签"这件事本身就是这里唯一的、驱动特定的认证机制
 * （`App\Supplier\Kasushou\KasushouDriver::parseCallback()` 内部做），不能也不该
 * 套用商户侧的 HMAC 方案。
 *
 * 本 Controller 只做「读请求 -> 调 Service -> 把 Service 抛出的几种异常映射成
 * HTTP 状态码 -> 回纯文本」，不写任何业务逻辑，业务逻辑全部在
 * `App\Service\Order\SupplierCallbackService` 里，符合这个代码库
 * Controller/Service 分层的既有约定。
 *
 * 【响应体是纯文本，不是这个代码库其它地方常见的 {code,message,data} JSON 信封】
 * kasushou.md 要求成功时响应体必须是字面字符串 `ok`，这是供应商单方面定义的
 * 响应契约，用 `Hyperf\HttpServer\Contract\ResponseInterface::raw()`
 * （`Content-Type: text/plain`）而不是 `AbstractOpenApiController::success()`/
 * `fail()` 那套 JSON 信封。
 *
 * 【HTTP 状态码映射，本类自行选定，不是平台/供应商强制规定】：
 *   404 — 供应商编码不存在（`SupplierNotFoundException`），或者验签通过但反推不出
 *         一笔真实存在的平台订单（`CallbackOrderNotFoundException`）——两种都是
 *         "这个请求指向的资源不存在"，对外表现统一成 404，不区分细节（同
 *         `App\Controller\OpenApi\OrderController` 对"订单不存在"的处理原则：
 *         不向调用方泄露"存在但对不上"和"完全不存在"的区别）；
 *   403 — 验签失败（`InvalidSupplierCallbackSignatureException`）——请求到达了
 *         一个真实存在的供应商回调地址，但没有通过身份校验，跟"资源不存在"是
 *         不同性质的失败，必须能被单独识别（绝不能让验签失败被误当成 200/`ok`
 *         回复给供应商，那等于告诉一个可能伪造请求的调用方"你成功了"）；
 *   200 — 其余情况（成功应用结果，或者订单已经是终态的幂等重放），回复驱动要求
 *         的纯文本（目前恒为 `ok`，见 `SupplierCallbackService` 类注释）。
 */
#[Controller(prefix: '/notify')]
class NotifySupplierController extends AbstractController
{
    /**
     * 商品变更通知的成功回复。卡速售文档没有单独规定，沿用订单回调的 `ok`。
     */
    private const PRODUCT_NOTIFICATION_REPLY = 'ok';

    #[Inject]
    protected SupplierCallbackService $callbackService;

    #[Inject]
    protected ProductSyncService $productSyncService;

    #[Inject]
    protected SupplierNotifyAddressService $notifyAddressService;

    #[Inject]
    protected MovieCallbackService $movieCallbackService;

    #[Inject]
    protected DisputeSupplierAftersaleService $aftersaleService;

    /**
     * 地址里的令牌不对跟供应商不存在一样返回 404（见 SupplierNotifyAddressService::resolve()）。
     */
    #[PostMapping(path: '{code}/{token}')]
    public function handle(string $code, string $token): PsrResponseInterface
    {
        try {
            $this->notifyAddressService->resolve($code, $token);
        } catch (SupplierNotFoundException $e) {
            return $this->response->raw($e->getMessage())->withStatus(404);
        }

        $payload = $this->request->all();

        // kasushou 的验签不依赖 HTTP header（见 KasushouDriver::parseCallback()
        // 类注释），这里仍然按 PSR-7 标准把 header 整理成 array<string, string>
        // 传下去（多值 header 用逗号拼接），保留给未来驱动使用。
        $headers = array_map(
            static fn (array $values): string => implode(', ', $values),
            $this->request->getHeaders()
        );

        try {
            $body = $this->callbackService->handle($code, $payload, $headers);
        } catch (CallbackOrderNotFoundException|SupplierNotFoundException $e) {
            return $this->response->raw($e->getMessage())->withStatus(404);
        } catch (InvalidSupplierCallbackSignatureException $e) {
            return $this->response->raw($e->getMessage())->withStatus(403);
        }

        return $this->response->raw($body)->withStatus(200);
    }

    /**
     * 芒果影院更新回调（mango.md：只发一次、不补发、接入方无需响应）：`POST /notify/{code}/{token}/cinema`。
     * 同步失败只记日志，照样回 200，漏掉的由每日全量同步兜底。
     */
    #[PostMapping(path: '{code}/{token}/cinema')]
    public function cinemaChanged(string $code, string $token): PsrResponseInterface
    {
        try {
            $supplier = $this->notifyAddressService->resolve($code, $token);
        } catch (SupplierNotFoundException $e) {
            return $this->response->raw($e->getMessage())->withStatus(404);
        }
        if ($supplier->driver === 'mango') {
            $this->movieCallbackService->handleCinemaUpdate($supplier, $this->request->all());
        }

        return $this->response->raw(self::PRODUCT_NOTIFICATION_REPLY)->withStatus(200);
    }

    /**
     * 供应商商品变更通知（kasushou.md 第 4 节"商品同步"）：`POST /notify/{code}/{token}/goods`，
     * 需要在供应商后台把商品变更通知地址配成这个。状态码约定同 handle()：供应商不存在
     * 404、验签失败 403，其余（含商品没配映射）200 回 `ok`；查询商品详情失败走全局
     * 异常处理返回 500，供应商视为失败，漏掉的由每日全量校准补上。
     */
    #[PostMapping(path: '{code}/{token}/goods')]
    public function productChanged(string $code, string $token): PsrResponseInterface
    {
        try {
            $this->notifyAddressService->resolve($code, $token);
            $this->productSyncService->handleNotification($code, $this->request->all());
        } catch (SupplierNotFoundException $e) {
            return $this->response->raw($e->getMessage())->withStatus(404);
        } catch (InvalidSupplierCallbackSignatureException $e) {
            return $this->response->raw($e->getMessage())->withStatus(403);
        }

        return $this->response->raw(self::PRODUCT_NOTIFICATION_REPLY)->withStatus(200);
    }

    /**
     * 卡速售售后处理回调（kasushou.md 第 1 节「售后」）：`POST /notify/{code}/{token}/aftersale`，
     * 提交售后时逐单传给卡速售。状态码约定同 handle()：验签失败 403、认不出售后单 404，成功回 `ok`。
     */
    #[PostMapping(path: '{code}/{token}/aftersale')]
    public function aftersale(string $code, string $token): PsrResponseInterface
    {
        try {
            $supplier = $this->notifyAddressService->resolve($code, $token);
            $this->aftersaleService->handleCallback($supplier, $this->request->all());
        } catch (CallbackOrderNotFoundException|SupplierNotFoundException $e) {
            return $this->response->raw($e->getMessage())->withStatus(404);
        } catch (InvalidSupplierCallbackSignatureException $e) {
            return $this->response->raw($e->getMessage())->withStatus(403);
        }

        return $this->response->raw(self::PRODUCT_NOTIFICATION_REPLY)->withStatus(200);
    }
}
