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
use App\Dao\SupplierGoodsDao;
use App\Dao\SupplierProductDao;
use App\Model\Product;
use App\Model\Supplier;
use App\Model\SupplierGoods;
use App\Model\SupplierProduct;
use App\Service\AbstractService;
use App\Supplier\SupplierDriverFactory;
use Hyperf\Database\Exception\QueryException;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Throwable;

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
 * 映射一律从供应商商品库（`supplier_goods`）里选：供应商商品编码、成本价、状态、
 * 库存都从选中的供应商商品带过来，不再手填编码，保证本地商品关联的是供应商那边
 * 真实存在的商品。新增本地商品时一并选好（App\Service\Admin\ProductAdminService::create()
 * 调本类的 resolveGoods()/createFromGoods()），之后在商品详情里还能再加。
 *
 * 路由跳过（供应商商品暂停/禁售/库存为 0 时不参与路由，requirements.md 6.5）、
 * `sale_restrictions` 字段具体结构的校验/业务逻辑，均不在本次任务范围——本 Service
 * 对 `sale_restrictions` 只做"透传 JSON 对象"处理，不解释其内容（见
 * App\Controller\Admin\ProductMappingController 类注释）。
 */
class ProductMappingAdminService extends AbstractService
{
    private const ALLOWED_STATUSES = ['active', 'paused', 'banned'];

    /** 商品详情（下单模板、购买数量）查过之后多久内不再重查 */
    private const DETAIL_FRESH_SECONDS = 3600;

    /** 平台下单参数：充值账号（话费手机号、卡券直充账号） */
    private const ACCOUNT_PARAM = 'recharge_account';

    private const GOODS_TYPE_LABELS = [
        SupplierGoods::TYPE_CARD_SECRET => '卡密',
        SupplierGoods::TYPE_VIRTUAL => '直充',
    ];

    #[Inject]
    protected SupplierProductDao $supplierProductDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierGoodsDao $supplierGoodsDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    /**
     * 某个本地商品的全部供应商映射，按 `priority` 升序（跟 SupplierProductDao::listForProduct()
     * 的排序含义一致）。每行附带 supplier 的 name/code 和关联的供应商商品（名称、面值；
     * 供应商商品库里找不到时为 null，比如上线前手填编码建的老映射），方便前端展示，不用为每一行
     * 再单独查一次供应商——这里用"先查映射再按 supplier_id 批量查供应商"的方式，
     * 一次列表只发三条 SQL，不会随映射行数增长而 N+1。
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForProduct(int $productId): array
    {
        $this->findProductOrFail($productId);

        $mappings = $this->supplierProductDao->listForProduct($productId);

        $supplierIds = $mappings->pluck('supplier_id')->unique()->values()->all();
        $suppliers = $this->supplierDao->findMany($supplierIds);
        $goods = $this->supplierGoodsDao->listBySupplierCodes(
            $supplierIds,
            $mappings->pluck('supplier_product_code')->unique()->values()->all()
        )->keyBy(fn (SupplierGoods $g) => $g->supplier_id . ':' . $g->code);

        return $mappings
            ->map(fn (SupplierProduct $mapping) => $this->format(
                $mapping,
                $suppliers->get($mapping->supplier_id),
                $goods->get($mapping->supplier_id . ':' . $mapping->supplier_product_code)
            ))
            ->values()
            ->all();
    }

    /**
     * 给本地商品添加一条映射：从供应商商品库里选一个供应商商品（`supplier_goods_id`），
     * 编码、成本价、库存从它带过来；`status` 不传时也跟它一致。
     *
     * 业务线一致性校验（"话费商品不能映射到卡券供应商"）直接源自 6.1「供应商只属于
     * 一条业务线」+ 6.4「本地商品关联到各供应商的商品」——允许业务线不匹配的映射等于
     * 允许一个永远不可能下单成功的路由目标存在，在创建时就拒绝（见 resolveGoods()）。
     *
     * @param array<string, mixed> $data
     */
    public function create(int $productId, array $data): SupplierProduct
    {
        $product = $this->findProductOrFail($productId);

        $goodsId = $this->requiredPositiveInt($data, 'supplier_goods_id', 'supplier_goods_id 不能为空');
        $goods = $this->resolveGoods($product->business_line, $product->card_type, [$goodsId])[0];
        $priority = $this->requiredNonNegativeInt($data, 'priority', 'priority 必须是非负整数');
        $status = array_key_exists('status', $data) ? $this->validateStatus($data['status']) : null;

        // 唯一性预检查：先查一遍尽量给出干净的 422，createFromGoods() 里再 catch 一次
        // 数据库唯一约束异常作为并发场景下的兜底防线（(product_id, supplier_id) 唯一）。
        if ($this->supplierProductDao->findMapping($goods->supplier_id, $productId) !== null) {
            throw new HttpException(422, '该本地商品已经映射过这个供应商');
        }

        $mapping = $this->createFromGoods($productId, $goods, $priority, $status);

        $extra = [];
        if (array_key_exists('param_mapping', $data)) {
            $extra['param_mapping'] = $this->nullableJsonObject($data['param_mapping'], 'param_mapping');
        }
        if (array_key_exists('sale_restrictions', $data)) {
            $extra['sale_restrictions'] = $this->nullableJsonObject($data['sale_restrictions'], 'sale_restrictions');
        }
        if ($extra !== []) {
            $mapping->fill($extra)->save();
        }

        return $mapping;
    }

