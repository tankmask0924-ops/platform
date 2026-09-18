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

namespace App\OpenApi;

/**
 * 开放 API 平台统一错误码（requirements.md 8.1「code 非 0 表示失败，错误码统一编号」）。
 * 开放 API 的所有出口（签名中间件、Controller、下单 Service 抛出的异常、订单失败原因）
 * 都从这里取码和文案，不在各处各写一份占位编号。
 *
 * 编号分段：
 *   400xx 鉴权与安全（签名、白名单、防重放、限流）
 *   410xx 请求参数
 *   420xx 业务校验（商品、商户状态、订单查询）
 *   430xx 订单失败原因：不作为接口的 code 返回，而是放在订单数据的 `fail_code` 里
 *         （订单本身已受理，只是结果失败），文案同时写进 `orders.fail_reason`
 *   490xx 通用（接口不存在、请求方法不对、其它无法归类的请求错误）
 *   500xx 系统错误
 *
 * HTTP 状态码：鉴权与安全类用 401/403/429，方便网关和商户 HTTP 客户端直接识别；
 * 参数和业务类一律 HTTP 200，以 body 里的 code 为准；接口不存在 404、方法不对 405；
 * 系统错误 500。无论哪种状态码，body 都是 {code, message, data} 信封。
 *
 * 文案是给商户看的平台统一文案，不含供应商名称、供应商原始错误信息或内部异常信息。
 * 已经对外公布的编号不要改动或复用，新增错误往对应分段后面追加。
 */
enum ErrorCode: int
{
    // 400xx 鉴权与安全
    case MissingAuthParams = 40001;
    case UnknownAppKey = 40002;
    case MerchantNotActive = 40003;
    case AppSecretNotConfigured = 40004;
    case InvalidSignature = 40005;
    case TimestampOutOfWindow = 40006;
    case NonceReplayed = 40007;
    case IpNotAllowed = 40008;
    case RateLimited = 40009;

    // 410xx 请求参数
    case InvalidParams = 41001;
    case UnsupportedBusinessLine = 41002;

    // 420xx 业务校验
    case ProductNotFound = 42001;
    case ProductNotOnShelf = 42002;
    case ProductBusinessLineMismatch = 42003;
    case MerchantSuspended = 42004;
    case OrderNotFound = 42005;
    case ProductUnavailable = 42006;
    case BusinessNotSubscribed = 42007;

    // 430xx 订单失败原因
    case InsufficientBalance = 43001;
    case NoSupplierAvailable = 43002;
    case OrderFailed = 43003;

    // 490xx 通用
    case RouteNotFound = 49001;
    case MethodNotAllowed = 49002;
    case BadRequest = 49003;

    // 500xx 系统错误
    case InternalError = 50000;

    /**
     * 可作为订单失败原因的错误码。
     */
    private const ORDER_FAILURES = [
        self::InsufficientBalance,
        self::NoSupplierAvailable,
        self::OrderFailed,
    ];

    public function message(): string
    {
        return match ($this) {
            self::MissingAuthParams => '缺少或错误的公共参数 app_key/timestamp/nonce',
            self::UnknownAppKey => 'app_key 不存在',
            self::MerchantNotActive => '商户状态不可用',
            self::AppSecretNotConfigured => '商户尚未生成密钥',
            self::InvalidSignature => '签名错误',
            self::TimestampOutOfWindow => 'timestamp 与服务器时间相差超过 5 分钟',
            self::NonceReplayed => 'nonce 重复',
            self::IpNotAllowed => '来源 IP 不在白名单内',
            self::RateLimited => '请求过于频繁，请稍后再试',
            self::InvalidParams => '请求参数缺失或格式错误',
            self::UnsupportedBusinessLine => '不支持的业务线',
            self::ProductNotFound => '商品不存在',
            self::ProductNotOnShelf => '商品未上架',
            self::ProductBusinessLineMismatch => '商品不属于该业务线',
            self::MerchantSuspended => '商户当前存在欠款，已暂停下单，请充值补足欠款后再试',
            self::OrderNotFound => '订单不存在',
            self::ProductUnavailable => '商品暂不可售',
            self::BusinessNotSubscribed => '未开通该业务线，请在商户后台申请开通',
            self::InsufficientBalance => '可用余额不足',
            self::NoSupplierAvailable => '商品暂时无法供货',
            self::OrderFailed => '订单处理失败',
            self::RouteNotFound => '接口不存在',
            self::MethodNotAllowed => '请求方法不正确',
            self::BadRequest => '请求无法处理',
            self::InternalError => '系统繁忙，请稍后再试',
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::MissingAuthParams, self::UnknownAppKey, self::InvalidSignature,
            self::TimestampOutOfWindow, self::NonceReplayed => 401,
            self::MerchantNotActive, self::AppSecretNotConfigured, self::IpNotAllowed => 403,
            self::RateLimited => 429,
            self::RouteNotFound => 404,
            self::MethodNotAllowed => 405,
            self::InternalError => 500,
            default => 200,
        };
    }

    /**
     * 按 `orders.fail_reason` 里存的平台文案反查失败错误码。旧数据或其它路径写入的
     * 不认识的文案一律归为 OrderFailed，保证对外永远只出现平台统一的码和文案。
     */
    public static function fromOrderFailReason(string $failReason): self
    {
        foreach (self::ORDER_FAILURES as $case) {
            if ($case->message() === $failReason) {
                return $case;
            }
        }

        return self::OrderFailed;
    }

    /**
     * 订单对商户展示的失败信息：`fail_code` + `fail_reason`（平台文案）。
     * 订单没有失败原因时两者都是 null。
     *
     * @return array{fail_code: null|int, fail_reason: null|string}
     */
    public static function presentOrderFailure(?string $failReason): array
    {
        if ($failReason === null || $failReason === '') {
            return ['fail_code' => null, 'fail_reason' => null];
        }

        $code = self::fromOrderFailReason($failReason);

        return ['fail_code' => $code->value, 'fail_reason' => $code->message()];
    }
}
