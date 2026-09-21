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

namespace App\Service\Admin;

use App\Dao\ProductDao;
use App\Dao\SupplierCircuitBreakerDao;
use App\Dao\SupplierDao;
use App\Dao\SupplierProductDao;
use App\Model\SupplierCircuitBreaker;
use App\Service\AbstractService;
use App\Service\Supplier\CircuitBreakerService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统管理后台「供应商管理 - 熔断状态」（requirements.md 6.6），docs/modules.md 第 8 节。
 *
 * 判定、暂停、恢复的逻辑都在 App\Service\Supplier\CircuitBreakerService，这里只做
 * 后台侧的参数校验、组装展示数据（商品名、当前阈值）和权限边界。
 *
 * 手动暂停的时长上限 `MAX_PAUSE_MINUTES` 是 24 小时：再长的"暂停"实质上是"停用这家
 * 供应商"，应该走供应商启停（`supplier.status`）或把商品映射停掉，而不是挂一个几天后
 * 才到期、谁也不会记得的熔断。需要无限期时传 `minutes = null`，那是明确的人工决定，
 * 后台列表里会显示成"手动暂停（无限期）"，不会被误当成自动熔断。
 */
class CircuitBreakerAdminService extends AbstractService
{
    private const MAX_PAUSE_MINUTES = 1440;

    private const MAX_REMARK_LENGTH = 200;

    #[Inject]
    protected SupplierCircuitBreakerDao $breakerDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected SupplierProductDao $supplierProductDao;

    #[Inject]
    protected CircuitBreakerService $circuitBreakerService;

    /**
     * 某供应商的熔断状态：当前阈值 + 全部熔断行（含已恢复的，保留历史见
     * App\Model\SupplierCircuitBreaker 类注释）+ 这家供应商映射了哪些商品
     * （给手动暂停的商品下拉用）。
     *
     * @return array<string, mixed>
     */
    public function forSupplier(int $supplierId): array
    {
        $this->findSupplierOrFail($supplierId);

        $rows = $this->breakerDao->listForSupplier($supplierId);
        $productNames = $this->productNames($rows->pluck('product_id')->all());

        return [
            'thresholds' => $this->circuitBreakerService->thresholds(),
            // 整个供应商此刻是不是被熔断了（不看单个商品的行）
            'supplier_paused' => $this->circuitBreakerService->isPaused($supplierId),
            'data' => $rows->map(function (SupplierCircuitBreaker $row) use ($productNames) {
                $isAll = $row->product_id === SupplierCircuitBreaker::PRODUCT_ID_ALL;

                return [
                    'id' => $row->id,
                    'product_id' => $isAll ? null : $row->product_id,
                    'product_name' => $isAll ? null : ($productNames[$row->product_id] ?? null),
                    // 库里的 status 可能是"已过期但还没被定时任务写回"，展示按实时判断为准
                    'status' => $row->isPausedNow() ? SupplierCircuitBreaker::STATUS_PAUSED : SupplierCircuitBreaker::STATUS_NORMAL,
                    'stored_status' => $row->status,
                    'paused_until' => $row->paused_until?->toDateTimeString(),
                    'manual' => $row->triggered_reason !== null
                        && str_starts_with($row->triggered_reason, CircuitBreakerService::MANUAL_REASON_PREFIX),
                    'triggered_reason' => $row->triggered_reason,
                    'updated_at' => $row->updated_at?->toDateTimeString(),
                ];
            })->values()->all(),
            'mapped_products' => $this->mappedProducts($supplierId),
        ];
    }

    /**
     * @param array<string, mixed> $payload product_id（可选，缺省=整个供应商）/ minutes（可选，null=无限期）/ remark
     * @return array<string, mixed>
     */
    public function pause(int $supplierId, array $payload): array
    {
        $this->findSupplierOrFail($supplierId);
        $productId = $this->validateProductId($supplierId, $payload);

        $remark = is_string($payload['remark'] ?? null) ? trim($payload['remark']) : '';
        if ($remark === '') {
            throw new HttpException(422, 'remark 不能为空，请写明暂停原因');
        }
        if (mb_strlen($remark) > self::MAX_REMARK_LENGTH) {
            throw new HttpException(422, 'remark 最多 ' . self::MAX_REMARK_LENGTH . ' 个字');
        }

        $minutes = $this->validateMinutes($payload);
        $this->circuitBreakerService->pauseManually($supplierId, $productId, $minutes, $remark);

        return $this->forSupplier($supplierId);
    }

    /**
     * @param array<string, mixed> $payload product_id（可选，缺省=整个供应商）
     * @return array<string, mixed>
     */
    public function resume(int $supplierId, array $payload): array
    {
        $this->findSupplierOrFail($supplierId);
        $productId = $this->validateProductId($supplierId, $payload);

        if ($this->breakerDao->findScope($supplierId, $productId) === null) {
            throw new HttpException(404, '这个范围没有熔断记录');
        }

        $this->circuitBreakerService->resumeManually($supplierId, $productId);

        return $this->forSupplier($supplierId);
    }

    /**
     * 商品维度的熔断只对这家供应商真正映射了的商品有意义——给一个没映射的商品建熔断行，
     * 路由根本不会走到那个组合，只会在后台留下一条永远不生效的记录。
     *
     * @param array<string, mixed> $payload
     */
    private function validateProductId(int $supplierId, array $payload): int
    {
        $productId = $payload['product_id'] ?? null;
        if ($productId === null || $productId === '' || (int) $productId === SupplierCircuitBreaker::PRODUCT_ID_ALL) {
            return SupplierCircuitBreaker::PRODUCT_ID_ALL;
        }
        if (! is_numeric($productId)) {
            throw new HttpException(422, 'product_id 不合法');
        }

        $productId = (int) $productId;
        if ($this->supplierProductDao->findMapping($supplierId, $productId) === null) {
            throw new HttpException(422, '这个商品没有映射到该供应商，不能单独熔断');
        }

        return $productId;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateMinutes(array $payload): ?int
    {
        $minutes = $payload['minutes'] ?? null;
        if ($minutes === null || $minutes === '') {
            return null;
        }
        if (! is_numeric($minutes) || (int) $minutes < 1 || (int) $minutes > self::MAX_PAUSE_MINUTES) {
            throw new HttpException(422, 'minutes 必须是 1~' . self::MAX_PAUSE_MINUTES . ' 的整数，留空表示无限期暂停');
        }

        return (int) $minutes;
    }

    private function findSupplierOrFail(int $supplierId): void
    {
        if ($this->supplierDao->find($supplierId) === null) {
            throw new HttpException(404, '供应商不存在');
        }
    }

    /**
     * @param list<int> $productIds
     * @return array<int, string>
     */
    private function productNames(array $productIds): array
    {
        $ids = array_values(array_filter(array_unique($productIds), static fn (int $id) => $id !== SupplierCircuitBreaker::PRODUCT_ID_ALL));
        if ($ids === []) {
            return [];
        }

        return $this->productDao->newQuery()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function mappedProducts(int $supplierId): array
    {
        $productIds = $this->supplierProductDao->newQuery()
            ->where('supplier_id', $supplierId)
            ->pluck('product_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        if ($productIds === []) {
            return [];
        }

        return $this->productDao->newQuery()
            ->whereIn('id', $productIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn ($product) => ['id' => (int) $product->id, 'name' => (string) $product->name])
            ->values()
            ->all();
    }
}