    /**
     * 本地商品要配哪种供应商商品：卡券里的卡密商品配卡密，话费和卡券直充都配虚拟商品（直充）。
     * 卡密和直充配反了一定下单失败或者拿不到卡密。
     */
    public static function requiredGoodsType(string $businessLine, ?string $cardType): string
    {
        return $businessLine === 'card' && $cardType !== 'direct' ? SupplierGoods::TYPE_CARD_SECRET : SupplierGoods::TYPE_VIRTUAL;
    }

    /**
     * 按 id 取一批供应商商品并校验能不能给这个本地商品用：都要存在、所属供应商的业务线一致、
     * 商品类型对得上（requiredGoodsType()；供应商没给类型的不拦）、下单模板和购买数量平台能满足
     * （paramMappingFor()，校验前先查一次商品详情，见 refreshDetail()）、同一个供应商只能选一个
     * （`(product_id, supplier_id)` 唯一）。返回顺序跟传入的 id 顺序一致（新增商品时这个顺序就是优先级顺序）。
     *
     * @param array<int, int> $goodsIds
     * @return array<int, SupplierGoods>
     */
    public function resolveGoods(string $businessLine, ?string $cardType, array $goodsIds): array
    {
        $goodsById = $this->supplierGoodsDao->findMany($goodsIds);
        $suppliers = $this->supplierDao->findMany($goodsById->pluck('supplier_id')->unique()->values()->all());

        $result = [];
        $seenSuppliers = [];
        foreach ($goodsIds as $goodsId) {
            $goods = $goodsById->get($goodsId);
            if (! $goods instanceof SupplierGoods) {
                throw new HttpException(422, '供应商商品不存在：#' . $goodsId);
            }

            $supplier = $suppliers->get($goods->supplier_id);
            if (! $supplier instanceof Supplier) {
                throw new HttpException(422, '供应商商品所属的供应商不存在：#' . $goodsId);
            }
            if ($supplier->business_line !== $businessLine) {
                throw new HttpException(422, '供应商业务线与本地商品业务线不一致，无法映射');
            }
            $goods = $this->refreshDetail($goods);
            $this->assertGoodsTypeMatches($goods, $businessLine, $cardType);
            $this->paramMappingFor($goods, $businessLine, $cardType);
            if (isset($seenSuppliers[$goods->supplier_id])) {
                throw new HttpException(422, '同一个供应商只能选一个商品：' . $supplier->name);
            }

            $seenSuppliers[$goods->supplier_id] = true;
            $result[] = $goods;
        }

        return $result;
    }

