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
 * `App\Service\Order\SupplierCallbackService::handle()` 按 `suppliers.code` 查不到
 * 供应商时抛出（requirements.md 6.8 通用回调入口 `/notify/{供应商编码}`）。
 * `App\Controller\NotifySupplierController` 捕获后映射成 HTTP 404——不认识的
 * 供应商编码，没有任何签名可验，也没有驱动可用，跟"这个 URL 不存在"是同一回事。
 */
class SupplierNotFoundException extends RuntimeException
{
}
