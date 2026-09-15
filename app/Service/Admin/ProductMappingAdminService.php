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
use App\Dao\SupplierDao;
use App\Dao\SupplierProductDao;
use App\Model\Product;
use App\Model\Supplier;
use App\Model\SupplierProduct;
use App\Service\AbstractService;
use Hyperf\Database\Exception\QueryException;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统管理后台（web/admin）「商品映射与成本价」（requirements.md 6.4），
 * docs/modules.md 第 8 节「供应商管理：商品映射」这一行。
 *
 * 这是 App\Model\SupplierProduct / App\Dao\SupplierProductDao 类注释里提到的、
 * 之前一直缺失的"创建映射行"功能——`App\Service\Supplier\ProductSyncService`
 * 负责的是已有映射行的成本价自动同步（`source=sync`），本 Service 负责映射行本身
 * 的增删改（`source=manual`），两者共用 `SupplierProductDao` 同一套"改价必留痕"
 * 事务逻辑（`SupplierProductDao::applySync()`/`updateCostPriceManually()` 都转发到
 * 同一个私有 `writeCostPrice()`），不可能出现某条改价路径漏记历史。
 *
 * 路由跳过（供应商商品暂停/禁售/库存为 0 时不参与路由，requirements.md 6.5）、
 * `sale_restrictions` 字段具体结构的校验/业务逻辑，均不在本次任务范围——本 Service
 * 对 `sale_restrictions` 只做"透传 JSON 对象"处理，不解释其内容（见
 * App\Controller\Admin\ProductMappingController 类注释）。
 */
class ProductMappingAdminService extends AbstractService
{
    private const ALLOWED_STATUSES = ['active', 'paused', 'banned'];

    private const DEFAULT_STATUS = 'active';

    #[Inject]
    protected SupplierProductDao $supplierProductDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    /**
     * 某个本地商品的全部供应商映射，按 `priority` 升序（跟 SupplierProductDao::listForProduct()
     * 的排序含义一致）。每行附带 supplier 的 name/code，方便前端展示，不用为每一行
     * 再单独查一次供应商——这里用"先查映射再按 supplier_id 批量查供应商"的方式，
     * 一次列表只发两条 SQL，不会随映射行数增长而 N+1。
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForProduct(int $productId): array
    {
        $this->findProductOrFail($productId);

        $mappings = $this->supplierProductDao->listForProduct($productId);

        $suppliers = $this->supplierDao->newQuery()
            ->whereIn('id', $mappings->pluck('supplier_id')->unique()->values()->all())
            ->get()
            ->keyBy('id');

        return $mappings
            ->map(fn (SupplierProduct $mapping) => $this->format($mapping, $suppliers->get($mapping->supplier_id)))
            ->values()
            ->all();
    }

    /**
     * 创建一条映射行。业务线一致性校验（"movie-only 供应商不能映射到 recharge 商品"）
     * 是本方法最核心的校验，直接源自 6.1「供应商只属于一条业务线」+ 6.4「本地商品
     * 关联到各供应商的商品」这两个概念的自然推论——6.4 原文没有逐字写出这条规则，
     * 但允许一个业务线不匹配的映射通过等于允许一个永远不可能真正下单成功的路由
     * 目标存在，属于本 Service 判断要在创建时就拒绝，而不是留到未来路由阶段才发现。
     *
     * @param array<string, mixed> $data
     */
    public function create(int $productId, array $data): SupplierProduct
    {
        $product = $this->findProductOrFail($productId);

        $supplierId = $this->requiredPositiveInt($data, 'supplier_id', 'supplier_id 不能为空');
        $supplier = $this->supplierDao->find($supplierId);
        if (! $supplier) {
            throw new HttpException(422, 'supplier_id 必须对应一个存在的供应商');
        }

        if ($supplier->business_line !== $product->business_line) {
            throw new HttpException(422, '供应商业务线与本地商品业务线不一致，无法映射');
        }

        $supplierProductCode = $this->requiredString($data, 'supplier_product_code', 'supplier_product_code 不能为空');
        $costPrice = $this->requiredNonNegativeDecimal($data, 'cost_price', 'cost_price 必须是非负数');
        $priority = $this->requiredNonNegativeInt($data, 'priority', 'priority 必须是非负整数');
        $status = $this->validateStatus($data['status'] ?? self::DEFAULT_STATUS);

        // 唯一性预检查：跟 App\Service\Merchant\AuthService::register()、
        // App\Service\Admin\SupplierAdminService::create() 对唯一字段的处理一样，
        // 先查一遍尽量给出干净的 422，下面 create() 里再 catch 一次数据库唯一约束
        // 异常作为并发场景下的兜底防线（(product_id, supplier_id) 唯一）。
        if ($this->supplierProductDao->newQuery()
            ->where('product_id', $productId)
            ->where('supplier_id', $supplierId)
            ->exists()) {
            throw new HttpException(422, '该本地商品已经映射过这个供应商');
        }

        try {
            return $this->supplierProductDao->create([
                'product_id' => $productId,
                'supplier_id' => $supplierId,
                'supplier_product_code' => $supplierProductCode,
                'cost_price' => $costPrice,
                'priority' => $priority,
                'status' => $status,
                'stock' => $this->nullableInt($data['stock'] ?? null),
                'param_mapping' => $this->nullableJsonObject($data['param_mapping'] ?? null, 'param_mapping'),
                'sale_restrictions' => $this->nullableJsonObject($data['sale_restrictions'] ?? null, 'sale_restrictions'),
            ]);
        } catch (QueryException $e) {
            throw new HttpException(422, '该本地商品已经映射过这个供应商', 0, $e);
        }
    }

