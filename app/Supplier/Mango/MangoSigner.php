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

namespace App\Supplier\Mango;

/**
 * 芒果电影的签名（mango.md 第 1 节"签名"行）：参数按 key 字典序排序，`key + value` 依次
 * 直接拼接（没有 `=`、`&` 分隔符），末尾拼上 `token`，整体取 32 位小写 md5，结果放 `signid`。
 *
 * 跟云洋（App\Supplier\Yunyang\YunyangSigner）不同，**请求参数整体参与签名**，所以请求内容
 * 被改了验签就过不了——芒果驱动因此不像云洋那样强制 https。
 *
 * 【两处是推断】文档没给可核对的签名样例：
 * 1. 公共参数 `agent_id`、`app_id` 也参与排序拼接（"参数"没有把它们排除在外），`signid` 自己不参与；
 * 2. 值是数组（锁座的 `seat_data`）时按 JSON 编码（不转义中文和斜杠）后拼进去——拼接式签名没法
 *    直接拼数组，这是这类接口最常见的做法。
 * 联调验签不过只改 sign() 这一个方法。
 */
class MangoSigner
{
    /**
     * @param array<string, mixed> $params 不含 signid 的全部请求参数
     */
    public function sign(array $params, string $token): string
    {
        unset($params['signid']);
        ksort($params, SORT_STRING);

        $text = '';
        foreach ($params as $key => $value) {
            $text .= $key . $this->stringify($value);
        }

        return md5($text . $token);
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            is_bool($value) => $value ? '1' : '0',
            $value === null => '',
            default => (string) $value,
        };
    }
}
