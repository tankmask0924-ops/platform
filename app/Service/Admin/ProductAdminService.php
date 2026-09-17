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

use App\Dao\MerchantLevelDao;
use App\Dao\ProductDao;
use App\Dao\ProductLevelRebateDao;
use App\Model\Product;
use App\Model\ProductLevelRebate;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统管理后台（web/admin）「本地商品库：CRUD + 各等级比例覆盖」（requirements.md 5.2/8.3），
 * docs/modules.md 第 8 节。
 *
 * 这是 App\Model\Product/App\Dao\ProductDao（commit 233d5d9）此前一直缺失的写入侧——
 * 那次提交只建了模型和只读查询（开放 API 商品列表用的 `listOnShelfByBusinessLine()`），
 * 商品行此前只能靠测试直接用 Dao 插进去，运营完全没有入口在后台新建一个真实商品。
 * 结构跟 App\Service\Admin\MerchantLevelAdminService（commit 4de61e8，同一形状的
 * 「配置实体 CRUD + 按 (entity_id, level_id) 唯一约束的比例覆盖 upsert」问题）保持
 * 一致：比例覆盖写入用这个 Hyperf 版本的原生 upsert（ProductLevelRebateDao::upsertRate()，
 * 技术上跟 MerchantLevelBusinessRateDao::upsertRate() 完全一样），详情接口同样要
 * 区分"没有单独设置"（不返回该等级）与"单独设置成 0"（返回 rebate_rate = '0.0000'）。
 *
 * 两个权限编码：
 * - `product.view`：看列表/详情（含各等级比例覆盖）；
 * - `product.manage`：新建/修改商品、上下架、设置/删除等级比例覆盖——直接影响
 *   之后所有该商品订单的售价和返佣金额。
 * 已同步维护 App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS。
 *
 * 范围说明：
 * - `business_line` 只接受 recharge/card：这个代码库目前只有话费、卡券两条业务线
 *   建了本地商品表和下单路由基础设施（见 App\Model\Product 类注释），即使数据库列
 *   本身不限制取值，这里也主动拒绝其它值，避免造出一个永远无法被任何驱动/下单
 *   流程消费的商品行；
 * - 不含 5.5 的价格/返佣保护提示（低于成本价、毛利为负、返佣比例超 100% 等）：
 *   跟 MerchantLevelAdminService 排除同样理由，没有后台前端来展示这些"提示"，
 *   而且是建议性质，不是硬性校验规则；
 * - 不含操作日志：这个代码库目前没有任何操作日志基础设施，是独立的后续工作；
 * - 不含 supplier_products/商品映射：那是已经建好的独立功能
 *   （App\Controller\Admin\ProductMappingController，commit 67c555b），本 Service
 *   只管本地 Product 实体本身和它的等级比例覆盖。
 *
 * requirements.md 5.3「修改商品返佣金额、商品单独设置的比例或等级比例，只影响
 * 之后下的订单」在这个代码库里天然成立，不需要额外实现：下单时
 * RebateCalculator 计算出的返佣金额被快照进订单/返佣记录，改商品/改比例都不会
 * 回溯任何已有记录。
 */
class ProductAdminService extends AbstractService
{
    /**
     * 业务线（requirements.md 5.1/8.3「话费、卡券商品」），跟迁移文件
     * products.business_line 列注释一致。见类注释「为什么只有这两个」。
     */
    private const ALLOWED_BUSINESS_LINES = ['recharge', 'card'];

    private const ALLOWED_OPERATORS = ['mobile', 'unicom', 'telecom'];

    private const ALLOWED_CHARGE_SPEEDS = ['fast', 'slow'];

    private const ALLOWED_CARD_TYPES = ['direct', 'card_secret'];

    private const ALLOWED_STATUSES = ['on_shelf', 'off_shelf'];