    /**
     * 人工改价（requirements.md 6.4「成本价每次变化都记历史」），转发到
     * SupplierProductDao::updateCostPriceManually()，`source='manual'`。这是本
     * Service 里唯一允许改 cost_price 的方法，跟下面 updatePriority()/setStatus()/
     * updateDetails() 完全独立，避免"改价"这个必须留痕的动作被某个更通用的更新
     * 方法顺手绕过去。
     */
    public function updateCostPrice(int $id, string $newCostPrice): void
    {
        $mapping = $this->findOrFail($id);
        $costPrice = $this->validateNonNegativeDecimal($newCostPrice, 'cost_price 必须是非负数');

        $this->supplierProductDao->updateCostPriceManually($mapping, $costPrice);
    }

    /**
     * 优先级调整，纯字段更新，不涉及历史记录（priority 不是价格，requirements.md 6.4
     * 只要求"成本价每次变化都记历史"，没有对优先级提出同样的要求）。
     */
    public function updatePriority(int $id, int $priority): void
    {
        if ($priority < 0) {
            throw new HttpException(422, 'priority 必须是非负整数');
        }

        $mapping = $this->findOrFail($id);
        $mapping->fill(['priority' => $priority])->save();
    }

    /**
     * 启用/暂停/禁售，独立的小方法，跟 App\Service\Admin\SupplierAdminService::setStatus()
     * 同样的道理——状态切换不塞进通用更新校验里。
     */
    public function setStatus(int $id, mixed $status): void
    {
        $mapping = $this->findOrFail($id);
        $mapping->fill(['status' => $this->validateStatus($status)])->save();
    }

    /**
     * 映射详情里除了 cost_price/priority/status 之外的其它字段——
     * supplier_product_code（供应商侧编码可能需要人工订正）、param_mapping
     * （下单参数映射）、stock（人工录入库存的供应商可能需要手动改）、
     * sale_restrictions（透传 JSON，本 Service 不解释其内容，见类注释）。
     * 只处理调用方实际传入的字段（`array_key_exists`），不传视为不改；刻意
     * 不接受 cost_price/priority/status——那三个字段各有专门的方法，混进这个
     * 通用更新方法会让"改价必留痕"这条规则出现一个可以绕过去的后门。
     *
     * @param array<string, mixed> $data
     */
    public function updateDetails(int $id, array $data): SupplierProduct
    {
        $mapping = $this->findOrFail($id);

        $attributes = [];

        if (array_key_exists('supplier_product_code', $data)) {
            $attributes['supplier_product_code'] = $this->requiredString(
                $data,
                'supplier_product_code',
                'supplier_product_code 不能为空'
            );
        }

        if (array_key_exists('stock', $data)) {
            $attributes['stock'] = $this->nullableInt($data['stock']);
        }

        if (array_key_exists('param_mapping', $data)) {
            $attributes['param_mapping'] = $this->nullableJsonObject($data['param_mapping'], 'param_mapping');
        }

        if (array_key_exists('sale_restrictions', $data)) {
            $attributes['sale_restrictions'] = $this->nullableJsonObject($data['sale_restrictions'], 'sale_restrictions');
        }

        if ($attributes !== []) {
            $mapping->fill($attributes)->save();
        }

        return $mapping;
    }

