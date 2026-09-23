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

namespace App\Supplier\Yunyang;

use App\Supplier\DriverResult;
use App\Supplier\UnifiedResult;
use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\Context\ApplicationContext;
use Hyperf\Guzzle\ClientFactory;
use InvalidArgumentException;
use RuntimeException;

/**
 * 云洋国内快递驱动（yunyang.md、requirements.md 7.2），平台第二个供应商驱动。
 * 只负责"跟云洋说话"：签名、发请求、把响应翻译成 `App\Supplier\DriverResult`。
 * 冻结/扣款、费用调整、路由切换都不在这里。
 *
 * 跟卡速售（`App\Supplier\Kasushou\KasushouDriver`）几个关键差异，接快递业务流程时
 * 不要照抄话费那一套：
 *
 * 1. **统一入口 + serviceCode**：所有能力都 POST 到 `/api/wuliu/openService`，靠
 *    `serviceCode` 区分，不是一个能力一个路径。
 * 2. **成功码不统一**：下单、取消、查询、检测渠道成功码是 `"1"`，余额这类账户接口是
 *    `"200"`（yunyang.md 第 1 节"返回格式"）。每个方法调 sendSigned() 时显式传自己的
 *    成功码，不做"哪个都算成功"的兜底——那样会把失败当成功。
 * 3. **只允许 https**：签名不覆盖请求内容（`content` 不参与签名），传输层是唯一能防
 *    篡改的地方，所以构造时就拒绝非 https 的接口地址（yunyang.md 第 3 节）。
 * 4. **下单没有防重复单号**：云洋没有 external_orderno 这种幂等参数，重复请求就是重复
 *    下单、重复扣钱。所以下单超时/无法解析时返回"结果未知"，调用方**绝不能重试**
 *    （yunyang.md 第 3 节）。平台订单号放在 `extendField1` 里传出去，回调会原样带回来，
 *    用来把找不到 shopbill 的订单认回来。
 * 5. **查询按云洋的单号**：卡速售能用平台自己的 external_orderno 反查，云洋只能按运单号
 *    `waybill` 或商家单号 `shopbill` 查（`shopbill` 由云洋在下单响应里给出，平台存进
 *    `orders.supplier_order_no`）。所以 queryOrder() 收的是供应商单号，不是平台单号——
 *    这也是目前**没有把两个驱动抽成公共 DriverInterface** 的原因：除了 queryBalance()，
 *    两家在"用什么单号查、下单要传什么"上根本不是同一个形状，硬套一个接口只会逼出一堆
 *    用不上的参数。等第 6/7/8 节的快递订单流程真正接上、有了第二组调用方之后再抽，
 *    那时才知道接口该长什么样。
 * 6. **回调没有签名**：parseCallback() 只把回调当触发信号，拿里面的单号重新调订单详情
 *    （带签名的请求）拿权威数据，绝不相信回调里的状态和运费（yunyang.md 第 3 节：
 *    伪造"已取消"骗解冻、伪造低运费）。这跟卡速售"回调只作为触发"是同一个模式，
 *    区别是卡速售至少还能验签，这里连验签都没有。
 * 7. **回调地址是账户级的**：在云洋个人中心配一个地址（平台配成 `/notify/{供应商编码}?token=...`），
 *    不像卡速售那样下单时逐单传 url，所以 placeOrder() 没有 notifyUrl 参数。
 *
 * 【serviceCode 字符串和响应字段名是推断】文档给了能力清单和字段含义，但没有给出可核对的
 * 报文样例（`serviceCode` 的确切取值、`result` 里每个字段的确切拼写）。下面 SERVICE_* 常量
 * 和解析用的字段名是按文档行文拼出来的最合理猜测，全部集中在常量区和几个 parse 方法里，
 * 联调时对不上只改这几处，不影响调用方。这跟 KasushouDriver 类注释里的处理方式一致。
 */
class YunyangDriver
{
    /**
     * 统一入口，所有能力都走这个路径，靠 serviceCode 区分（yunyang.md 第 1 节）。
     */
    private const PATH_OPEN_SERVICE = '/api/wuliu/openService';

