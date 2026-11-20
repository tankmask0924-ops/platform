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
use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\Context\ApplicationContext;
use Hyperf\Guzzle\ClientFactory;
use RuntimeException;

/**
 * 卡速售 2.0 驱动（requirements.md 6.2 统一能力 + kasushou.md），覆盖下单、查询订单、
 * 解析回调、查询余额、撤单、售后，以及商品同步（商品变更通知解析、商品详情、商品列表）。
 *
 * 【接口依据】路径、请求字段、响应字段、状态取值一律以 docs/卡速售2.0API接口文档.md
 * （卡速售提供的接口文档原文）为准，各方法注释写明对应文档里的哪个接口。
 *
 * 【商品同步部分的安全设计：通知只作为触发，绝不信任通知 payload 里的价格/状态/
 * 库存】接口文档「商品变更通知」：签名只覆盖 id 和 time，而通知表单里同时带着
 * status/goods_price/stock_num——这些字段完全不在签名保护范围内：任何能打到这个
 * webhook 地址的人，都可以在 id+time+sign 仍然合法的前提下伪造任意价格/状态/库存。
 * 所以 parseProductChangeNotification() 验签通过后只返回 id（供应商商品编码），
 * 调用方（App\Service\Supplier\ProductSyncService::applyNotification()）必须再调
 * queryProductDetail() 重新查一次权威值——这跟本类 parseCallback()
 * "card_list/express_list 不参与签名，卡密一律以订单详情接口为准"是同一类问题、
 * 同一种解法。每天全量校准的 syncAllProducts() 不同：那是平台主动发起的出站认证
 * 请求，响应可以直接信任。
 *
 * 【撤单、售后要先查出卡速售订单号】撤单（order/close）和售后申请（order/complain）
 * 都只认卡速售自己的订单号 `ordersn`，不认平台传的 external_orderno。调用方手里只有
 * external_orderno（`订单号-尝试序号`），而且超时的异常单平台可能根本没拿到过 ordersn，
 * 所以这两个方法先用订单详情接口按 external_orderno 查出 ordersn 再发请求。
 *
 * 【是否需要一个正式的 DriverInterface？仍然暂不引入，但理由已经从"猜"变成"量过了"】
 * 原先的判断是：只有一个驱动实现，抽接口容易抽错，等第二个驱动落地再说。
 * 2026-09-21 云洋驱动（App\Supplier\Yunyang\YunyangDriver）落地之后量的结果是——
 * 两家真正同形状的只有 `queryBalance(): string` 一个方法：
 * - 下单：卡速售传商品 id + 安全价 + 逐单回调地址，云洋传渠道 id + 一整套寄收件信息，
 *   回调地址还是账户级配置的，没有共同的参数表；
 * - 查询订单：卡速售能用平台自己的 external_orderno 反查，云洋只能用它给的 shopbill 查，
 *   连"拿什么去查"都不是一回事；
 * - 回调：卡速售验签后回查，云洋压根没有签名。
 * 为了 queryBalance() 一个方法建接口，再逼着六个调用方去 instanceof 收窄，
 * 换来的类型正确性还不如现在直白。真正该抽接口的时机是第 6/7/8 节的快递订单流程接上、
 * 出现第二组调用方之后——那时才知道"下单""查询"在业务层被怎么用，接口该长什么样。
 *
 * 【出站 HTTP 的测试方式】跟 App\Job\NotifyMerchantJob 同一个模式：httpClient() 是
 * 一个可被测试用匿名子类覆盖的 protected 方法，默认实现从容器拿
 * Hyperf\Guzzle\ClientFactory 建一个真实 Guzzle 客户端；单测里全部换成 Mockery 双重，
 * 不发真实网络请求。
 */
class KasushouDriver
{
    /** 订单提交接口 */
    private const PATH_ORDER_CREATE = '/api/v1/order/buy';

    /** 订单详情接口 */
    private const PATH_ORDER_QUERY = '/api/v1/order/info';

    /** 用户余额查询 */
    private const PATH_USER_INFO = '/api/v1/user/info';

    /** 订单撤单接口：只认卡速售订单号 ordersn，撤单结果推到 callbackUrl */
    private const PATH_ORDER_CLOSE = '/api/v1/order/close';

