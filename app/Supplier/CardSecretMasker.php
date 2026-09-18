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

namespace App\Supplier;

/**
 * 供应商请求/响应快照里的卡号卡密打码（requirements.md 9「供应商调用日志……一律打码」）。
 *
 * 驱动的原始响应体是 JSON 字符串（`['body' => '{...}']`），只按数组键打码会漏掉，
 * 所以能解析成 JSON 对象/数组的字符串也解开打码后再编码回去。
 */
final class CardSecretMasker
{
    private const MASKED_KEYS = ['card_no', 'card_password', 'card_pwd'];

    private const MASK = '******';

    public static function mask(mixed $data): mixed
    {
        if (is_string($data)) {
            return self::maskJsonString($data);
        }
        if (! is_array($data)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::MASKED_KEYS, true) && $value !== null && $value !== '') {
                $data[$key] = self::MASK;
            } else {
                $data[$key] = self::mask($value);
            }
        }

        return $data;
    }

    private static function maskJsonString(string $value): string
    {
        $trimmed = ltrim($value);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return $value;
        }

        $masked = self::mask($decoded);

        return $masked === $decoded ? $value : json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
