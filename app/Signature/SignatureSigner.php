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

namespace App\Signature;

/**
 * 开放 API 的签名算法（requirements.md 8.1）：除 sign 外的参数按 key 字母排序
 * 拼成 k1=v1&k2=v2，用 AppSecret 做 HMAC-SHA256。平台回调商户时用同样的算法签名，
 * 所以验签、签名共用这一个类，不写两套逻辑。
 */
class SignatureSigner
{
    /**
     * 用 $secret 对 $params 计算签名（自动忽略 sign 字段）。
     *
     * @param array<string, scalar> $params
     */
    public function sign(array $params, string $secret): string
    {
        unset($params['sign']);
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return hash_hmac('sha256', implode('&', $pairs), $secret);
    }

    /**
     * 校验 $params 里的 sign 字段是否和用 $secret 重新计算的签名一致。
     *
     * @param array<string, scalar> $params
     */
    public function verify(array $params, string $secret): bool
    {
        $sign = $params['sign'] ?? null;
        if (! is_string($sign) || $sign === '') {
            return false;
        }

        return hash_equals($this->sign($params, $secret), $sign);
    }
}
