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

use App\Dao\MerchantLevelBusinessRateDao;
use App\Dao\MerchantLevelDao;
use App\Model\MerchantLevel;
use App\Service\AbstractService;
use Hyperf\Database\Exception\QueryException;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统管理后台（web/admin）「商户等级：CRUD / 各业务线比例设置」（requirements.md 5.2），
 * docs/modules.md 第 8 节。
 *
 * 写入的 merchant_level_business_rates 正是 App\Service\Product\RebateCalculator
 * 5.3 取法第 2 步读取的数据。
 *
 * 范围说明：
 * - 不含商品单独覆盖某等级比例（product_level_rebates，5.2/5.3 的另一半），单独的后续任务；
 * - 不提供删除等级：已被商户/返佣记录引用的等级不应该被随手删掉，需求文档也没有描述删除流程；
 * - 不含「调整某个商户属于哪个等级」，那是商户管理的功能（见 MerchantAdminService）；
 * - 不含 5.5 的比例保护提示（电影票/快递比例超过 100% 等），还没有后台前端来展示。
 *
 * 比例修改只影响之后下的订单（5.2/5.3）：这一点由现有数据流天然保证——返佣在订单
 * 成功时计算并把比例快照进 merchant_rebates，这里改比例不回溯任何已有记录。
 */
class MerchantLevelAdminService extends AbstractService
{
    /**
     * 业务线，跟迁移文件 merchant_level_business_rates.business_line 列注释一致。
     * 详情接口按这个顺序逐条输出。
     */
    public const BUSINESS_LINES = ['recharge', 'card', 'movie', 'express'];

    private const NAME_MAX_LENGTH = 32;

    private const REMARK_MAX_LENGTH = 255;

    /**
     * rebate_rate 列是 decimal(6,4)：最多 4 位小数，整数部分最多 2 位，
     * 能存的最大值是 99.9999。
     */
    private const RATE_MAX = '99.9999';

    #[Inject]
    protected MerchantLevelDao $merchantLevelDao;

    #[Inject]
    protected MerchantLevelBusinessRateDao $merchantLevelBusinessRateDao;

    /**
     * 全量列表，不分页（原因见 MerchantLevelDao::all()）。
     *
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function list(): array
    {
        $data = $this->merchantLevelDao->all()
            ->map(fn (MerchantLevel $level) => $this->formatLevel($level))
            ->values()
            ->all();

        return ['data' => $data, 'total' => count($data)];
    }

    /**
     * 等级字段 + 四条业务线的比例。`rates` 固定包含全部四个业务线 key：
     * - 值为 `null`：该业务线**没有设置**比例（没有行），按 5.3 取法视为 0%，不返佣；
     * - 值为字符串（如 `'0.9000'`、`'0.0000'`）：已设置的比例，`'0.0000'` 是运营
     *   明确设置成 0%。
     * 两者当前算出来的返佣都是 0，但语义不同：没设置意味着运营还没配这一项。
     *
     * @return array<string, mixed>
     */
    public function detail(int $id): array
    {
        $level = $this->findOrFail($id);

        $rates = array_fill_keys(self::BUSINESS_LINES, null);
        foreach ($this->merchantLevelBusinessRateDao->listForLevel($level->id) as $row) {
            if (array_key_exists($row->business_line, $rates)) {
                $rates[$row->business_line] = $row->rebate_rate;
            }
        }

        return $this->formatLevel($level) + ['rates' => $rates];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $name = $this->validateName($data['name'] ?? null);
        $remark = array_key_exists('remark', $data) ? $this->validateRemark($data['remark']) : null;

        // 先查一遍给出干净的 422，数据库唯一约束异常作为并发场景下的兜底防线
        // （跟 SupplierAdminService::create() 同样的做法）。
        if ($this->merchantLevelDao->findByName($name)) {
            throw new HttpException(422, 'name 已存在');
        }

        try {
            $level = $this->merchantLevelDao->create(['name' => $name, 'remark' => $remark]);
        } catch (QueryException $e) {
            throw new HttpException(422, 'name 已存在', 0, $e);
        }

        return $this->detail($level->id);
    }

    /**
     * 只更新 payload 里带了的字段（name/remark）。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $level = $this->findOrFail($id);

        $attributes = [];

        if (array_key_exists('name', $data)) {
            $name = $this->validateName($data['name']);
            if ($name !== $level->name) {
                $existing = $this->merchantLevelDao->findByName($name);
                if ($existing && $existing->id !== $level->id) {
                    throw new HttpException(422, 'name 已存在');
                }
                $attributes['name'] = $name;
            }
        }

        if (array_key_exists('remark', $data)) {
            $attributes['remark'] = $this->validateRemark($data['remark']);
        }

        if ($attributes !== []) {
            try {
                $level->fill($attributes)->save();
            } catch (QueryException $e) {
                throw new HttpException(422, 'name 已存在', 0, $e);
            }
        }

        return $this->detail($level->id);
    }

    /**
     * 设置（新建或原地更新）某等级在某业务线的默认返佣比例。
     *
     * 比例是小数比值（0.9000 表示 90%），接受任意非负值，最多 4 位小数。
     * 大于 1（超过 100%）的比例照样保存：5.5 只要求电影票/快递超过 100% 时"提示"，
     * 没有要求禁止，提示属于后台前端的交互，不在这个接口里硬性拒绝。
     * 唯一的上限是列本身能存下的 99.9999，超过直接 422，而不是让数据库报错。
     *
     * @return array<string, mixed>
     */
    public function setRate(int $id, string $businessLine, mixed $rebateRate): array
    {
        if (! in_array($businessLine, self::BUSINESS_LINES, true)) {
            throw new HttpException(422, 'business_line 必须是以下之一：' . implode('/', self::BUSINESS_LINES));
        }

        $rate = $this->validateRate($rebateRate);

        $level = $this->findOrFail($id);

        $row = $this->merchantLevelBusinessRateDao->upsertRate($level->id, $businessLine, $rate);

        return [
            'level_id' => $row->level_id,
            'business_line' => $row->business_line,
            'rebate_rate' => $row->rebate_rate,
            'updated_at' => $row->updated_at?->toDateTimeString(),
        ];
    }

    private function findOrFail(int $id): MerchantLevel
    {
        $level = $this->merchantLevelDao->find($id);
        if (! $level) {
            throw new HttpException(404, '商户等级不存在');
        }

        return $level;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatLevel(MerchantLevel $level): array
    {
        return [
            'id' => $level->id,
            'name' => $level->name,
            'remark' => $level->remark,
            'created_at' => $level->created_at?->toDateTimeString(),
            'updated_at' => $level->updated_at?->toDateTimeString(),
        ];
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

    private function validateRemark(mixed $remark): ?string
    {
        if ($remark === null) {
            return null;
        }
        if (! is_string($remark)) {
            throw new HttpException(422, 'remark 必须是字符串');
        }

        $remark = trim($remark);
        if (mb_strlen($remark) > self::REMARK_MAX_LENGTH) {
            throw new HttpException(422, 'remark 不能超过 ' . self::REMARK_MAX_LENGTH . ' 个字符');
        }

        return $remark === '' ? null : $remark;
    }

    /**
     * JSON 请求体里的数字会被解成 int/float，字符串形式也接受；统一转成字符串后
     * 用正则校验成「非负、最多 4 位小数」的普通十进制写法（拒绝负数、科学计数法等）。
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