    /** 检测可用渠道（查价），见类注释里的推断说明 */
    private const SERVICE_CHECK_CHANNEL = 'checkChannel';

    private const SERVICE_CREATE_ORDER = 'createOrder';

    private const SERVICE_ORDER_DETAIL = 'orderDetail';

    private const SERVICE_CANCEL_ORDER = 'cancelOrder';

    private const SERVICE_TRACE = 'queryTrace';

    private const SERVICE_BALANCE = 'queryBalance';

    /**
     * 调用日志里的动作名（`supplier_call_logs.action`），跟卡速售用同一套词，
     * 后台"调用日志"页面筛选时两家供应商的同类调用能对上。
     */
    private const ACTIONS = [
        self::SERVICE_CHECK_CHANNEL => 'check_channel',
        self::SERVICE_CREATE_ORDER => 'place_order',
        self::SERVICE_ORDER_DETAIL => 'query',
        self::SERVICE_CANCEL_ORDER => 'cancel',
        self::SERVICE_TRACE => 'query_trace',
        self::SERVICE_BALANCE => 'query_balance',
    ];

    /** 下单、取消、查询、检测渠道的成功码 */
    private const CODE_OK_ORDER = '1';

    /** 余额这类账户接口的成功码 */
    private const CODE_OK_ACCOUNT = '200';

    /**
     * 渠道标识，固定传"智能"：得物、重货渠道暂不开放（yunyang.md 第 4 节）。
     */
    private const CHANNEL_TAG = '智能';

    private readonly YunyangSigner $signer;

    private readonly YunyangStatusMapper $statusMapper;

    /**
     * `$callRecorder`：每次 HTTP 调用结束后调一次
     * `fn(string $action, array $request, array $response, int $durationMs)`，用来记调用日志
     * （见 App\Supplier\SupplierDriverFactory），不传就不记。
     *
     * @throws InvalidArgumentException 接口地址不是 https——签名不覆盖请求内容，
     *                                  明文 http 等于谁都能改收件地址和重量
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $appId,
        private readonly string $secretKey,
        private readonly ?Closure $callRecorder = null,
    ) {
        if (stripos($this->baseUrl, 'https://') !== 0) {
            throw new InvalidArgumentException('YunyangDriver: base_url must be https (yunyang.md 第 3 节：请求内容不参与签名，只能靠 HTTPS 防篡改).');
        }
        $this->signer = new YunyangSigner();
        $this->statusMapper = new YunyangStatusMapper();
    }

    /**
     * 查价：按寄收件地址、重量、体积检测可用渠道，返回多个渠道及各自费用
     * （yunyang.md 第 1 节"查价"）。没有报价单号和有效期，**下单前要重新查一次**。
     *
     * `$content` 是寄收件地址、重量、体积等原样透传给云洋的请求内容（字段名以云洋文档
     * 为准，驱动不替调用方拼业务字段）；`channelTag` 由驱动固定填"智能"。
     *
     * 返回的每个渠道都按 parseChannel() 归一化。接口失败（code 不是 "1"）或响应解析不出
     * 渠道列表时返回空数组——查价查不到渠道和"没有可用渠道"对调用方是同一件事：这单发不了。
     *
     * @param array<string, mixed> $content
     * @return list<array{channel_id: null|string, channel_name: null|string, freight: null|string,
     *     freight_insured: null|string, freight_haocai: null|string, total_freight: null|string,
     *     official_price: null|string, billing_rule: null|string, allow_insured: bool,
     *     appointment_times: list<string>}>
     */
    public function checkChannels(array $content): array
    {
        $response = $this->send(self::SERVICE_CHECK_CHANNEL, $content + ['channelTag' => self::CHANNEL_TAG], self::CODE_OK_ORDER);
        if (! $response['ok']) {
            return [];
        }

        $list = $response['result'];
        if (! is_array($list)) {
            return [];
        }
        // 有的接口把列表包一层（{"list": [...]}），有的直接返回数组，两种都兜住
        if (isset($list['list']) && is_array($list['list'])) {
            $list = $list['list'];
        }

        $channels = [];
        foreach ($list as $raw) {
            if (is_array($raw)) {
                $channels[] = $this->parseChannel($raw);
            }
        }

        return $channels;
    }

