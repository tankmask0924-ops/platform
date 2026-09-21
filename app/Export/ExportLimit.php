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

namespace App\Export;

use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 导出接口共用的行数上限（requirements.md 7.2 商户后台「资金流水 / 返佣 / 订单」的
 * "支持筛选导出"）。
 *
 * 导出接口不分页，一次把符合筛选条件的行全查出来。Swoole worker 常驻内存、多个请求
 * 共用同一个进程，没有上限的"全量导出"是最容易把整个 worker 拖垮的那一类接口
 * （一个商户导三年流水，别的商户的请求跟着一起 OOM）。
 *
 * **超过上限时报错而不是截断**：截断导出会安静地少给数据，商户拿去对账时不会发现
 * 少了——这比导不出来严重得多。明确 422 让商户缩小筛选范围（加时间段），导出的
 * 数据要么是完整的，要么根本没有。
 *
 * 静态工具类，跟 App\Supplier\CardSecretMasker 一样不进 DI 容器：没有状态、没有
 * 依赖，调用方也不需要替换它的实现。
 */
final class ExportLimit
{
    /**
     * 一次导出最多多少行。1 万行的 CSV 在 Excel 里是完全正常的量级，前端拿到 JSON
     * 再转 CSV 也不会卡；真要导更多的场景（年度对账）应该走离线任务，不是这个接口。
     */
    public const MAX_ROWS = 10000;

    public static function assertWithinLimit(int $total): void
    {
        if ($total > self::MAX_ROWS) {
            throw new HttpException(422, '符合条件的记录有 ' . $total . ' 条，一次最多导出 ' . self::MAX_ROWS . ' 条，请缩小时间范围后重试');
        }
    }
}
