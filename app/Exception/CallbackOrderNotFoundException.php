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

namespace App\Exception;

use RuntimeException;

/**
 * `App\Service\Order\SupplierCallbackService::handle()` 在验签通过之后，仍然无法从
 * 回调结果反推出一笔真实存在的平台订单时抛出——覆盖两种子情况：驱动结果的
 * `rawRequest['external_orderno']` 本身缺失/不可解析，或者从中截出的 `order_no`
 * 在 `orders` 表里查不到。正常情况下不应该发生（`external_orderno` 是平台自己
 * 生成、原样传给供应商、供应商回调再原样带回来的），但驱动/供应商侧的异常数据
 * 不能让这里直接 500，必须是一个能被 `App\Controller\NotifySupplierController`
 * 干净地映射成 HTTP 404 的独立类型。
 */
class CallbackOrderNotFoundException extends RuntimeException
{
}
