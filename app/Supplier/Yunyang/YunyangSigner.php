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

/**
 * 云洋国内快递的签名（yunyang.md 第 1 节"签名"行）：
 * `sign = md5(appid + requestId + timeStamp + secretKey)`，四段直接拼接后取 md5。
 *
 * **请求内容 `content` 不参与签名**——这是这套签名跟卡速售（`App\Supplier\Kasushou\KasushouSigner`，
 * 请求体整体参与 sha1）最大的区别，也是 yunyang.md 第 3 节列的风险之一：签名只能证明
 * "调用方持有 secretKey"，证明不了"这次请求的收件地址、重量、渠道没被改过"。
 * 唯一的防线是传输层，所以 `App\Supplier\Yunyang\YunyangDriver` 在构造时就拒绝非 https 的接口地址。
 *
 * **`requestId` 每次请求都要新生成**（yunyang.md 第 4 节）。它不是幂等键——云洋下单接口
 * 没有防重复的订单号参数（第 3 节），换句话说重复请求就是重复下单，重放保护指望不上它。
 *
 * 【`timeStamp` 的位数是推断】文档只写了参与签名的四段是什么，没有给出 `timeStamp` 是秒还是
 * 毫秒、也没有给可核对的报文样例。这里按国内这类 md5 拼接签名最常见的形式取**10 位秒级**
 * 时间戳，是本次实现的合理推断而不是文档原文。联调时如果验签不过，只需要改
 * timestamp() 这一个方法（驱动里所有请求都从这里取值），不影响其它代码。
 */
class YunyangSigner
{
    /**
     * 10 位秒级时间戳字符串，见类注释里的推断说明。
     */
    public function timestamp(): string
    {
        return (string) time();
    }

    /**
     * 每次请求新生成的 requestId（UUID v4）。
     */
    public function requestId(): string
    {
        $bytes = random_bytes(16);
        // 版本位 4、变体位 10xx，按 RFC 4122 v4 置位
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public function sign(string $appId, string $requestId, string $timestamp, string $secretKey): string
    {
        return md5($appId . $requestId . $timestamp . $secretKey);
    }
}
