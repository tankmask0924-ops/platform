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

use App\Dao\SupplierDao;
use App\Dao\SupplierProductDao;
use App\Exception\InvalidSupplierCallbackSignatureException;
use App\Exception\SupplierNotFoundException;
use App\Model\Supplier;
use App\Service\AbstractService;
use App\Supplier\Kasushou\KasushouDriver;
use App\Supplier\SupplierDriverFactory;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 供应商商品同步的编排层（kasushou.md 第 4 节"商品同步"）。命名和命名空间刻意不带
 * "Kasushou"——虽然今天只有卡速售一个驱动实现，但云洋、芒果以后也会有各自的商品
 * 同步需求，这层"验签/取权威值 -> 查映射行 -> 落库"的编排逻辑跟具体供应商驱动
 * 无关，只要驱动实现了 parseProductChangeNotification()/queryProductDetail() 这样
 * 的方法（目前直接依赖 KasushouDriver 具体类型，原因见
 * App\Supplier\Kasushou\KasushouDriver 类注释里"是否需要 DriverInterface"的判断——
 * 只有一个驱动实现时不引入接口，等第二个驱动落地再抽取）。
 *
 * 【触发源】
 * - 商品变更通知：`POST /notify/{code}/goods`（App\Controller\NotifySupplierController）
 *   -> handleNotification() -> applyNotification()；
 * - 每日全量校准：App\Crontab\SupplierProductSyncCrontab -> syncAllSuppliers()
 *   -> syncSupplier() 翻页 -> applyFullSyncPage()（kasushou.md 第 4 节"每天用商品列表
 *   全量校准一次，防止漏收通知"）。
 *
 * 【全量校准不下架列表里没出现的映射】商品列表接口的分页参数、外层结构都是按文档
 * 行文猜的（见 KasushouDriver 类注释），在没联调之前，"列表里没出现"不足以说明
 * 供应商真的下架了这个商品，贸然暂停映射可能让整个商品无货可路由。只更新出现的，
 * 没出现的保持原样。
 */
class ProductSyncService extends AbstractService
{
    /**
     * 全量校准单个供应商最多翻这么多页（100 条/页），防止接口忽略页码时无限翻页。
     */
    public const MAX_FULL_SYNC_PAGES = 200;

    public const FULL_SYNC_PAGE_SIZE = 100;

    #[Inject]
    protected SupplierProductDao $supplierProductDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * 商品变更通知入口：按回调地址里的供应商编码找到供应商和驱动再处理。
     * 供应商不存在抛 SupplierNotFoundException，验签失败抛
     * InvalidSupplierCallbackSignatureException；查询商品详情失败的异常原样抛出，
     * 让供应商按失败处理，漏掉的由每日全量校准补上。
     *
     * @param array<string, mixed> $payload
     */
    public function handleNotification(string $supplierCode, array $payload): void
    {
        $supplier = $this->supplierDao->findByCode($supplierCode);
        if ($supplier === null) {
            throw new SupplierNotFoundException('ProductSyncService: unknown supplier code "' . $supplierCode . '".');
        }

        $driver = $this->supplierDriverFactory->build($supplier);
        if (! $this->applyNotification((int) $supplier->id, $driver, $payload)) {
            throw new InvalidSupplierCallbackSignatureException(
                'ProductSyncService: product change notification signature verification failed for supplier "' . $supplierCode . '".'
            );
        }
    }

    /**
     * 处理一条商品变更通知。验签失败、或者验签通过但这个供应商商品在平台上还没
     * 配置映射行（`supplier_products` 找不到对应行）：都是 no-op，不抛异常——
     * 前者是"通知不可信，忽略"，后者是"平台还没配置，没有可更新的行"（详见
     * 任务范围说明：商品映射创建行不在本次任务范围内）。
     *
     * @return bool 验签是否通过（false 时什么都没做）
     */
    public function applyNotification(int $supplierId, KasushouDriver $driver, array $payload): bool
    {
        $code = $driver->parseProductChangeNotification($payload);
        if ($code === null) {
            $this->logger()->warning('supplier product change notification signature invalid, ignored', [
                'supplier_id' => $supplierId,
            ]);
            return false;
        }

        $mapping = $this->supplierProductDao->findBySupplierAndCode($supplierId, $code);
        if ($mapping === null) {
            $this->logger()->info('supplier product change notification for unmapped product, skipped', [
                'supplier_id' => $supplierId,
                'supplier_product_code' => $code,
            ]);
            return true;
        }

        // 通知本身不可信任何价格/状态/库存字段（见 KasushouDriver 类注释的安全
        // 设计说明），验签通过后只当成"该查一下这个商品了"的触发信号，权威值
        // 一律重新查一次。
        $detail = $driver->queryProductDetail($code);
        $this->supplierProductDao->applySync($mapping, $detail['cost_price'], $detail['status'], $detail['stock']);

        return true;
    }

    /**
     * 每日全量校准所有启用中的供应商，单个供应商失败只记日志，不影响其它供应商。
     *
     * @return int 校准成功的供应商数
     */
    public function syncAllSuppliers(): int
    {
        $succeeded = 0;
        foreach ($this->supplierDao->listActive() as $supplier) {
            try {
                $this->syncSupplier($supplier);
                ++$succeeded;
            } catch (Throwable $e) {
                $this->logger()->error('supplier full product sync failed', [
                    'supplier_id' => $supplier->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $succeeded;
    }

    /**
     * 翻页拉取一个供应商的全部商品并落库。遇到空页、不满一页、跟上一页完全相同
     * （接口忽略页码）或达到页数上限时停止。
     *
     * @return int 拉到的商品条目数
     */
    public function syncSupplier(Supplier $supplier): int
    {
        $driver = $this->supplierDriverFactory->build($supplier);

        $total = 0;
        $previousPage = null;
        for ($page = 1; $page <= self::MAX_FULL_SYNC_PAGES; ++$page) {
            $entries = $driver->syncAllProducts($page, self::FULL_SYNC_PAGE_SIZE);
            if ($entries === [] || $entries === $previousPage) {
                break;
            }

            $this->applyFullSyncPage((int) $supplier->id, $entries);
            $total += count($entries);

            if (count($entries) < self::FULL_SYNC_PAGE_SIZE) {
                break;
            }
            if ($page === self::MAX_FULL_SYNC_PAGES) {
                $this->logger()->warning('supplier full product sync stopped at page limit', [
                    'supplier_id' => $supplier->id,
                    'pages' => $page,
                ]);
            }
            $previousPage = $entries;
        }

        $this->logger()->info('supplier full product sync finished', [
            'supplier_id' => $supplier->id,
            'entries' => $total,
        ]);

        return $total;
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
