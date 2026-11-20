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

/**
 * 卡速售 2.0 的签名算法（docs/卡速售2.0API接口文档.md「签名规范」及各回调的 php demo），跟平台开放 API 用的
 * `App\Signature\SignatureSigner`（HMAC-SHA256 + k=v&k=v 拼接）是完全不同的两套
 * 算法，不要混用：卡速售这边是 sha1 + JSON 编码拼接，且请求签名和回调验签用的
 * JSON 编码 flag、参与签名的字段范围都不一样，所以分别提供两个方法。
 *
 * 请求签名：Sign = sha1(Timestamp + 按顶层参数名排序后的 JSON 请求体 + apikey)
 *   - Timestamp 是 13 位毫秒时间戳（字符串形式，放 Header，不放进 JSON 请求体）
 *   - JSON 编码必须用 JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
 *   - 只对顶层键排序，嵌套结构（如 attach）内部顺序不处理
 *   - 空请求体编码成 `{}`（PHP 空数组 json_encode 默认是 `[]`，这里要特殊处理）
 *
 * 回调验签：sha1(time + 按参数名排序后的 JSON（除 sign 外）+ apikey)
 *   - JSON 编码只用 JSON_UNESCAPED_UNICODE（文档没提 UNESCAPED_SLASHES，不能想当然
 *     跟请求签名用一样的 flag，两处特意分开写，方便对照文档核对）
 *   - card_list、express_list 字段虽然在回调 payload 里，但不参与签名计算，必须先
 *     从待签名参数里剔除
 *   - time 字段本身仍然留在参与签名的 JSON 里（文档原文是"除 sign 外参数"），
 *     只是 sign / card_list / express_list 三个字段被排除
 *
 * 商品变更通知验签（docs/卡速售2.0API接口文档.md「商品变更通知」的 php demo）：
 *   sha1(time + json_encode(ksort({id, time}), JSON_UNESCAPED_UNICODE) + apikey)
 *   - 只有 id、time 两个字段参与签名，是白名单——跟回调验签的黑名单（除 sign/card_list/
 *     express_list 外全部参与）方向相反：通知表单里的 status/goods_price/stock_num 不受签名
 *     保护，调用方不能信任它们（见 KasushouDriver::parseProductChangeNotification()）
 *   - 通知是表单格式（application/x-www-form-urlencoded），id、time 都按收到的字符串参与编码
 *
 * 请求体必须原样发送签名时编码出来的那个字符串（见 encodeRequestBody()）：让 HTTP 客户端
 * 自己再编码一次的话，键顺序和转义（`\/`、`\uXXXX`）都可能跟签名用的不一样。
 */
class KasushouSigner
{
    /**
     * 生成 13 位毫秒时间戳字符串，供请求签名的 Timestamp 头使用。
     */
    public function timestamp(): string
    {
        return (string) (int) round(microtime(true) * 1000);
    }

    /**
     * 请求体的 JSON 字符串：顶层键排序，JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE，空请求体为 `{}`。
     * 签名和实际发送的请求体都用它，保证两边一字不差。
     *
     * @param array<string, mixed> $params 请求体的顶层参数（不含 Timestamp/UserId，那两个走 Header）
     */
    public function encodeRequestBody(array $params): string
    {
        return $this->encodeSorted($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $params 请求体的顶层参数（不含 Timestamp/UserId，那两个走 Header）
     */
    public function signRequest(array $params, string $apiKey, string $timestamp): string
    {
        return sha1($timestamp . $this->encodeRequestBody($params) . $apiKey);
    }

    /**
     * 校验回调参数里的 sign 字段。$params 是回调收到的完整参数（含 sign/time/
     * card_list/express_list 等），本方法自己负责按规则剔除字段、排序、编码。
     *
     * @param array<string, mixed> $params
     */
    public function verifyCallback(array $params, string $apiKey): bool
    {
        $sign = $params['sign'] ?? null;
        $time = $params['time'] ?? null;
        if (! is_string($sign) || $sign === '' || $time === null || $time === '') {
            return false;
        }

        $signed = $params;
        unset($signed['sign'], $signed['card_list'], $signed['express_list']);

        $timeString = is_string($time) ? $time : (string) $time;
        $expected = sha1($timeString . $this->encodeSorted($signed, JSON_UNESCAPED_UNICODE) . $apiKey);

        return hash_equals($expected, $sign);
    }

    /**
     * 校验商品变更通知的 id+time+sign 签名。$params 是通知收到的完整参数，可能还
     * 带有价格/状态/库存等字段——本方法一律不读取它们参与签名计算（见类注释里
     * "白名单只有 id+time"的说明），只负责判断 id+time+sign 这三者是否自洽。
     *
     * @param array<string, mixed> $params
     */
    public function verifyProductChangeNotification(array $params, string $apiKey): bool
    {
        $id = $params['id'] ?? null;
        $time = $params['time'] ?? null;
        $sign = $params['sign'] ?? null;

        if (! is_string($sign) || $sign === '') {
            return false;
        }
        if ($id === null || $id === '' || $time === null || $time === '') {
            return false;
        }
        if (! is_string($id) && ! is_int($id)) {
            return false;
        }

        $idString = is_string($id) ? $id : (string) $id;
        $timeString = is_string($time) ? $time : (string) $time;

        $signed = ['id' => $idString, 'time' => $timeString];
        ksort($signed);

        $expected = sha1($timeString . $this->encodeSorted($signed, JSON_UNESCAPED_UNICODE) . $apiKey);

        return hash_equals($expected, $sign);
    }

    /**
     * 只排序顶层键，按文档要求的 flag 编码；空数组按文档要求编码成 `{}`
     * 而不是 PHP 默认的 `[]`。
     *
     * @param array<string, mixed> $params
     */
    private function encodeSorted(array $params, int $flags): string
    {
        if ($params === []) {
            return '{}';
        }

        ksort($params);

        return (string) json_encode($params, $flags);
    }
}