    /**
     * 新建商品默认下架（requirements.md 8.3「上下架」，本 Service 的取舍）：一个刚
     * 建好、字段可能还没配置齐全（比如还没设置任何等级比例覆盖）的商品不应该被
     * 意外立即上架给商户购买，运营确认无误后需要显式调用状态切换接口上架。
     */
    private const DEFAULT_STATUS = 'off_shelf';

    /**
     * products.name 列宽 128（migrations/2026_09_14_091300_create_products_table.php）。
     */
    private const NAME_MAX_LENGTH = 128;

    /**
     * products.province 列宽 32。
     */
    private const PROVINCE_MAX_LENGTH = 32;

    /**
     * products.applicable_region 列宽 64。
     */
    private const APPLICABLE_REGION_MAX_LENGTH = 64;

    /**
     * product_level_rebates.rebate_rate 是 decimal(6,4)：最多 4 位小数，整数部分
     * 最多 2 位，能存的最大值是 99.9999。跟 MerchantLevelAdminService::RATE_MAX
     * 同样的列约束。
     */
    private const RATE_MAX = '99.9999';

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected ProductLevelRebateDao $productLevelRebateDao;

    #[Inject]
    protected MerchantLevelDao $merchantLevelDao;

    /**
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(int $page, int $perPage, ?string $businessLine, ?string $status): array
    {
        if ($businessLine !== null) {
            $this->validateBusinessLine($businessLine);
        }
        if ($status !== null) {
            $this->validateStatus($status);
        }

        $products = $this->productDao->paginate($page, $perPage, $businessLine, $status);

        return [
            'data' => $products->map(fn (Product $product) => $this->formatProduct($product))->values()->all(),
            'total' => $this->productDao->count($businessLine, $status),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 商品字段 + 已单独设置的等级比例覆盖列表（只包含设置过的等级，附带 level_name
     * 方便前端展示——跟 App\Service\Admin\ProductMappingAdminService::listForProduct()
     * 联表带出 supplier name/code 同样的理由）。没有任何覆盖时 `level_rebates` 是
     * 空数组，不是 null 或报错。
     *
     * @return array<string, mixed>
     */
    public function detail(int $id): array
    {
        $product = $this->findOrFail($id);

        return $this->formatProduct($product) + ['level_rebates' => $this->listLevelRebates($product->id)];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $businessLine = $this->validateBusinessLine($data['business_line'] ?? null);
        $name = $this->validateName($data['name'] ?? null);
        $faceValue = $this->requiredNonNegativeDecimal($data, 'face_value');
        $salePrice = $this->requiredNonNegativeDecimal($data, 'sale_price');
        $rebateAmount = $this->requiredNonNegativeDecimal($data, 'rebate_amount');
        $status = array_key_exists('status', $data) ? $this->validateStatus($data['status']) : self::DEFAULT_STATUS;
        $province = $this->nullableString($data['province'] ?? null, 'province', self::PROVINCE_MAX_LENGTH);
        $applicableRegion = $this->nullableString(
            $data['applicable_region'] ?? null,
            'applicable_region',
            self::APPLICABLE_REGION_MAX_LENGTH
        );

        $conditionalFields = $this->resolveConditionalFields($businessLine, $data, true);

        $product = $this->productDao->create(array_merge([
            'business_line' => $businessLine,
            'name' => $name,
            'province' => $province,
            'face_value' => $faceValue,
            'sale_price' => $salePrice,
            'rebate_amount' => $rebateAmount,
            'applicable_region' => $applicableRegion,
            'status' => $status,
        ], $conditionalFields));

        return $this->detail($product->id);
    }

