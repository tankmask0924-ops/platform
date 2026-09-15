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

use App\Crypto\Encryptor;
use App\Dao\SupplierDao;
use App\Model\Supplier;
use App\Service\AbstractService;
use Hyperf\Database\Exception\QueryException;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统管理后台（web/admin）「供应商管理 - 配置 CRUD」（requirements.md 6.3），
 * docs/modules.md 第 8 节。
 *
 * 只覆盖供应商配置本身的增删改查 + 启用禁用，不覆盖同一行里其它更大的子模块——
 * 商品映射、余额监控、熔断状态、调用日志、供应商统计，那些依赖的路由/同步/监控
 * 基础设施都还没建，是单独的、更大的后续工作（docs/modules.md 第 8 节把这行拆开
 * 标注，就是本任务改的部分）。也不负责真正拿供应商配置去构造/路由到具体的
 * `App\Supplier\Kasushou\KasushouDriver` 实例——那是订单路由（6.5 节），
 * 同样是后续工作，`KasushouDriver` 至今仍然是构造函数直接收 baseUrl/userId/apiKey，
 * 跟这里的数据库配置来源解耦（见 KasushouDriver 类注释）。
 */
class SupplierAdminService extends AbstractService
{
    /**
     * 已开发的驱动白名单（requirements.md 6.3「对接驱动：从已开发的驱动中选择」）。
     * 截至本任务，代码库里只有 App\Supplier\Kasushou\KasushouDriver 一个驱动实现
     * （云洋/芒果尚未开工，见 docs/modules.md 第 3/4 节），所以这里只有一项。
     * ————新驱动落地后，把驱动标识加进这个数组即可，不需要改别的校验逻辑。————.
     */
    private const KNOWN_DRIVERS = ['kasushou'];

    /**
     * 所属业务线（requirements.md 6.3：话费 / 卡券 / 电影票 / 快递单选），
     * 跟迁移文件 suppliers.business_line 列注释「单选：recharge/card/movie/express」
     * 一致。
     */
    private const ALLOWED_BUSINESS_LINES = ['recharge', 'card', 'movie', 'express'];

    private const ALLOWED_STATUSES = ['active', 'disabled'];

    private const DEFAULT_STATUS = 'active';

    /**
     * 判断一个 config JSON 字段是否需要脱敏的启发式规则（requirements.md 6.3
     * 「密钥加密存储，后台不明文显示」，比商户 AppSecret「生成时明文回显一次」
     * 更严格——供应商配置密钥任何时候都不明文返回，哪怕刚创建完）。
     *
     * config 的具体形状是按驱动各不相同的自由 JSON，数据库/PHP 层都不做结构校验，
     * 所以没法机械地列出"哪个 key 一定是密钥"，这里用一个大小写不敏感的
     * 子串匹配规则：key 名包含 key / secret / password / token 中任意一个，
     * 就认为是敏感字段，值整体替换成固定掩码 '******'；不匹配的字段
     * （比如 base_url、user_id 这类非敏感字段）原样返回。这是本任务的判断，
     * 不是需求文档给出的机械规则，如果未来某个驱动的非敏感字段名恰好命中这个
     * 子串（比如一个叫 "keyword" 的字段），也会被误判掩码——按「宁可错杀」
     * 的方向选择，因为这里的失败模式是「多脱敏了一个不敏感字段」，比「密钥没脱敏
     * 就泄露出去」安全得多。
     *
     * @var string[]
     */
    private const SENSITIVE_KEY_HINTS = ['key', 'secret', 'password', 'token'];

    private const MASK = '******';

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected Encryptor $encryptor;

