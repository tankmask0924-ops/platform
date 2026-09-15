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

namespace App\Service\Supplier;

use App\Dao\SupplierProductDao;
use App\Service\AbstractService;
use App\Supplier\Kasushou\KasushouDriver;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * 供应商商品同步的编排层（kasushou.md 第 4 节"商品同步"）。命名和命名空间刻意不带
 * "Kasushou"——虽然今天只有卡速售一个驱动实现，但云洋、芒果以后也会有各自的商品
 * 同步需求，这层"验签/取权威值 -> 查映射行 -> 落库"的编排逻辑跟具体供应商驱动
 * 无关，只要驱动实现了 parseProductChangeNotification()/queryProductDetail() 这样
 * 的方法（目前直接依赖 KasushouDriver 具体类型，原因见
 * App\Supplier\Kasushou\KasushouDriver 类注释里"是否需要 DriverInterface"的判断——
 * 只有一个驱动实现时不引入接口，等第二个驱动落地再抽取）。
 *
 * 【本次任务明确没有接入任何触发源】这个 Service 目前没有任何调用方：
 * - 商品变更通知走 HTTP webhook 触发 applyNotification()，但 webhook 路由/控制器
 *   本身是 docs/modules.md 第 1 节"供应商回调入口与验签框架"，还是 ⬜，没建；
 * - 每日全量同步走 `#[Crontab]` 定时任务触发 applyFullSyncPage()（配合
 *   KasushouDriver::syncAllProducts() 翻页），但这需要一个"从哪里读供应商配置
 *   （baseUrl/userId/apiKey）"的 Supplier 配置加载机制，同样还没建（原始
 *   KasushouDriver 任务里就是同样的理由跳过了 Service 接入）。
 * 也就是说这里只是把"收到通知/收到一页全量数据之后该怎么落库"这段逻辑准备好，
 * 不代表这条链路已经在生产环境跑起来——docs/modules.md 第 2 节的表格会如实标注
 * 这一点。
 */
class ProductSyncService extends AbstractService
{
    #[Inject]
    protected SupplierProductDao $supplierProductDao;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 处理一条商品变更通知。验签失败、或者验签通过但这个供应商商品在平台上还没
     * 配置映射行（`supplier_products` 找不到对应行）：都是 no-op，不抛异常——
     * 前者是"通知不可信，忽略"，后者是"平台还没配置，没有可更新的行"（详见
     * 任务范围说明：商品映射创建行不在本次任务范围内）。
     */
    public function applyNotification(int $supplierId, KasushouDriver $driver, array $payload): void
    {
        $code = $driver->parseProductChangeNotification($payload);
        if ($code === null) {
            $this->logger()->warning('supplier product change notification signature invalid, ignored', [
                'supplier_id' => $supplierId,
            ]);
            return;
        }

        $mapping = $this->supplierProductDao->findBySupplierAndCode($supplierId, $code);
        if ($mapping === null) {
            $this->logger()->info('supplier product change notification for unmapped product, skipped', [
                'supplier_id' => $supplierId,
                'supplier_product_code' => $code,
            ]);
            return;
        }

        // 通知本身不可信任何价格/状态/库存字段（见 KasushouDriver 类注释的安全
        // 设计说明），验签通过后只当成"该查一下这个商品了"的触发信号，权威值
        // 一律重新查一次。
        $detail = $driver->queryProductDetail($code);
        $this->supplierProductDao->applySync($mapping, $detail['cost_price'], $detail['status'], $detail['stock']);
    }

    /**
     * 应用一页已经取回的全量同步数据（由调用方通过
     * `KasushouDriver::syncAllProducts()` 取好后传进来，翻页循环由调用方负责，
     * 见类注释）。这批数据本身来自平台主动发起的出站认证请求，不是入站通知，
     * 可以直接信任，不需要再逐条 queryProductDetail()。
     *
     * @param array<int, array{supplier_product_code: string, cost_price: string, status: string, stock: null|int}> $productDetails
     */
    public function applyFullSyncPage(int $supplierId, array $productDetails): void
    {
        foreach ($productDetails as $detail) {
            $code = $detail['supplier_product_code'] ?? null;
            if (! is_string($code) || $code === '') {
                continue;
            }

            $mapping = $this->supplierProductDao->findBySupplierAndCode($supplierId, $code);
            if ($mapping === null) {
                $this->logger()->info('full sync entry for unmapped product, skipped', [
                    'supplier_id' => $supplierId,
                    'supplier_product_code' => $code,
                ]);
                continue;
            }

            $this->supplierProductDao->applySync($mapping, $detail['cost_price'], $detail['status'], $detail['stock']);
        }
    }

    private function logger(): LoggerInterface
    {
        return $this->loggerFactory->get('supplier-product-sync');
    }
}