    /** 售后申请接口（提交投诉）：只认 ordersn，处理结果推到 url */
    private const PATH_AFTERSALE_APPLY = '/api/v1/order/complain';

    /** 售后处理回调的状态：2=正在处理，3=处理完成，4=终止处理（数字和中文都认） */
    private const AFTERSALE_STATUS_MAP = [
        '2' => 'processing', '正在处理' => 'processing',
        '3' => 'completed', '处理完成' => 'completed',
        '4' => 'terminated', '终止处理' => 'terminated',
    ];

    /** 商品状态：1=销售，2=暂停，3=禁售 */
    private const GOODS_STATUS_MAP = [
        1 => 'active',
        2 => 'paused',
        3 => 'banned',
    ];

    /** 商品详情接口 */
    private const PATH_GOODS_DETAIL = '/api/v1/goods/info';

    /** 商品列表接口，每页最多 100 条（limit 不传默认 100） */
    private const PATH_GOODS_LIST = '/api/v1/goods/list';

    /**
     * 调用日志里的动作名（supplier_call_logs.action）。
     */
    private const ACTIONS = [
        self::PATH_ORDER_CREATE => 'place_order',
        self::PATH_ORDER_QUERY => 'query',
        self::PATH_USER_INFO => 'query_balance',
        self::PATH_ORDER_CLOSE => 'cancel',
        self::PATH_AFTERSALE_APPLY => 'submit_aftersale',
        self::PATH_GOODS_DETAIL => 'goods_detail',
        self::PATH_GOODS_LIST => 'goods_list',
    ];

    private readonly KasushouSigner $signer;

    private readonly KasushouStatusMapper $statusMapper;

    /**
     * `$callRecorder`：每次 HTTP 调用结束后调一次 `fn(string $action, array $request, array $response, int $durationMs)`，
     * 用来记调用日志（见 App\Supplier\SupplierDriverFactory），不传就不记。
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $userId,
        private readonly string $apiKey,
        private readonly ?Closure $callRecorder = null,
    ) {
        $this->signer = new KasushouSigner();
        $this->statusMapper = new KasushouStatusMapper();
    }

    /**
     * 下单（订单提交接口 order/buy）。quantity 一期固定传 1（kasushou.md："话费、卡券固定为 1"），
     * attach 是商品映射配置好的下单模板字段（如 recharge_account），由调用方组装好传进来；
     * 卡密商品不传 attach（文档：卡密商品不用传此参数），空数组也不传——PHP 空数组会编码成
     * `[]`，而 attach 要求是对象。
     *
     * HTTP 200：按返回的订单状态映射（kasushou.md 第 2 节）。
     * HTTP 400：不能直接判定失败，必须用 external_orderno 查一次订单详情：查不到才是
     *           明确失败，查到就按查到的状态处理——这两种情况正好就是 queryOrder()
     *           的返回值本身，所以直接把 queryOrder() 的结果原样返回。
     * HTTP 500 / 网络异常（超时等）/ 响应体解析不出 {code,msg,data} 结构：结果未知，
     *           绝不当成明确失败（文档：下单接口如果请求超时，请调用订单详情接口确认下单结果）。
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
            // 文档里商品 ID 是 int；编码不是纯数字时原样传字符串，让卡速售自己报错
            'id' => ctype_digit($supplierGoodsId) ? (int) $supplierGoodsId : $supplierGoodsId,
            'external_orderno' => $externalOrderNo,
            'safe_price' => $safePrice,
            'quantity' => $quantity,
            'url' => $notifyUrl,
        ];
        if ($attach !== []) {
            $body['attach'] = $attach;
        }

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
     * 查询订单详情（订单详情接口 order/info）。day 恒传 0（文档：默认近 30 天，查全部传 0，
     * 查全部才能保证异常单不会因为跨月漏查）。
     *
     * 响应 data 是订单数组（external_orderno 可以逗号拼多个），这里只查一个，按
     * external_orderno 找出对应那一条。
     * 查询本身返回 400/500/超时：结果未知，继续查询（kasushou.md 第 3 节）。
     * 查询成功但返回空数组：明确失败（对应 placeOrder 400 之后"查不到订单"的场景）。
     * 返回了订单但没有一条是这个 external_orderno：拿不准，结果未知。
     * 查到订单：按 KasushouStatusMapper 映射状态码。
     */
    public function queryOrder(string $externalOrderNo, bool $isCardProduct = false): DriverResult
    {
        $lookup = $this->lookupOrder($externalOrderNo);

        if ($lookup['state'] === 'not_found') {
            return new DriverResult(
                result: UnifiedResult::DefiniteFailure,
                failReason: 'kasushou: order not found for external_orderno=' . $externalOrderNo,
                rawRequest: $lookup['request'],
                rawResponse: $lookup['raw'],
            );
        }

        if ($lookup['state'] !== 'found') {
            return new DriverResult(
                result: UnifiedResult::Unknown,
                failReason: $lookup['reason'],
                rawRequest: $lookup['request'],
                rawResponse: $lookup['raw'],
            );
        }

        return $this->mapOrderData($lookup['entry'], $lookup['request'], $lookup['raw'], $isCardProduct);
    }

