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
 * 卡速售 2.0 的签名算法（kasushou.md 第 1 节"签名"行），跟平台开放 API 用的
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
 * 商品变更通知验签（第三套签名作用域，kasushou.md 第 4 节"商品同步"：
 * "接收商品变更通知（表单格式，签名只包含 id 和 time）"）：
 *   - 【本方法公式是推断，不是文档直接给出的字符串】文档只说了"签名只包含 id 和
 *     time"这一句话，没有像上面回调验签那样给出完整的 "sha1(time + JSON + apikey)"
 *     公式原文。这里按本类另外两个方法共用的通用形状——sha1(时间 + 排序后 JSON
 *     编码 + apikey)——收窄到只对 {id, time} 这两个字段计算，是本次实现按"同一
 *     供应商同一套签名家族"做的合理推断，不是对文档原文的直接引用。等实际联调
 *     验证不通过时，只需要改 verifyProductChangeNotification() 这一个方法，
 *     不影响 KasushouDriver::parseProductChangeNotification() 的调用方式
 *   - 但"参与签名的字段只有 id + time"这件事本身是文档明确写出来的，不是推断——
 *     这是一个白名单（只有这两个字段参与），跟回调验签的黑名单（除 sign/card_list/
 *     express_list 外全部参与）方向相反：即使通知表单里还夹带了价格/状态/库存等
 *     字段，这里也完全不读取它们参与签名，调用方同样不能信任这些字段本身
 *     （见 KasushouDriver::parseProductChangeNotification() 类注释里的安全说明）
 *   - JSON 编码 flag 沿用回调验签同款的 JSON_UNESCAPED_UNICODE（不用
 *     UNESCAPED_SLASHES）——两者同属"供应商 -> 平台"方向的推送/通知，跟"平台 ->
 *     供应商"方向的请求签名（用 UNESCAPED_SLASHES | UNESCAPED_UNICODE）分开处理
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
     * @param array<string, mixed> $params 请求体的顶层参数（不含 Timestamp/UserId，那两个走 Header）
     */
    public function signRequest(array $params, string $apiKey, string $timestamp): string
    {
        return sha1($timestamp . $this->encodeSorted($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . $apiKey);
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