    /**
     * 只更新 payload 里带了的字段，跟 App\Service\Admin\SupplierAdminService::update()
     * 同样的"部分更新"约定。`business_line` 允许在更新时一并改变（没有像
     * SupplierAdminService 的 `code` 那样禁止修改的理由），条件字段（operator/
     * charge_speed/card_type）按"改变后生效的 business_line"校验——即如果本次
     * 请求也带了新的 business_line，就按新值校验，否则按商品当前的 business_line。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $product = $this->findOrFail($id);

        $attributes = [];

        $businessLine = $product->business_line;
        if (array_key_exists('business_line', $data)) {
            $businessLine = $this->validateBusinessLine($data['business_line']);
            $attributes['business_line'] = $businessLine;
        }

        if (array_key_exists('name', $data)) {
            $attributes['name'] = $this->validateName($data['name']);
        }

        if (array_key_exists('face_value', $data)) {
            $attributes['face_value'] = $this->requiredNonNegativeDecimal($data, 'face_value');
        }

        if (array_key_exists('sale_price', $data)) {
            $attributes['sale_price'] = $this->requiredNonNegativeDecimal($data, 'sale_price');
        }

        if (array_key_exists('rebate_amount', $data)) {
            $attributes['rebate_amount'] = $this->requiredNonNegativeDecimal($data, 'rebate_amount');
        }

        if (array_key_exists('status', $data)) {
            $attributes['status'] = $this->validateStatus($data['status']);
        }

        if (array_key_exists('province', $data)) {
            $attributes['province'] = $this->nullableString($data['province'], 'province', self::PROVINCE_MAX_LENGTH);
        }

        if (array_key_exists('applicable_region', $data)) {
            $attributes['applicable_region'] = $this->nullableString(
                $data['applicable_region'],
                'applicable_region',
                self::APPLICABLE_REGION_MAX_LENGTH
            );
        }

        $attributes = array_merge($attributes, $this->resolveConditionalFields($businessLine, $data, false));

        if ($attributes !== []) {
            $product->fill($attributes)->save();
        }

        return $this->detail($product->id);
    }

    /**
     * 上架/下架，独立的小方法，不塞进通用 update() 校验里——跟
     * App\Service\Admin\SupplierAdminService::setStatus() 同样的道理。
     */
    public function setStatus(int $id, mixed $status): void
    {
        $product = $this->findOrFail($id);

        $product->fill(['status' => $this->validateStatus($status)])->save();
    }

