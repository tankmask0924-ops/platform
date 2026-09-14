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

namespace App\Supplier\Kasushou;

use App\Supplier\DriverResult;
use App\Supplier\UnifiedResult;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\Context\ApplicationContext;
use Hyperf\Guzzle\ClientFactory;
use RuntimeException;

/**
 * 卡速售 2.0 驱动（requirements.md 6.2 统一能力 + kasushou.md 全文），覆盖本次任务
 * 范围内的下单/查询订单/解析回调/查询余额，商品同步、撤单、售后是 docs/modules.md
 * 里单独的 ⬜ 行，本类不实现。
 *
 * 【是否需要一个正式的 DriverInterface？本次的判断：暂不引入，理由写在这】
 * requirements.md 6.2 列的统一能力（下单/查询订单/解析回调/查询余额等）确实是
 * "共享契约"的候选，但目前只有卡速售这一个驱动实现，云洋、芒果都还没开工
 * （docs/modules.md 第 3、4 节整行 ⬜）。在只有一个实现的情况下抽象接口容易抽错——
 * 比如云洋"没有防重复单号，下单超时不重试"、芒果"锁座/确认出票分两步"，这些跟
 * 卡速售的形状差异多大、接口要不要收窄成"下单"一个方法还是拆成多个，现在都是猜。
 * 所以这里先不建 App\Supplier\DriverInterface，等云洋或芒果任一个驱动落地、能真正
 * 验证"这几个方法签名对两家供应商都合适"之后再抽取，避免过早抽象。
 *
 * 【出站 HTTP 的测试方式】跟 App\Job\NotifyMerchantJob 同一个模式：httpClient() 是
 * 一个可被测试用匿名子类覆盖的 protected 方法，默认实现从容器拿
 * Hyperf\Guzzle\ClientFactory 建一个真实 Guzzle 客户端；单测里全部换成 Mockery 双重，
 * 不发真实网络请求（卡速售没有测试环境域名/账号，kasushou.md 第 5 节原文
 * "域名等信息待实际配置供应商时录入"）。
 *
 * 【请求字段名的免责声明】kasushou.md 目前只是"核对结果"文档，没有给出下单/查询
 * 接口的完整请求体 JSON schema 示例（只提到 external_orderno、safe_price、
 * quantity、attach、url、day 这几个字段名是文档原文出现过的；商品 id 字段名、
 * 订单详情响应里 status/ordersn/has_back_money/total_price/card_list 等字段的
 * 确切拼写，文档没有给出可核对的报文样例）。下面的字段名是按文档行文最合理的猜测
 * 拼出来的，等实际拿到卡速售接口文档/联调时如果字段名对不上，只需要改
 * placeOrder() 里拼请求体的部分和 mapOrderData() 这两处，不影响外部调用方。
 */
class KasushouDriver
{
    private const PATH_ORDER_CREATE = '/api/v1/order/create';

    private const PATH_ORDER_QUERY = '/api/v1/order/query';

    private const PATH_USER_INFO = '/api/v1/user/info';

    private readonly KasushouSigner $signer;