    /**
     * 下单。`$platformOrderNo` 放进 `extendField1`——云洋没有防重复单号，这是回调带回来时
     * 把订单认回来的唯一线索（yunyang.md 第 3 节）。
     *
     * **超时、500、响应解析不出结构：一律返回结果未知，调用方不能重试**，理由见类注释第 4 条。
     * 接口明确返回失败（code 不是 "1"）才是明确失败——这时云洋没受理，没有产生任何费用。
     *
     * `$content` 里的业务字段（收寄件人、重量、保价金额 `insured` 等）由调用方按云洋文档拼好；
     * 重量是整数公斤、菜鸟渠道必须带预约取件时间（yunyang.md 第 4 节），这些是调用方的责任。
     *
     * @param array<string, mixed> $content
     */
    public function placeOrder(string $platformOrderNo, string $channelId, array $content = []): DriverResult
    {
        $body = $content + [
            'channelId' => $channelId,
            'channelTag' => self::CHANNEL_TAG,
            'extendField1' => $platformOrderNo,
        ];

        $response = $this->send(self::SERVICE_CREATE_ORDER, $body, self::CODE_OK_ORDER);

        if ($response['httpStatus'] !== 200 || $response['code'] === null) {
            return new DriverResult(
                result: UnifiedResult::Unknown,
                failReason: $this->describeTransportFailure($response['httpStatus']),
                rawRequest: $body,
                rawResponse: $response['raw'],
            );
        }

        if (! $response['ok']) {
            return new DriverResult(
                result: UnifiedResult::DefiniteFailure,
                failReason: 'yunyang: ' . $this->describeBusinessFailure($response),
                rawRequest: $body,
                rawResponse: $response['raw'],
            );
        }

        return $this->mapOrderData(is_array($response['result']) ? $response['result'] : [], $body, $response['raw']);
    }

    /**
     * 查询订单详情。**按云洋的单号查**（商家单号 `shopbill` 或运单号 `waybill`），
     * 不是平台订单号，理由见类注释第 5 条。
     *
     * 查询本身失败（超时、500、解析不了）：结果未知，继续查（同卡速售）。
     * 接口明确返回失败（查无此单）：明确失败——这时确实没有这笔订单。
     */
    public function queryOrder(string $supplierOrderNo): DriverResult
    {
        $body = ['shopbill' => $supplierOrderNo];
        $response = $this->send(self::SERVICE_ORDER_DETAIL, $body, self::CODE_OK_ORDER);

        if ($response['httpStatus'] !== 200 || $response['code'] === null) {
            return new DriverResult(
                result: UnifiedResult::Unknown,
                failReason: $this->describeTransportFailure($response['httpStatus']),
                rawRequest: $body,
                rawResponse: $response['raw'],
            );
        }

        if (! $response['ok']) {
            return new DriverResult(
                result: UnifiedResult::DefiniteFailure,
                failReason: 'yunyang: order not found for shopbill=' . $supplierOrderNo . '（' . $this->describeBusinessFailure($response) . '）',
                rawRequest: $body,
                rawResponse: $response['raw'],
            );
        }

        return $this->mapOrderData(is_array($response['result']) ? $response['result'] : [], $body, $response['raw']);
    }

    /**
     * 解析回调。**回调没有签名**，所以这里不解析也不相信 payload 里的任何状态和金额，
     * 只从中取出单号，然后调订单详情接口（带签名）拿权威数据返回（yunyang.md 第 3 节）。
     *
     * payload 里连单号都找不到时返回 null：调用方不能把它当权威结果处理，也不能回复
     * 云洋 `{"code":1,"message":"推送成功"}`——回不成功云洋会自己重试，比认下一个
     * 认不出来的回调要好。
     *
     * `extendField1`（平台订单号）也一并取出来交给调用方：shopbill 对不上时它是唯一线索。
     *
     * @param array<string, mixed> $payload
     */
    public function parseCallback(array $payload): ?DriverResult
    {
        $supplierOrderNo = $this->toStringOrNull($payload['shopbill'] ?? null)
            ?? $this->toStringOrNull($payload['waybill'] ?? null);
        if ($supplierOrderNo === null) {
            return null;
        }

        return $this->queryOrder($supplierOrderNo);
    }

