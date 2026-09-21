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

namespace App\Express;

use RuntimeException;

use function Hyperf\Support\env;

/**
 * 快递渠道编号的对外编码（requirements.md 7.2「平台对外使用自己的渠道编号，不暴露供应商
 * 渠道 ID」，yunyang.md 第 4 节同样要求）。
 *
 * 为什么不建一张映射表：渠道是供应商侧的动态数据（同一个地址不同重量返回的渠道集合都可能
 * 不一样），建表就要同步、要过期、要处理"表里有而这次查价没返回"的行。而下单流程本来就
 * **必须重新查一次价**（requirements.md 7.2「下单前重新向供应商检测价格，不采用商户传入
 * 的金额」），所以把渠道编号做成一个**确定性的单向编码**：查价时对每个供应商渠道算出编号
 * 返回给商户，下单时重新查价、对返回的每个渠道算一遍编号，找出跟商户传进来的那个相等的，
 * 就换回了供应商渠道 ID。不需要存储、不会过期、也不存在"编号还在但渠道没了"的状态。
 *
 * 编码用 HMAC-SHA256 而不是可逆加密或明文拼接：
 * - 商户拿到的编号里**不含**供应商 id 和渠道 id 的任何可还原信息，它只是个标签；
 * - 密钥来自 `APP_ENCRYPTION_KEY`，但先派生出一个只用于本用途的子密钥，不直接拿主密钥当
 *   HMAC key——同一把密钥同时用于加密存储和签名标签是没必要的耦合；
 * - 比对用 `hash_equals()`，不用 `===`，跟平台其它签名比对保持一致。
 *
 * **同一个渠道的编号在不同商户、不同次查价之间是稳定的**（输入只有供应商 id + 渠道 id）。
 * 这是故意的：商户可以把常用的渠道编号存下来直接下单，不必每次先查价。代价是编号不具备
 * "报价有效期"的含义——价格永远以下单时重新查到的为准，这跟云洋没有报价单号、没有有效期
 * 的现实是一致的（yunyang.md 第 1 节「报价有效期」）。
 */
class ExpressChannelCodec
{
    /** 编号前缀，方便一眼认出是平台的快递渠道编号 */
    private const PREFIX = 'EX';

    /** 取 HMAC 的前 16 个十六进制字符（64 位），碰撞概率远低于渠道数量级 */
    private const LENGTH = 16;

    /** 子密钥的派生标签，换了这个值会让所有已发出的渠道编号失效 */
    private const KEY_CONTEXT = 'express-channel-code';

    public function encode(int $supplierId, string $supplierChannelId): string
    {
        return self::PREFIX . substr(
            hash_hmac('sha256', $supplierId . ':' . $supplierChannelId, $this->key()),
            0,
            self::LENGTH
        );
    }

    /**
     * 商户传进来的编号是不是这个供应商渠道的编号。用于下单时把编号换回供应商渠道 ID。
     */
    public function matches(string $platformCode, int $supplierId, string $supplierChannelId): bool
    {
        return hash_equals($this->encode($supplierId, $supplierChannelId), $platformCode);
    }

    /**
     * 只用于本用途的子密钥，从主密钥派生（见类注释）。
     */
    private function key(): string
    {
        $master = env('APP_ENCRYPTION_KEY');
        if (! is_string($master) || $master === '') {
            throw new RuntimeException('APP_ENCRYPTION_KEY is not configured.');
        }

        return hash_hmac('sha256', self::KEY_CONTEXT, $master, true);
    }
}
