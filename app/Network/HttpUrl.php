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

namespace App\Network;

/**
 * 商户提交的图片链接（充值凭证、证件照片）会在管理端直接当链接打开，
 * 只放行 http(s)，挡掉 javascript: 之类能在管理员浏览器里执行脚本的地址。
 */
final class HttpUrl
{
    public static function isValid(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