    /**
     * 发起撤单（订单撤单接口 order/close，kasushou.md 第 1 节：异常单由客服发起）。
     * 撤单接口只认卡速售订单号，先按 external_orderno 查出 ordersn（见类注释）。
     *
     * 只表示"卡速售受理了撤单申请"，**不是撤单成功**（文档：订单撤单不代表订单退款）：结果通过
     * 撤单结果回调（`$notifyUrl` 作为 callbackUrl，跟下单回调同一个地址，回调里带 external_orderno，
     * 走同一个回调入口回查订单详情）或者之后查询订单详情的状态 4/5 + 退款金额得知，由调用方据此
     * 推进订单，这里不改任何状态。商品不支持撤单、订单已经成功等情况卡速售返回 400 + 文案，原样带回给客服看。
     *
     * @return array{accepted: bool, message: string}
     */
    public function cancelOrder(string $externalOrderNo, ?string $notifyUrl = null): array
    {
        $lookup = $this->lookupOrder($externalOrderNo);
        if ($lookup['state'] === 'not_found') {
            return ['accepted' => false, 'message' => '卡速售查不到这笔订单（' . $externalOrderNo . '）'];
        }
        $ordersn = $lookup['state'] === 'found' ? $this->toStringOrNull($lookup['entry']['ordersn'] ?? null) : null;
        if ($ordersn === null || $ordersn === '') {
            return ['accepted' => false, 'message' => '查不到卡速售订单号，请稍后重试：' . $lookup['reason']];
        }

        $body = ['ordersn' => $ordersn];
        if ($notifyUrl !== null) {
            $body['callbackUrl'] = $notifyUrl;
        }

        $response = $this->sendSigned(self::PATH_ORDER_CLOSE, $body);
        $decoded = json_decode((string) ($response['raw']['body'] ?? ''), true);
        $message = is_array($decoded) ? (string) ($decoded['msg'] ?? '') : '';

        if ($response['httpStatus'] === null) {
            return ['accepted' => false, 'message' => 'kasushou: network error or timeout'];
        }

        return [
            'accepted' => $response['httpStatus'] === 200,
            'message' => $message !== '' ? $message : ('http ' . $response['httpStatus']),
        ];
    }