    /**
     * 回调里带回来的平台订单号（下单时放在 `extendField1`），供调用方在 shopbill
     * 对不上时认领订单用。取不到返回 null。
     *
     * @param array<string, mixed> $payload
     */
    public function platformOrderNoFromCallback(array $payload): ?string
    {
        return $this->toStringOrNull($payload['extendField1'] ?? null);
    }

    /**
     * 取消订单。只有待揽收状态能取消，德邦重货等部分渠道不支持接口取消
     * （yunyang.md 第 1 节"取消"），这两种情况云洋都返回失败 + 原因文案，
     * 原样带回给调用方（客服要看到"为什么取消不了"）。
     *
     * @return array{cancelled: bool, message: string}
     */
    public function cancelOrder(string $supplierOrderNo): array
    {
        $response = $this->send(self::SERVICE_CANCEL_ORDER, ['shopbill' => $supplierOrderNo], self::CODE_OK_ORDER);

        return [
            'cancelled' => $response['ok'],
            'message' => $response['ok'] ? '' : $this->describeBusinessFailure($response),
        ];
    }

    /**
     * 轨迹查询（yunyang.md 第 1 节"查询"）。失败时返回空数组——轨迹是展示用的，
     * 查不到不影响订单本身的判定。
     *
     * @return list<array{time: null|string, description: null|string}>
     */
    public function queryTrace(string $supplierOrderNo): array
    {
        $response = $this->send(self::SERVICE_TRACE, ['shopbill' => $supplierOrderNo], self::CODE_OK_ORDER);
        if (! $response['ok'] || ! is_array($response['result'])) {
            return [];
        }

        $list = $response['result'];
        if (isset($list['list']) && is_array($list['list'])) {
            $list = $list['list'];
        }

        $traces = [];
        foreach ($list as $raw) {
            if (is_array($raw)) {
                $traces[] = [
                    'time' => $this->toStringOrNull($raw['time'] ?? $raw['acceptTime'] ?? null),
                    'description' => $this->toStringOrNull($raw['context'] ?? $raw['acceptStation'] ?? null),
                ];
            }
        }

        return $traces;
    }

    /**
     * 查询平台在云洋的预存款余额。**取可用余额 `keyong`**，不是总额 `yue`——
     * 冻结部分（`dongjie`）已经被在途订单占用，拿总额做余额监控会在真正不够用的时候
     * 还显示充足（yunyang.md 第 1 节"余额"）。
     *
     * @throws RuntimeException 接口失败或余额字段解析不出来——余额监控宁可报错记日志，
     *                          也不能返回一个猜出来的数字去更新供应商余额
     */
    public function queryBalance(): string
    {
        $response = $this->send(self::SERVICE_BALANCE, [], self::CODE_OK_ACCOUNT);
        if (! $response['ok']) {
            throw new RuntimeException('YunyangDriver::queryBalance: ' . $this->describeBusinessFailure($response));
        }

        $balance = is_array($response['result']) ? ($response['result']['keyong'] ?? null) : null;
        if (! is_string($balance) && ! is_int($balance) && ! is_float($balance)) {
            throw new RuntimeException('YunyangDriver::queryBalance: keyong field missing or unparseable in response.');
        }

        return (string) $balance;
    }

    protected function httpClient(): ClientInterface
    {
        return ApplicationContext::getContainer()->get(ClientFactory::class)->create();
    }