    private function findProductOrFail(int $productId): Product
    {
        $product = $this->productDao->find($productId);
        if (! $product) {
            throw new HttpException(404, '本地商品不存在');
        }

        return $product;
    }

    private function findOrFail(int $id): SupplierProduct
    {
        $mapping = $this->supplierProductDao->find($id);
        if (! $mapping) {
            throw new HttpException(404, '映射行不存在');
        }

        return $mapping;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(SupplierProduct $mapping, ?Supplier $supplier): array
    {
        return [
            'id' => $mapping->id,
            'product_id' => $mapping->product_id,
            'supplier_id' => $mapping->supplier_id,
            'supplier_name' => $supplier?->name,
            'supplier_code' => $supplier?->code,
            'supplier_product_code' => $mapping->supplier_product_code,
            'cost_price' => $mapping->cost_price,
            'priority' => $mapping->priority,
            'status' => $mapping->status,
            'stock' => $mapping->stock,
            'param_mapping' => $mapping->param_mapping,
            'sale_restrictions' => $mapping->sale_restrictions,
            'synced_at' => $mapping->synced_at?->toDateTimeString(),
            'created_at' => $mapping->created_at?->toDateTimeString(),
            'updated_at' => $mapping->updated_at?->toDateTimeString(),
        ];
    }

    private function validateStatus(mixed $status): string
    {
        if (! is_string($status) || ! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new HttpException(422, 'status 必须是以下之一：' . implode('/', self::ALLOWED_STATUSES));
        }

        return $status;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredString(array $data, string $field, string $message): string
    {
        $value = trim((string) ($data[$field] ?? ''));
        if ($value === '') {
            throw new HttpException(422, $message);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredPositiveInt(array $data, string $field, string $message): int
    {
        $value = $data[$field] ?? null;
        if (! is_numeric($value) || (int) $value != $value || (int) $value <= 0) {
            throw new HttpException(422, $message);
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredNonNegativeInt(array $data, string $field, string $message): int
    {
        $value = $data[$field] ?? null;
        if (! is_numeric($value) || (int) $value != $value || (int) $value < 0) {
            throw new HttpException(422, $message);
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredNonNegativeDecimal(array $data, string $field, string $message): string
    {
        return $this->validateNonNegativeDecimal($data[$field] ?? null, $message);
    }

    /**
     * cost_price 是 decimal(10,2) 列，JSON 请求体里数字会被解成 PHP int/float 而不是
     * 字符串，用 bccomp 跟 '0' 比较而不是转 float，避免浮点误差；跟
     * App\Service\Admin\SupplierAdminService::nullableDecimal() 同样的坑。
     */
    private function validateNonNegativeDecimal(mixed $value, string $message): string
    {
        if ($value === null || $value === '' || (! is_int($value) && ! is_float($value) && ! is_string($value))) {
            throw new HttpException(422, $message);
        }

        if (! is_numeric($value) || bccomp((string) $value, '0', 2) < 0) {
            throw new HttpException(422, $message);
        }

        return (string) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new HttpException(422, 'stock 必须是整数或留空表示不限');
        }

        return (int) $value;
    }

    /**
     * param_mapping / sale_restrictions 的透传校验：不是本次任务的业务规则范围
     * （见类注释），只要求"是个可以编码成 JSON 对象的数组"，不解释其内容；
     * 没传或传 null 都视为不设置。
     *
     * @return null|array<string, mixed>
     */
    private function nullableJsonObject(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new HttpException(422, $field . ' 必须是一个 JSON 对象');
        }

        return $value;
    }
}