    /**
     * 用一个已经校验过的供应商商品建映射行（校验见 resolveGoods()）。`$status` 为
     * null 时跟供应商商品当前状态一致；下单参数映射按供应商商品的下单模板自动生成。
     */
    public function createFromGoods(int $productId, SupplierGoods $goods, int $priority, ?string $status = null): SupplierProduct
    {
        $product = $this->findProductOrFail($productId);

        try {
            return $this->supplierProductDao->create([
                'product_id' => $productId,
                'supplier_id' => $goods->supplier_id,
                'supplier_product_code' => $goods->code,
                'cost_price' => $goods->cost_price,
                'priority' => $priority,
                'status' => $status ?? $goods->status,
                'stock' => $goods->stock,
                'param_mapping' => $this->paramMappingFor($goods, $product->business_line, $product->card_type),
                'sale_restrictions' => $goods->sale_restrictions,
                'synced_at' => $goods->synced_at?->toDateTimeString(),
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
     * supplier_goods_id（换成同一个供应商的另一个商品）、param_mapping
     * （下单参数映射）、stock（人工录入库存的供应商可能需要手动改）、
     * sale_restrictions（透传 JSON，本 Service 不解释其内容，见类注释）。
     * 只处理调用方实际传入的字段（`array_key_exists`），不传视为不改；刻意
     * 不接受 cost_price/priority/status——那三个字段各有专门的方法，混进这个
     * 通用更新方法会让"改价必留痕"这条规则出现一个可以绕过去的后门。换供应商商品
     * 带过来的成本价也走 updateCostPriceManually()，照样留历史。
     *
     * @param array<string, mixed> $data
     */
    public function updateDetails(int $id, array $data): SupplierProduct
    {
        $mapping = $this->findOrFail($id);

        if (array_key_exists('supplier_product_code', $data)) {
            throw new HttpException(422, '供应商商品编码不能手填，请用 supplier_goods_id 从供应商商品库里选');
        }

        $attributes = [];

        $goods = null;
        if (array_key_exists('supplier_goods_id', $data)) {
            $goodsId = $this->requiredPositiveInt($data, 'supplier_goods_id', 'supplier_goods_id 不能为空');
            $goods = $this->supplierGoodsDao->find($goodsId);
            if (! $goods) {
                throw new HttpException(422, '供应商商品不存在：#' . $goodsId);
            }
            if ($goods->supplier_id !== $mapping->supplier_id) {
                throw new HttpException(422, '只能换成同一个供应商的商品');
            }
            $product = $this->findProductOrFail((int) $mapping->product_id);
            $goods = $this->refreshDetail($goods);
            $this->assertGoodsTypeMatches($goods, $product->business_line, $product->card_type);
            $attributes['param_mapping'] = $this->paramMappingFor($goods, $product->business_line, $product->card_type);
            $attributes['sale_restrictions'] = $goods->sale_restrictions;
            $attributes['supplier_product_code'] = $goods->code;
            $attributes['status'] = $goods->status;
            $attributes['stock'] = $goods->stock;
            $attributes['synced_at'] = $goods->synced_at?->toDateTimeString();
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

        Db::transaction(function () use ($mapping, $attributes, $goods) {
            if ($attributes !== []) {
                $mapping->fill($attributes)->save();
            }
            if ($goods !== null) {
                $this->supplierProductDao->updateCostPriceManually($mapping, $goods->cost_price);
            }
        });

        return $mapping;
    }

    /**
     * 建映射前查一次供应商商品详情（下单模板、购买数量只有详情接口有）。一小时内查过就不再查；
     * 查不到直接报错，不拿不知道模板的商品去建映射——模板对不上，上线后这个商品每单都会下单失败。
     */
    private function refreshDetail(SupplierGoods $goods): SupplierGoods
    {
        if ($goods->detail_synced_at !== null && $goods->detail_synced_at->getTimestamp() > time() - self::DETAIL_FRESH_SECONDS) {
            return $goods;
        }

        $supplier = $this->supplierDao->find((int) $goods->supplier_id);
        try {
            $detail = $this->supplierDriverFactory->build($supplier)->queryProductDetail($goods->code);
        } catch (Throwable $e) {
            throw new HttpException(502, sprintf('查询供应商商品「%s」的详情失败，请稍后重试：%s', $goods->name ?? $goods->code, $e->getMessage()), 0, $e);
        }

        return $this->supplierGoodsDao->upsertFromSync((int) $goods->supplier_id, $detail);
    }

    /**
     * 按供应商商品的下单模板生成映射行的 param_mapping（`{"recharge_account": "<模板里的字段名>"}`），
     * 平台满足不了的直接拒绝：
     * - 话费、卡券直充要传充值账号：模板里有 `recharge_account` 就用它；只有一个字段就填到那个字段；
     *   一个字段都没有，或者有多个字段却没有 `recharge_account`（平台只能提供充值账号这一个值）都不能用；
     * - 卡密商品不传参数：模板必须是空的；
     * - 最少要买 2 个以上的不能用：平台每单只买 1 个。
     *
     * @return null|array<string, string>
     */
    private function paramMappingFor(SupplierGoods $goods, string $businessLine, ?string $cardType): ?array
    {
        $label = '供应商商品「' . ($goods->name ?? $goods->code) . '」';
        if ($goods->min_quantity !== null && $goods->min_quantity > 1) {
            throw new HttpException(422, $label . '最少要买 ' . $goods->min_quantity . ' 个，平台每单只买 1 个，不能用');
        }

        $template = $goods->attach_template ?? [];
        $keys = array_values(array_map(static fn (array $field) => (string) $field['key'], $template));
        $names = array_map(static fn (array $field) => (string) ($field['name'] ?? $field['key']), $template);

        if (self::requiredGoodsType($businessLine, $cardType) === SupplierGoods::TYPE_CARD_SECRET) {
            if ($keys !== []) {
                throw new HttpException(422, $label . '下单要填参数（' . implode('、', $names) . '），不是卡密商品');
            }

            return null;
        }

        if (in_array(self::ACCOUNT_PARAM, $keys, true)) {
            return [self::ACCOUNT_PARAM => self::ACCOUNT_PARAM];
        }
        if (count($keys) === 1) {
            return [self::ACCOUNT_PARAM => $keys[0]];
        }
        if ($keys === []) {
            throw new HttpException(422, $label . '没有下单参数模板，传不了充值账号，不能用');
        }

        throw new HttpException(422, $label . '下单要填 ' . count($keys) . ' 个参数（' . implode('、', $names) . '），平台只能传充值账号，不能用');
    }

    private function assertGoodsTypeMatches(SupplierGoods $goods, string $businessLine, ?string $cardType): void
    {
        $required = self::requiredGoodsType($businessLine, $cardType);
        if ($goods->goods_type !== null && $goods->goods_type !== $required) {
            throw new HttpException(422, sprintf(
                '供应商商品「%s」是%s商品，这个本地商品要配%s商品',
                $goods->name ?? $goods->code,
                self::GOODS_TYPE_LABELS[$goods->goods_type] ?? $goods->goods_type,
                self::GOODS_TYPE_LABELS[$required]
            ));
        }
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
    private function format(SupplierProduct $mapping, ?Supplier $supplier, ?SupplierGoods $goods): array
    {
        return [
            'id' => $mapping->id,
            'product_id' => $mapping->product_id,
            'supplier_id' => $mapping->supplier_id,
            'supplier_name' => $supplier?->name,
            'supplier_code' => $supplier?->code,
            'supplier_product_code' => $mapping->supplier_product_code,
            'supplier_goods' => $goods === null ? null : [
                'id' => $goods->id,
                'name' => $goods->name,
                'face_value' => $goods->face_value,
                'goods_type' => $goods->goods_type,
                'attach_template' => $goods->attach_template,
                'min_quantity' => $goods->min_quantity,
                'can_back' => $goods->can_back,
            ],
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