    /**
     * 列表接口不返回 config（哪怕是脱敏后的）——列表场景只需要知道「这是哪个供应商、
     * 挂哪个驱动、属于哪条业务线、启用没启用」，不需要接口配置的任何细节，
     * 少返回一个要额外解密的字段也让列表接口更轻。需要看配置就走详情接口。
     * 这是本任务的判断，不是需求文档强制要求。
     *
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(int $page, int $perPage): array
    {
        $suppliers = $this->supplierDao->paginate($page, $perPage);

        $data = $suppliers->map(static fn (Supplier $supplier) => [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'code' => $supplier->code,
            'business_line' => $supplier->business_line,
            'driver' => $supplier->driver,
            'status' => $supplier->status,
            'balance' => $supplier->balance,
            'balance_synced_at' => $supplier->balance_synced_at?->toDateTimeString(),
            'balance_warning_threshold' => $supplier->balance_warning_threshold,
            'created_at' => $supplier->created_at?->toDateTimeString(),
        ])->values()->all();

        return [
            'data' => $data,
            'total' => $this->supplierDao->count(),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(int $id): array
    {
        return $this->format($this->findOrFail($id));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $name = $this->requiredString($data, 'name', 'name 不能为空');
        $code = $this->requiredString($data, 'code', 'code 不能为空');
        $driver = $this->validateDriver($data);
        $businessLine = $this->validateBusinessLine($data);
        $status = $this->validateStatus($data['status'] ?? self::DEFAULT_STATUS);
        $encryptedConfig = $this->encryptConfig($this->validateConfig($data));

        // 唯一性预检查：跟 App\Service\Merchant\AuthService::register() 对
        // phone/email 的处理一样，先查一遍尽量给出干净的 422，下面 create() 里
        // 再 catch 一次数据库唯一约束异常作为并发场景下的兜底防线。
        if ($this->supplierDao->findByCode($code)) {
            throw new HttpException(422, 'code 已存在');
        }

        try {
            $supplier = $this->supplierDao->create([
                'name' => $name,
                'code' => $code,
                'business_line' => $businessLine,
                'driver' => $driver,
                'config' => $encryptedConfig,
                'status' => $status,
                'balance_warning_threshold' => $this->nullableDecimal($data['balance_warning_threshold'] ?? null),
                'contact' => $this->nullableString($data['contact'] ?? null),
                'settlement_info' => $this->nullableString($data['settlement_info'] ?? null),
                'remark' => $this->nullableString($data['remark'] ?? null),
                // balance / balance_synced_at 由未来的余额同步任务写入（out of
                // scope，见类注释），这个接口不接受调用方传入的值，即便传了也忽略。
            ]);
        } catch (QueryException $e) {
            throw new HttpException(422, 'code 已存在', 0, $e);
        }

        return $this->format($supplier);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $supplier = $this->findOrFail($id);

        // code 创建后不可改（requirements.md 6.3）。选择的处理方式：payload 里
        // 完全不带 code 视为「不改这个字段」直接放行；带了 code 但值跟当前一致
        // 也放行（视为幂等的"没有实际变更"）；只有带了一个不同的 code 才拒绝——
        // 这样调用方回传整份详情再改别的字段时不会被 code 字段意外卡住。
        if (array_key_exists('code', $data) && (string) $data['code'] !== $supplier->code) {
            throw new HttpException(422, 'code 创建后不可修改');
        }

        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = $this->requiredString($data, 'name', 'name 不能为空');
        }

        if (array_key_exists('driver', $data)) {
            $attributes['driver'] = $this->validateDriver($data);
        }

        if (array_key_exists('business_line', $data)) {
            $attributes['business_line'] = $this->validateBusinessLine($data);
        }

        if (array_key_exists('status', $data)) {
            $attributes['status'] = $this->validateStatus($data['status']);
        }

        if (array_key_exists('config', $data)) {
            // config 整段替换，没有部分合并语义：既然承诺过"永远不明文回显"，
            // 就没法安全地把调用方传来的明文片段跟"看不到的旧密文"做合并，
            // 只能要求调用方一次性传完整的新配置。
            $attributes['config'] = $this->encryptConfig($this->validateConfig($data));
        }

        if (array_key_exists('balance_warning_threshold', $data)) {
            $attributes['balance_warning_threshold'] = $this->nullableDecimal($data['balance_warning_threshold']);
        }

        if (array_key_exists('contact', $data)) {
            $attributes['contact'] = $this->nullableString($data['contact']);
        }

        if (array_key_exists('settlement_info', $data)) {
            $attributes['settlement_info'] = $this->nullableString($data['settlement_info']);
        }

        if (array_key_exists('remark', $data)) {
            $attributes['remark'] = $this->nullableString($data['remark']);
        }

        if ($attributes !== []) {
            $supplier->fill($attributes)->save();
        }

        return $this->format($supplier);
    }

    /**
     * 启用/禁用，独立的小方法，不塞进通用 update() 校验里——跟上一个任务里
     * 商户 approve/reject 独立于「通用商户更新」是同样的道理（见
     * App\Service\Admin\MerchantAdminService 类注释）。
     */
    public function setStatus(int $id, mixed $status): void
    {
        $supplier = $this->findOrFail($id);

        $supplier->fill(['status' => $this->validateStatus($status)])->save();
    }

