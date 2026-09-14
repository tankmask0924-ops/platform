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

namespace App\Service;

use App\Job\NotifyMerchantJob;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Di\Annotation\Inject;

/**
 * 结果回调（平台 → 商户，requirements.md 7.6）的统一入口——订单进入成功/失败/取消/
 * 已退款等终态时应该调用这里，而不是自己去 new NotifyMerchantJob。
 *
 * 目前只有这一个"通知原语"落地：真正在订单成功/失败等生命周期节点调用
 * notify() 的那部分代码（供应商对接驱动/订单处理流程）还没有实现，等那部分
 * 工作落地时接进来即可，见 docs/modules.md 第 6 节这一行的备注。
 */
class MerchantNotifyService extends AbstractService
{
    #[Inject]
    protected DriverFactory $driverFactory;

    public function notify(int $orderId): void
    {
        $this->driverFactory->get('default')->push(new NotifyMerchantJob($orderId, 1));
    }
}
