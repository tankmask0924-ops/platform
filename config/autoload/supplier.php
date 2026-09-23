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
use function Hyperf\Support\env;

return [
    // 话费、卡券下单后调用供应商是否走异步队列（requirements.md 9「调用供应商下单……走异步队列，不阻塞商户下单请求」）。
    // 默认开启；测试环境在 phpunit.xml.dist 里关掉，已有的路由用例按同步结果断言。见 App\Service\Order\SupplierOrderDispatcher。
    'dispatch_async' => (bool) filter_var(env('SUPPLIER_DISPATCH_ASYNC', true), FILTER_VALIDATE_BOOL),
];