    private function findOrFail(int $id): Supplier
    {
        $supplier = $this->supplierDao->find($id);
        if (! $supplier) {
            throw new HttpException(404, '供应商不存在');
        }

        return $supplier;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'code' => $supplier->code,
            'business_line' => $supplier->business_line,
            'driver' => $supplier->driver,
            'config' => $this->maskConfig(json_decode($this->encryptor->decrypt($supplier->config), true)),
            'status' => $supplier->status,
            'balance' => $supplier->balance,
            'balance_synced_at' => $supplier->balance_synced_at?->toDateTimeString(),
            'balance_warning_threshold' => $supplier->balance_warning_threshold,
            'contact' => $supplier->contact,
            'settlement_info' => $supplier->settlement_info,
            'remark' => $supplier->remark,
            'created_at' => $supplier->created_at?->toDateTimeString(),
            'updated_at' => $supplier->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function maskConfig(array $config): array
    {
        $masked = [];
        foreach ($config as $key => $value) {
            $masked[$key] = $this->isSensitiveKey((string) $key) ? self::MASK : $value;
        }

        return $masked;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = mb_strtolower($key);
        foreach (self::SENSITIVE_KEY_HINTS as $hint) {
            if (str_contains($lower, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validateConfig(array $data): array
    {
        $config = $data['config'] ?? null;
        if (! is_array($config)) {
            throw new HttpException(422, 'config 必须是一个可编码为 JSON 的对象');
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function encryptConfig(array $config): string
    {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new HttpException(422, 'config 无法编码为 JSON');
        }

        return $this->encryptor->encrypt($json);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateDriver(array $data): string
    {
        $driver = $data['driver'] ?? null;
        if (! is_string($driver) || ! in_array($driver, self::KNOWN_DRIVERS, true)) {
            throw new HttpException(422, 'driver 必须是已开发的驱动之一：' . implode('/', self::KNOWN_DRIVERS));
        }

        return $driver;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateBusinessLine(array $data): string
    {
        $businessLine = $data['business_line'] ?? null;
        if (! is_string($businessLine) || ! in_array($businessLine, self::ALLOWED_BUSINESS_LINES, true)) {
            throw new HttpException(
                422,
                'business_line 必须是以下之一：' . implode('/', self::ALLOWED_BUSINESS_LINES)
            );
        }

        return $businessLine;
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

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * balance_warning_threshold 是 decimal(10,2) 列，JSON 请求体里数字会被解成
     * PHP int/float 而不是字符串，跟 nullableString() 只接受字符串的语义不一样，
     * 需要单独处理，否则一个合法的 `{"balance_warning_threshold": 100.5}` 会被
     * 悄悄丢成 null。
     */
    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new HttpException(422, 'balance_warning_threshold 必须是数字');
        }

        if (! is_numeric($value)) {
            throw new HttpException(422, 'balance_warning_threshold 必须是数字');
        }

        return (string) $value;
    }
}
