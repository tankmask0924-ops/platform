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
 * `App\Service\Order\SupplierCallbackService::handle()` 在驱动的 `parseCallback()`
 * 返回 `null`（验签失败）时抛出——绝不能把这种情况当成"已接受"回复供应商 `ok`，
 * 也绝不能静默吞掉当成 404，必须是一个能被 `App\Controller\NotifySupplierController`
 * 单独识别、映射成 HTTP 403 的独立类型，跟"供应商编码不存在"（404）、"回调签得对但
 * 订单号对不上"（404）三者互不相同、互不覆盖。
 */
class InvalidSupplierCallbackSignatureException extends RuntimeException
{
}