    /**
     * 提交售后申请（售后申请接口 order/complain，requirements.md 7.7「供应商支持售后接口时，客服可直接
     * 通过接口提交给供应商」）。售后接口只认卡速售订单号，先按 external_orderno 查出 ordersn（见类注释）；
     * 截图链接逗号拼成一个 screenshot 字符串，处理结果推到 `$notifyUrl`。
     *
     * 卡速售不返回售后单号，售后处理回调里也只带 ordersn，所以 `aftersale_no` 返回的就是 ordersn——
     * 一笔卡速售订单同时只有一个售后，回调按它找回争议（见 parseAftersaleCallback()）。
     *
     * `unknown = true`：网络失败或响应解析不了，卡速售可能已经受理，调用方要提示客服先去卡速售后台确认，
     * 不能当"提交失败"马上重提。查 ordersn 时结果未知也算 unknown（还没提交，但没法确定订单在不在）。
     *
     * @param list<string> $images 截图链接
     * @return array{accepted: bool, unknown: bool, aftersale_no: null|string, message: string}
     */
    public function submitAftersale(string $externalOrderNo, string $content, array $images, string $notifyUrl): array
    {
        $lookup = $this->lookupOrder($externalOrderNo);
        if ($lookup['state'] === 'not_found') {
            return ['accepted' => false, 'unknown' => false, 'aftersale_no' => null, 'message' => '卡速售查不到这笔订单（' . $externalOrderNo . '）'];
        }
        $ordersn = $lookup['state'] === 'found' ? $this->toStringOrNull($lookup['entry']['ordersn'] ?? null) : null;
        if ($ordersn === null || $ordersn === '') {
            return ['accepted' => false, 'unknown' => true, 'aftersale_no' => null, 'message' => '查不到卡速售订单号：' . $lookup['reason']];
        }

        $body = ['ordersn' => $ordersn, 'content' => $content, 'url' => $notifyUrl];
        if ($images !== []) {
            $body['screenshot'] = implode(',', $images);
        }

        $response = $this->sendSigned(self::PATH_AFTERSALE_APPLY, $body);
        $decoded = json_decode((string) ($response['raw']['body'] ?? ''), true);
        if ($response['httpStatus'] === null || ! is_array($decoded)) {
            return ['accepted' => false, 'unknown' => true, 'aftersale_no' => null, 'message' => 'kasushou: network error, timeout or unparseable response'];
        }

        $code = $decoded['code'] ?? null;
        $message = (string) ($decoded['msg'] ?? '');
        if ((string) $code === '500') {
            // 文档：500 是未知错误，不能当拒绝
            return ['accepted' => false, 'unknown' => true, 'aftersale_no' => null, 'message' => $message !== '' ? $message : 'kasushou: unknown error'];
        }
        if ($response['httpStatus'] !== 200 || (string) $code !== '200') {
            return ['accepted' => false, 'unknown' => false, 'aftersale_no' => null, 'message' => $message !== '' ? $message : ('http ' . $response['httpStatus'])];
        }

        return ['accepted' => true, 'unknown' => false, 'aftersale_no' => $ordersn, 'message' => $message];
    }