    /**
     * 发一次带签名的请求。返回 `[httpStatus, code, message, result, ok, raw]`：
     * `httpStatus` 为 null 表示网络异常/超时，`code` 为 null 表示响应体不是可解析的
     * `{code, message, result}` 结构，`ok` 表示业务成功（code 等于调用方传进来的成功码）。
     *
     * 云洋的签名只覆盖 appid + requestId + timeStamp + secretKey，`content` 不参与，
     * 所以这里不需要像卡速售那样对请求体排序再编码（见 YunyangSigner 类注释）。
     *
     * @param array<string, mixed> $content
     * @return array{httpStatus: null|int, code: null|string, message: string, result: mixed, ok: bool, raw: array<string, mixed>}
     */
    private function send(string $serviceCode, array $content, string $successCode): array
    {
        $requestId = $this->signer->requestId();
        $timestamp = $this->signer->timestamp();
        $body = [
            'appid' => $this->appId,
            'requestId' => $requestId,
            'timeStamp' => $timestamp,
            'sign' => $this->signer->sign($this->appId, $requestId, $timestamp, $this->secretKey),
            'serviceCode' => $serviceCode,
            // content 按 JSON 字符串传（文档把它叫"请求内容"且明确说不参与签名）；
            // 联调时如果对方要的是对象，改这一行即可
            'content' => json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
        $startedAt = microtime(true);

        try {
            $httpResponse = $this->httpClient()->request('POST', $this->baseUrl . self::PATH_OPEN_SERVICE, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $body,
                'timeout' => 10,
                'connect_timeout' => 5,
                // 跟卡速售驱动一样：4xx/5xx 不抛异常，走业务分支判断，
                // 异常分支只留给真正的连接/超时失败
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            $this->recordCall($serviceCode, $body, ['exception' => $e->getMessage()], $startedAt);

            return [
                'httpStatus' => null,
                'code' => null,
                'message' => $e->getMessage(),
                'result' => null,
                'ok' => false,
                'raw' => ['request' => $body, 'exception' => $e->getMessage()],
            ];
        }

        $httpStatus = $httpResponse->getStatusCode();
        $rawBody = (string) $httpResponse->getBody();
        $decoded = json_decode($rawBody, true);
        // code 在文档里是字符串（"1"/"0"/"200"），但 JSON 里也可能是数字，统一成字符串比较
        $code = is_array($decoded) && isset($decoded['code']) && (is_string($decoded['code']) || is_int($decoded['code']))
            ? (string) $decoded['code']
            : null;

        $this->recordCall($serviceCode, $body, ['http_status' => $httpStatus, 'body' => is_array($decoded) ? $decoded : $rawBody], $startedAt);

        return [
            'httpStatus' => $httpStatus,
            'code' => $code,
            'message' => is_array($decoded) ? (string) ($decoded['message'] ?? '') : '',
            'result' => is_array($decoded) ? ($decoded['result'] ?? null) : null,
            'ok' => $httpStatus === 200 && $code === $successCode,
            'raw' => ['http_status' => $httpStatus, 'body' => $rawBody],
        ];
    }

    /**
     * 把订单详情/下单响应的 result 转成 DriverResult。判定成功与否看 `feeOver` 而不是
     * 物流状态，映射表见 App\Supplier\Yunyang\YunyangStatusMapper。
     *
     * `actualCost` 取 `totalFreight`（结算依据，yunyang.md 第 4 节）；还没扣费时
     * 云洋给的是冻结运费 `freight`，两个都放进 `expressFees` 交给业务层，
     * 驱动不替它决定按哪个冻结、按哪个结算。
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $rawRequest
     * @param array<string, mixed> $rawResponse
     */
    private function mapOrderData(array $result, array $rawRequest, array $rawResponse): DriverResult
    {
        $feeOver = $this->toNullableInt($result['feeOver'] ?? null);
        $typeCode = $this->toNullableInt($result['typeCode'] ?? null);
        $unified = $this->statusMapper->map($typeCode, $feeOver);

        $fees = [
            'fee_over' => $feeOver,
            'type_code' => $typeCode,
            'waybill' => $this->toStringOrNull($result['waybill'] ?? null),
            'weight' => $this->toMoneyString($result['weight'] ?? null),
            'total_freight' => $this->toMoneyString($result['totalFreight'] ?? null),
            'freight' => $this->toMoneyString($result['freight'] ?? null),
            'freight_insured' => $this->toMoneyString($result['freightInsured'] ?? null),
            'freight_haocai' => $this->toMoneyString($result['freightHaocai'] ?? null),
            'change_bill_freight' => $this->toMoneyString($result['changeBillFreight'] ?? null),
            // 下单时放进 extendField1 的平台订单号，查询结果（带签名的请求）原样带回来。
            // 下单结果未知、平台还没拿到 shopbill 的订单，只能靠它把回调认回来——而且必须用
            // 查询结果里的这个值，不能用回调 payload 里的（回调没签名，谁都能填）
            'platform_order_no' => $this->toStringOrNull($result['extendField1'] ?? null),
        ];

        return new DriverResult(
            result: $unified,
            supplierOrderNo: $this->toStringOrNull($result['shopbill'] ?? null),
            failReason: $unified === UnifiedResult::DefiniteFailure
                ? ('yunyang: order cancelled (typeCode ' . ($typeCode ?? 'null') . ')')
                : null,
            actualCost: $fees['total_freight'] ?? $fees['freight'],
            // 快递没有"退款金额"这个字段，退回运费走费用调整（requirements.md 7.2），
            // 不塞进 refundAmount 装成话费那套
            refundAmount: null,
            // 云洋文档里没有任何返佣字段（yunyang.md 第 5 节已确认），快递暂按无返佣
            supplierRebate: null,
            rawRequest: $rawRequest,
            rawResponse: $rawResponse,
            expressFees: $fees,
        );
    }

    /**
     * 渠道条目归一化。字段名是按文档行文的推断，见类注释。
     *
     * @param array<string, mixed> $raw
     * @return array{channel_id: null|string, channel_name: null|string, freight: null|string,
     *     freight_insured: null|string, freight_haocai: null|string, total_freight: null|string,
     *     official_price: null|string, billing_rule: null|string, allow_insured: bool,
     *     appointment_times: list<string>}
     */
    private function parseChannel(array $raw): array
    {
        $times = [];
        $rawTimes = $raw['appointmentTimes'] ?? $raw['appointTime'] ?? null;
        if (is_array($rawTimes)) {
            foreach ($rawTimes as $time) {
                $value = $this->toStringOrNull($time);
                if ($value !== null) {
                    $times[] = $value;
                }
            }
        }

        return [
            'channel_id' => $this->toStringOrNull($raw['channelId'] ?? null),
            'channel_name' => $this->toStringOrNull($raw['channelName'] ?? null),
            'freight' => $this->toMoneyString($raw['freight'] ?? null),
            'freight_insured' => $this->toMoneyString($raw['freightInsured'] ?? null),
            'freight_haocai' => $this->toMoneyString($raw['freightHaocai'] ?? null),
            'total_freight' => $this->toMoneyString($raw['totalFreight'] ?? null),
            'official_price' => $this->toMoneyString($raw['officialPrice'] ?? null),
            // 计费规则说明（首重/续重那段文案），原样透传给商户（requirements.md 7.2
            // 查价要返回"计费说明"）
            'billing_rule' => $this->toStringOrNull($raw['chargeRule'] ?? $raw['billingRule'] ?? null),
            // 只有 allowInsured=1 的渠道能选保价（yunyang.md 第 4 节）
            'allow_insured' => $this->toNullableInt($raw['allowInsured'] ?? null) === 1,
            'appointment_times' => $times,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $response
     */
    private function recordCall(string $serviceCode, array $body, array $response, float $startedAt): void
    {
        if ($this->callRecorder === null) {
            return;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        ($this->callRecorder)(
            self::ACTIONS[$serviceCode] ?? $serviceCode,
            ['path' => self::PATH_OPEN_SERVICE, 'service_code' => $serviceCode, 'body' => $body],
            $response,
            $durationMs
        );
    }

    private function describeTransportFailure(?int $httpStatus): string
    {
        return $httpStatus === null
            ? 'yunyang: network error or timeout'
            : sprintf('yunyang: unexpected http status %d or unparseable response', $httpStatus);
    }

    /**
     * @param array{code: null|string, message: string} $response
     */
    private function describeBusinessFailure(array $response): string
    {
        return sprintf('code %s: %s', $response['code'] ?? 'null', $response['message'] !== '' ? $response['message'] : '(no message)');
    }

    private function toStringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * 金额一律转成字符串，不转 float（database-design.md 5：金额禁止用浮点数）。
     */
    private function toMoneyString(mixed $value): ?string
    {
        if (is_string($value)) {
            return is_numeric($value) ? $value : null;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : null;
    }
}