    /**
     * 设置（新建或原地更新）某商品对某等级单独设置的返佣比例（requirements.md
     * 5.2「话费、卡券的商品可以单独调整某个等级的比例，覆盖等级的设置」）。
     *
     * @return array<string, mixed>
     */
    public function setLevelRebate(int $id, int $levelId, mixed $rebateRate): array
    {
        $this->findOrFail($id);
        $this->findLevelOrFail($levelId);
        $rate = $this->validateRate($rebateRate);

        $row = $this->productLevelRebateDao->upsertRate($id, $levelId, $rate);

        return [
            'product_id' => $row->product_id,
            'level_id' => $row->level_id,
            'rebate_rate' => $row->rebate_rate,
            'updated_at' => $row->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * 删除某商品对某等级单独设置的返佣比例，回退到该等级在该业务线的默认比例
     * （requirements.md 5.2「没设置的等级继续用等级比例」）。这是一个有实际业务
     * 含义的操作，不是简单的"清理"，所以对着一个本来就不存在的覆盖行调用要
     * 返回 404，而不是静默当作成功——静默成功会掩盖调用方"以为这个等级本来有
     * 单独设置"的错误假设。
     */
    public function deleteLevelRebate(int $id, int $levelId): void
    {
        $this->findOrFail($id);
        $this->findLevelOrFail($levelId);

        if ($this->productLevelRebateDao->findForProductAndLevel($id, $levelId) === null) {
            throw new HttpException(404, '该商品未对此等级单独设置比例');
        }

        $this->productLevelRebateDao->deleteForProductAndLevel($id, $levelId);
    }

    private function findOrFail(int $id): Product
    {
        $product = $this->productDao->find($id);
        if (! $product) {
            throw new HttpException(404, '本地商品不存在');
        }

        return $product;
    }

    private function findLevelOrFail(int $levelId): void
    {
        if (! $this->merchantLevelDao->find($levelId)) {
            throw new HttpException(404, '商户等级不存在');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatProduct(Product $product): array
    {
        return [
            'id' => $product->id,
            'business_line' => $product->business_line,
            'name' => $product->name,
            'operator' => $product->operator,
            'province' => $product->province,
            'charge_speed' => $product->charge_speed,
            'card_type' => $product->card_type,
            'face_value' => $product->face_value,
            'sale_price' => $product->sale_price,
            'rebate_amount' => $product->rebate_amount,
            'applicable_region' => $product->applicable_region,
            'status' => $product->status,
            'created_at' => $product->created_at?->toDateTimeString(),
            'updated_at' => $product->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * 联表带出 level_name，一次列表最多两条 SQL（覆盖行 + 批量查等级），不随
     * 覆盖行数增长而 N+1，跟 ProductMappingAdminService::listForProduct() 联表
     * 供应商 name/code 同样的做法。
     *
     * @return array<int, array<string, mixed>>
     */
    private function listLevelRebates(int $productId): array
    {
        $overrides = $this->productLevelRebateDao->listForProduct($productId);
        if ($overrides->isEmpty()) {
            return [];
        }

        $levels = $this->merchantLevelDao->newQuery()
            ->whereIn('id', $overrides->pluck('level_id')->unique()->values()->all())
            ->get()
            ->keyBy('id');

        return $overrides
            ->map(fn (ProductLevelRebate $override) => [
                'level_id' => $override->level_id,
                'level_name' => $levels->get($override->level_id)?->name,
                'rebate_rate' => $override->rebate_rate,
            ])
            ->values()
            ->all();
    }

    /**
     * 按 business_line 决定 operator/charge_speed/card_type 三个条件字段的取值，
     * 校验规则（requirements.md 8.3「运营商/品牌...类型（直充/卡密）」+ 本任务的
     * "拒绝供错业务线的字段"取舍）：
     * - recharge：operator 必填（枚举），charge_speed 可选（给了就校验枚举，
     *   给 null 表示不设置），card_type 不适用，一旦传了直接 422；
     * - card：card_type 必填（枚举），operator/charge_speed 都不适用，一旦传了
     *   直接 422。
     *
     * `$isCreate` 区分两种模式：
     * - true（新建）：必填字段即使 payload 没给也要报错；不适用的字段固定清成
     *   null 写入（比如 card 商品的 operator/charge_speed 总是 null）；
     * - false（更新）：只处理 payload 里实际带了的 key，没带的字段维持数据库里
     *   原值不动——不能因为"这个字段在 recharge 下必填"就要求每次更新都必须
     *   重新传一遍，那样就不是"部分更新"了。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed> 待写入的属性（只包含需要写的 key）
     */
    private function resolveConditionalFields(string $businessLine, array $data, bool $isCreate): array
    {
        $attributes = [];

        $hasOperator = array_key_exists('operator', $data);
        $hasChargeSpeed = array_key_exists('charge_speed', $data);
        $hasCardType = array_key_exists('card_type', $data);

        if ($businessLine === 'recharge') {
            if ($hasCardType) {
                throw new HttpException(422, 'card_type 不适用于 recharge 商品');
            }

            if ($isCreate || $hasOperator) {
                $attributes['operator'] = $this->validateEnum(
                    $data['operator'] ?? null,
                    self::ALLOWED_OPERATORS,
                    'operator'
                );
            }

            if ($hasChargeSpeed) {
                $attributes['charge_speed'] = $data['charge_speed'] === null
                    ? null
                    : $this->validateEnum($data['charge_speed'], self::ALLOWED_CHARGE_SPEEDS, 'charge_speed');
            } elseif ($isCreate) {
                $attributes['charge_speed'] = null;
            }

            if ($isCreate) {
                $attributes['card_type'] = null;
            }

            return $attributes;
        }

        // business_line === 'card'
        if ($hasOperator) {
            throw new HttpException(422, 'operator 不适用于 card 商品');
        }
        if ($hasChargeSpeed) {
            throw new HttpException(422, 'charge_speed 不适用于 card 商品');
        }

        if ($isCreate || $hasCardType) {
            $attributes['card_type'] = $this->validateEnum($data['card_type'] ?? null, self::ALLOWED_CARD_TYPES, 'card_type');
        }

        if ($isCreate) {
            $attributes['operator'] = null;
            $attributes['charge_speed'] = null;
        }

        return $attributes;
    }

    /**
     * @param string[] $allowed
     */
    private function validateEnum(mixed $value, array $allowed, string $field): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new HttpException(422, $field . ' 必须是以下之一：' . implode('/', $allowed));
        }

        return $value;
    }

    private function validateBusinessLine(mixed $businessLine): string
    {
        return $this->validateEnum($businessLine, self::ALLOWED_BUSINESS_LINES, 'business_line');
    }

    private function validateStatus(mixed $status): string
    {
        return $this->validateEnum($status, self::ALLOWED_STATUSES, 'status');
    }

    private function validateName(mixed $name): string
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') {
            throw new HttpException(422, 'name 不能为空');
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new HttpException(422, 'name 不能超过 ' . self::NAME_MAX_LENGTH . ' 个字符');
        }

        return $name;
    }

    private function nullableString(mixed $value, string $field, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new HttpException(422, $field . ' 必须是字符串');
        }

        $value = trim($value);
        if (mb_strlen($value) > $maxLength) {
            throw new HttpException(422, $field . ' 不能超过 ' . $maxLength . ' 个字符');
        }

        return $value === '' ? null : $value;
    }

    /**
     * face_value/sale_price/rebate_amount 都是 decimal(10,2) 列，JSON 请求体里的
     * 数字会被解成 PHP int/float 而不是字符串，用 bccomp 跟 '0' 比较而不是转
     * float，避免浮点误差——跟 App\Service\Admin\ProductMappingAdminService::
     * validateNonNegativeDecimal() 处理 cost_price 同样的坑。
     *
     * @param array<string, mixed> $data
     */
    private function requiredNonNegativeDecimal(array $data, string $field): string
    {
        $value = $data[$field] ?? null;
        if ($value === null || $value === '' || (! is_int($value) && ! is_float($value) && ! is_string($value))) {
            throw new HttpException(422, $field . ' 必须是非负数');
        }

        if (! is_numeric($value) || bccomp((string) $value, '0', 2) < 0) {
            throw new HttpException(422, $field . ' 必须是非负数');
        }

        return (string) $value;
    }

    /**
     * JSON 请求体里的数字会被解成 int/float，字符串形式也接受；统一转成字符串后
     * 用正则校验成「非负、最多 4 位小数」的普通十进制写法（拒绝负数、科学计数法等）。
     * 跟 App\Service\Admin\MerchantLevelAdminService::validateRate() 完全一样的
     * 校验逻辑（同一列宽 decimal(6,4)）。
     */
    private function validateRate(mixed $rate): string
    {
        if (is_int($rate) || is_float($rate)) {
            if ($rate < 0) {
                throw new HttpException(422, 'rebate_rate 不能为负数');
            }
            $rate = is_float($rate) ? rtrim(rtrim(sprintf('%.10F', $rate), '0'), '.') : (string) $rate;
        }

        if (! is_string($rate) || trim($rate) === '') {
            throw new HttpException(422, 'rebate_rate 不能为空，且必须是数字');
        }

        $rate = trim($rate);
        if (str_starts_with($rate, '-') && is_numeric($rate)) {
            throw new HttpException(422, 'rebate_rate 不能为负数');
        }

        if (preg_match('/^\d+(\.\d{1,4})?$/', $rate) !== 1) {
            throw new HttpException(422, 'rebate_rate 必须是非负小数，最多 4 位小数');
        }

        if (bccomp($rate, self::RATE_MAX, 4) > 0) {
            throw new HttpException(422, 'rebate_rate 不能超过 ' . self::RATE_MAX);
        }

        return $rate;
    }
}