    /**
     * 售后处理回调。签名：除 sign 外全部参数排序编码，sha1(time + JSON + apikey)，跟订单回调同一套
     * （KasushouSigner::verifyCallback()），验签失败返回 null，调用方回 403。回调字段：ordersn、
     * status（2=正在处理，3=处理完成，4=终止处理）、content（处理内容）、screenshot、time、sign。
     * 签名通过的状态和说明可以信；但"处理完成"不等于"确认未到账"——结论由客服看说明后在争议里驳回
     * 或确认，这里只返回卡速售说了什么。`aftersale_no` 是 ordersn（见 submitAftersale()）。
     *
     * @param array<string, mixed> $payload
     * @return null|array{aftersale_no: null|string, external_orderno: null|string, status: null|string, reply: null|string}
     */
    public function parseAftersaleCallback(array $payload): ?array
    {
        if (! $this->signer->verifyCallback($payload, $this->apiKey)) {
            return null;
        }

        $string = static fn (mixed $value): ?string => (is_string($value) || is_int($value)) && (string) $value !== '' ? (string) $value : null;
        $status = $string($payload['status'] ?? null);

        return [
            'aftersale_no' => $string($payload['ordersn'] ?? null),
            'external_orderno' => $string($payload['external_orderno'] ?? null),
            'status' => $status === null ? null : (self::AFTERSALE_STATUS_MAP[$status] ?? null),
            'reply' => $string($payload['content'] ?? null),
        ];
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

    /**
     * 解析商品变更通知。只验证 id+time+sign 签名，验签通过后只返回 id（供应商商品
     * 编码，对应 supplier_products.supplier_product_code），绝不从 payload 里
     * 读取/信任任何价格/状态/库存字段——即便通知表单里带了这些字段。原因见本类
     * 类注释里的安全设计说明。
     *
     * 验签失败：返回 null，调用方不能把 null 当成"没有变化"，也不能凭 null 做
     * 任何库存/价格更新——就是单纯的"这条通知不可信，忽略"。
     *
     * @param array<string, mixed> $payload
     */
    public function parseProductChangeNotification(array $payload): ?string
    {
        if (! $this->signer->verifyProductChangeNotification($payload, $this->apiKey)) {
            return null;
        }

        $id = $payload['id'] ?? null;
        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        return (string) $id;
    }

    /**
     * 查单个供应商商品的权威详情（商品详情接口 goods/info，请求字段 `id`，文档里是 int）。
     *
     * HTTP 失败/响应解析不出结构/关键字段缺失：一律抛 RuntimeException，不返回
     * null——跟 queryBalance() 是同一个"这个方法要么给权威值、要么让调用方知道
     * 失败了"的处理原则，不做"结果未知"这种订单类方法才有的三态语义（这不是订单
     * 操作，没有 UnifiedResult 概念可用）。
     *
     * @return array{supplier_product_code: string, cost_price: string, status: string, stock: null|int, name: null|string, face_value: null|string}
     */
    public function queryProductDetail(string $supplierProductCode): array
    {
        $body = ['id' => ctype_digit($supplierProductCode) ? (int) $supplierProductCode : $supplierProductCode];
        $response = $this->sendSigned(self::PATH_GOODS_DETAIL, $body);

        if ($response['httpStatus'] !== 200 || $response['data'] === null) {
            throw new RuntimeException(sprintf(
                'KasushouDriver::queryProductDetail: %s',
                $this->describeNonSuccessHttp($response['httpStatus'])
            ));
        }

        $entry = $this->parseGoodsEntry($response['data']);
        if ($entry === null) {
            throw new RuntimeException('KasushouDriver::queryProductDetail: response missing id/goods_price or unparseable.');
        }

        return $entry;
    }

    /**
     * 查商品列表的一页（商品列表接口 goods/list，`page` + `limit`，每页最多 100）。
     * 这里只做单次 HTTP 调用，翻页循环、"翻到空页就停"留给调用方
     * （App\Service\Supplier\ProductSyncService::syncSupplier()）。
     *
     * HTTP 失败/响应解析不出结构：抛 RuntimeException，跟 queryProductDetail() 一致。
     * 响应里 `list` 缺失、非数组，或者某一条商品条目缺 id/goods_price：不当成
     * 错误，跳过那一条（或整页返回空），因为这是"取一页数据"的语义，不是"取一个
     * 确定存在的商品详情"，容忍个别脏数据不影响其它条目正常同步。
     *
     * @return array<int, array{supplier_product_code: string, cost_price: string, status: string, stock: null|int, name: null|string, face_value: null|string}>
     */
    public function syncAllProducts(int $page = 1, int $pageSize = 100): array
    {
        $body = ['limit' => $pageSize, 'page' => $page];
        $response = $this->sendSigned(self::PATH_GOODS_LIST, $body);

        if ($response['httpStatus'] !== 200 || $response['data'] === null) {
            throw new RuntimeException(sprintf(
                'KasushouDriver::syncAllProducts: %s',
                $this->describeNonSuccessHttp($response['httpStatus'])
            ));
        }

        $listRaw = $response['data']['list'] ?? null;
        if (! is_array($listRaw)) {
            return [];
        }

        $entries = [];
        foreach ($listRaw as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $entry = $this->parseGoodsEntry($raw);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    protected function httpClient(): ClientInterface
    {
        return ApplicationContext::getContainer()->get(ClientFactory::class)->create();
    }

    /**
     * 统一发起一次带签名的 POST 请求（文档「签名规范」），返回 [httpStatus, data, raw]。
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
        $startedAt = microtime(true);

        try {
            $httpResponse = $this->httpClient()->request('POST', $this->baseUrl . $path, [
                'headers' => [
                    'Sign' => $sign,
                    'Timestamp' => $timestamp,
                    'UserId' => $this->userId,
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                // 原样发送签名用的那个字符串（排序、不转义斜杠和中文），不交给 Guzzle 再编码一次
                'body' => $this->signer->encodeRequestBody($body),
                'timeout' => 10,
                'connect_timeout' => 5,
                // 跟 NotifyMerchantJob 一样：关掉 Guzzle 对 4xx/5xx 自动抛异常，
                // 400/500 要走业务分支判断，异常分支只留给真正的连接/超时失败。
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            $this->recordCall($path, $body, ['exception' => $e->getMessage()], $startedAt);

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

        $this->recordCall($path, $body, ['http_status' => $httpStatus, 'body' => is_array($decoded) ? $decoded : $rawBody], $startedAt);

        return [
            'httpStatus' => $httpStatus,
            'data' => $data,
            'raw' => ['http_status' => $httpStatus, 'body' => $rawBody],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $response
     */
    private function recordCall(string $path, array $body, array $response, float $startedAt): void
    {
        if ($this->callRecorder === null) {
            return;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        ($this->callRecorder)(self::ACTIONS[$path] ?? $path, ['path' => $path, 'body' => $body], $response, $durationMs);
    }

    /**
     * 按 external_orderno 查订单详情，找出对应的那一条（订单详情接口 data 是数组）。
     * state：found（entry 是那条订单）/ not_found（返回空数组）/ unknown（请求失败、
     * 结构解析不了、或者返回的订单里没有这个 external_orderno），reason 说明 unknown 的原因。
     *
     * @return array{state: string, entry: null|array<string, mixed>, reason: string, request: array<string, mixed>, raw: array<string, mixed>}
     */
    private function lookupOrder(string $externalOrderNo): array
    {
        $body = ['day' => 0, 'external_orderno' => $externalOrderNo];
        $response = $this->sendSigned(self::PATH_ORDER_QUERY, $body);
        $result = ['state' => 'unknown', 'entry' => null, 'reason' => '', 'request' => $body, 'raw' => $response['raw']];

        if ($response['httpStatus'] !== 200 || $response['data'] === null) {
            return ['reason' => $this->describeNonSuccessHttp($response['httpStatus'])] + $result;
        }

        if ($response['data'] === []) {
            return ['state' => 'not_found'] + $result;
        }

        foreach ($response['data'] as $entry) {
            if (is_array($entry) && $this->toStringOrNull($entry['external_orderno'] ?? null) === $externalOrderNo) {
                return ['state' => 'found', 'entry' => $entry] + $result;
            }
        }

        return ['reason' => 'kasushou: order list does not contain external_orderno=' . $externalOrderNo] + $result;
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
        // 文档里是 int；数字字符串（如 "3"、"-1"）也认，其它一律拿不准
        if (is_string($status) && preg_match('/^-?\d+$/', $status) === 1) {
            $status = (int) $status;
        }
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
            supplierBalanceInsufficient: $status === KasushouStatusMapper::STATUS_UNPAID,
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

    /**
     * 把商品详情/列表条目的原始数组（id、goods_name、face_value、goods_price、status、stock_num）
     * 解析成统一的小结构。id/goods_price 缺失或类型
     * 不对：解析失败，返回 null（调用方决定是抛异常还是跳过这一条，见
     * queryProductDetail()/syncAllProducts() 的不同处理）。
     *
     * @param array<string, mixed> $raw
     * @return null|array{supplier_product_code: string, cost_price: string, status: string, stock: null|int, name: null|string, face_value: null|string}
     */
    private function parseGoodsEntry(array $raw): ?array
    {
        $id = $raw['id'] ?? null;
        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        $costPrice = $this->toMoneyString($raw['goods_price'] ?? null);
        if ($costPrice === null) {
            return null;
        }

        // 名称、面值只用来在后台选供应商商品时辨认，缺了不影响同步
        $name = $raw['goods_name'] ?? null;
        $faceValue = $raw['face_value'] ?? null;

        return [
            'supplier_product_code' => (string) $id,
            'cost_price' => $costPrice,
            'status' => $this->mapGoodsStatus($raw['status'] ?? null),
            'stock' => $this->toNullableInt($raw['stock_num'] ?? null),
            'name' => is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 128) : null,
            'face_value' => is_numeric($faceValue) && bccomp((string) $faceValue, '0', 2) >= 0 ? bcadd((string) $faceValue, '0', 2) : null,
        ];
    }

    /**
     * 商品状态（1=销售，2=暂停，3=禁售）映射成平台的 active/paused/banned。
     *
     * 缺字段、类型不对、或者不认识的取值：一律按 paused（暂停）处理，不猜 active——
     * 猜错成"暂停"顶多是路由跳过这个供应商商品，猜错成"在售"却可能把已经下架/
     * 缺货的商品继续卖给商户，两种错误代价不对称，跟 KasushouStatusMapper 里
     * "拿不准一律 Unknown，不猜"是同一个原则的延伸。
     */
    private function mapGoodsStatus(mixed $raw): string
    {
        if (is_string($raw) && ctype_digit($raw)) {
            $raw = (int) $raw;
        }

        return is_int($raw) ? (self::GOODS_STATUS_MAP[$raw] ?? 'paused') : 'paused';
    }

    private function toNullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