    private readonly KasushouStatusMapper $statusMapper;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $userId,
        private readonly string $apiKey,
    ) {
        $this->signer = new KasushouSigner();
        $this->statusMapper = new KasushouStatusMapper();
    }

    /**
     * 下单。quantity 一期固定传 1（kasushou.md："话费、卡券固定为 1"），
     * attach 是商品映射配置好的下单模板字段（如 recharge_account），由调用方组装好
     * 传进来（商品映射不在本次任务范围内，见任务说明"OUT of scope"）。
     *
     * HTTP 200：按返回的订单状态映射（kasushou.md 第 2 节）。
     * HTTP 400：不能直接判定失败，必须用 external_orderno 查一次订单详情：查不到才是
     *           明确失败，查到就按查到的状态处理——这两种情况正好就是 queryOrder()
     *           的返回值本身，所以直接把 queryOrder() 的结果原样返回。
     * HTTP 500 / 网络异常（超时等）/ 响应体解析不出 {code,msg,data} 结构：结果未知，
     *           绝不当成明确失败。
     *
     * @param array<string, mixed> $attach
     */
    public function placeOrder(
        string $externalOrderNo,
        string $supplierGoodsId,
        string $safePrice,
        string $notifyUrl,
        array $attach = [],
        int $quantity = 1,
        bool $isCardProduct = false
    ): DriverResult {
        $body = [
            'external_orderno' => $externalOrderNo,
            'goods_id' => $supplierGoodsId,
            'safe_price' => $safePrice,
            'quantity' => $quantity,
            'attach' => $attach,
            'url' => $notifyUrl,
        ];

        $response = $this->sendSigned(self::PATH_ORDER_CREATE, $body);

        if ($response['httpStatus'] === 400) {
            // 卡速售的 400 只有一段自由文本 msg，不能靠文案判断是否已扣费，
            // 必须回查订单详情——查不到=明确失败，查到=按状态处理，两种结果
            // queryOrder() 本身就完整覆盖了。
            return $this->queryOrder($externalOrderNo, $isCardProduct);
        }

        if ($response['httpStatus'] !== 200 || $response['data'] === null) {
            // 500、超时（httpStatus 为 null）、响应解析不出结构，或者出现文档
            // 三种返回码之外的情况：一律结果未知，绝不猜明确失败。
            return new DriverResult(
                result: UnifiedResult::Unknown,
                failReason: $this->describeNonSuccessHttp($response['httpStatus']),
                rawRequest: $body,
                rawResponse: $response['raw'],
            );
        }

        return $this->mapOrderData($response['data'], $body, $response['raw'], $isCardProduct);
    }

    /**
     * 查询订单详情。day 恒传 0（文档："默认只查近 30 天，day=0 查全部"，查全部
     * 才能保证异常单不会因为跨月漏查）。
     *
     * 查询本身返回 400/500/超时：结果未知，继续查询（kasushou.md 第 3 节）。
     * 查询成功但找不到匹配的订单：明确失败（对应 placeOrder 400 之后"查不到订单"
     * 的场景，也是 queryOrder 自身作为独立能力时的合理语义——没有这笔订单）。
     * 查到订单：按 KasushouStatusMapper 映射状态码。
     */
    public function queryOrder(string $externalOrderNo, bool $isCardProduct = false): DriverResult
    {
        $body = [
            'external_orderno' => $externalOrderNo,
            'day' => 0,
        ];

        $response = $this->sendSigned(self::PATH_ORDER_QUERY, $body);

        if ($response['httpStatus'] !== 200 || $response['data'] === null) {
            return new DriverResult(
                result: UnifiedResult::Unknown,
                failReason: $this->describeNonSuccessHttp($response['httpStatus']),
                rawRequest: $body,
                rawResponse: $response['raw'],
            );
        }

        if ($response['data'] === []) {
            return new DriverResult(
                result: UnifiedResult::DefiniteFailure,
                failReason: 'kasushou: order not found for external_orderno=' . $externalOrderNo,
                rawRequest: $body,
                rawResponse: $response['raw'],
            );
        }

        return $this->mapOrderData($response['data'], $body, $response['raw'], $isCardProduct);
    }

    /**
     * 查询平台在该卡速售站点的预存款余额。
     */
    public function queryBalance(): string
    {
        $response = $this->sendSigned(self::PATH_USER_INFO, []);

        $balance = $response['data']['balance'] ?? null;
        if (! is_string($balance) && ! is_int($balance) && ! is_float($balance)) {
            throw new RuntimeException('KasushouDriver::queryBalance: balance field missing or unparseable in response.');
        }

        return (string) $balance;
    }

    /**
     * 解析回调。回调只作为"触发"，不作为权威结果来源——kasushou.md 明确写了
     * "回调只作为触发...再做扣款、解冻"、"卡密一律以订单详情接口为准"，
     * 因为回调的 card_list/express_list 不参与签名，理论上可能被篡改。
     * 所以这里验签通过之后，不直接信回调 payload 里的状态/卡密，而是拿
     * external_orderno 再调一次 queryOrder() 得到权威结果——这是一个跟其它供应商
     * 驱动可能不同的设计决定，特意写在这里：以后接云洋/芒果时不要想当然照抄。
     *
     * 验签失败：返回 null，调用方不能把它当权威结果处理，也不能回复供应商 "ok"
     * （回复逻辑本身不在本次任务范围）。
     *
     * $isCardProduct 由调用方传入（同 queryOrder，商品类型不是驱动职责范围）；
     * 默认 false——如果调用方在收到回调时还不知道这笔订单是不是卡密商品，
     * 用默认值有可能在卡密还没到位时就把状态 3 误判成 Success，这是已知限制，
     * 接入路由/订单服务层时应该总是显式传入正确的 isCardProduct，而不是依赖默认值。
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers 保留供未来驱动使用；卡速售的签名字段（sign/time）
     *                                       都在回调 payload 里，不依赖 header，这里不用它
     */
    public function parseCallback(array $payload, array $headers, bool $isCardProduct = false): ?DriverResult
    {
        if (! $this->signer->verifyCallback($payload, $this->apiKey)) {
            return null;
        }

        $externalOrderNo = $payload['external_orderno'] ?? null;
        if (! is_string($externalOrderNo) || $externalOrderNo === '') {
            return null;
        }

        return $this->queryOrder($externalOrderNo, $isCardProduct);
    }

    protected function httpClient(): ClientInterface
    {
        return ApplicationContext::getContainer()->get(ClientFactory::class)->create();
    }

    /**
     * 统一发起一次带签名的 POST 请求，返回 [httpStatus, data, raw]。
     * httpStatus 为 null 表示网络异常/超时（连请求都没送达或没收到响应）。
     * data 为 null 表示响应体不是可解析的 {code,msg,data} 结构（data 键不是数组）。
     *
     * @param array<string, mixed> $body
     * @return array{httpStatus: null|int, data: null|array<string, mixed>, raw: array<string, mixed>}
     */
    private function sendSigned(string $path, array $body): array
    {
        $timestamp = $this->signer->timestamp();
        $sign = $this->signer->signRequest($body, $this->apiKey, $timestamp);

        try {
            $httpResponse = $this->httpClient()->request('POST', $this->baseUrl . $path, [
                'headers' => [
                    'Sign' => $sign,
                    'Timestamp' => $timestamp,
                    'UserId' => $this->userId,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => 10,
                'connect_timeout' => 5,
                // 跟 NotifyMerchantJob 一样：关掉 Guzzle 对 4xx/5xx 自动抛异常，
                // 400/500 要走业务分支判断，异常分支只留给真正的连接/超时失败。
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            return [
                'httpStatus' => null,
                'data' => null,
                'raw' => ['request' => $body, 'exception' => $e->getMessage()],
            ];
        }

        $httpStatus = $httpResponse->getStatusCode();
        $rawBody = (string) $httpResponse->getBody();
        $decoded = json_decode($rawBody, true);

        $data = null;
        if (is_array($decoded) && is_array($decoded['data'] ?? null)) {
            $data = $decoded['data'];
        }

        return [
            'httpStatus' => $httpStatus,
            'data' => $data,
            'raw' => ['http_status' => $httpStatus, 'body' => $rawBody],
        ];
    }

    /**
     * 把订单详情/下单响应里的 data 部分（已假定是 {status, ordersn, has_back_money,
     * total_price, card_list, ...} 结构）转成 DriverResult。下单响应和查询响应
     * 共用同一套状态映射表（kasushou.md 第 2 节没有区分"下单响应"和"查询响应"
     * 两张表，两者返回的是同一套订单状态码）。
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rawRequest
     * @param array<string, mixed> $rawResponse
     */
    private function mapOrderData(array $data, array $rawRequest, array $rawResponse, bool $isCardProduct): DriverResult
    {
        $status = $data['status'] ?? null;
        if (! is_int($status)) {
            // 状态字段本身缺失或类型不对：解析不出权威状态，拿不准，按结果未知处理
            return new DriverResult(
                result: UnifiedResult::Unknown,
                failReason: 'kasushou: order status field missing or unparseable',
                rawRequest: $rawRequest,
                rawResponse: $rawResponse,
            );
        }

        $cardListRaw = $data['card_list'] ?? null;
        $cardList = is_array($cardListRaw) ? $cardListRaw : null;
        $hasBackMoney = $this->toMoneyString($data['has_back_money'] ?? null);
        $totalPrice = $this->toMoneyString($data['total_price'] ?? null);

        $unified = $this->statusMapper->map($status, $cardList, $isCardProduct, $hasBackMoney, $totalPrice);

        return new DriverResult(
            result: $unified,
            supplierOrderNo: $this->toStringOrNull($data['ordersn'] ?? null),
            failReason: $unified === UnifiedResult::DefiniteFailure
                ? ('kasushou: order status ' . $status)
                : null,
            actualCost: $totalPrice,
            refundAmount: $hasBackMoney,
            // 卡速售不做电影票/快递，没有供应商返佣这个概念
            supplierRebate: null,
            rawRequest: $rawRequest,
            rawResponse: $rawResponse,
            cardList: $cardList,
        );
    }

    private function describeNonSuccessHttp(?int $httpStatus): string
    {
        return $httpStatus === null
            ? 'kasushou: network error or timeout'
            : sprintf('kasushou: unexpected http status %d or unparseable response', $httpStatus);
    }

    private function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function toMoneyString(mixed $value): ?string
    {
        return $this->toStringOrNull($value);
    }
}
