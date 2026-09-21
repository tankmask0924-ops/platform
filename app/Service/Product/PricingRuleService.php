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

namespace App\Service\Product;

use App\Dao\AdminUserDao;
use App\Dao\PricingRuleDao;
use App\Model\AdminUser;
use App\Model\PricingRule;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use RuntimeException;

/**
 * 电影票、快递的加价规则（requirements.md 5.1、8.3「价格设置」）：后台读写规则，
 * 下单链路按规则把成本算成售价。话费、卡券不走这里——它们的售价是运营给每个商品
 * 直接设置的（`products.sale_price`），没有"成本 + 规则"这一层。
 *
 * **快递只对运费加价**：保价费、耗材费、逆向费按成本转给商户（5.1 表格 + 7.2）。
 * 这件事由调用方保证——它只把运费传进 salePriceFor()，其余费用原样相加。
 * 服务本身不知道传进来的成本是"一整单"还是"其中一项"，所以这个约定写在这里，
 * 快递下单流程接上时按这个来，别把 totalFreight 整个丢进来加价。
 *
 * **金额一律用 bcmath 字符串运算**（同 App\Service\Product\RebateCalculator）：
 * 成本最多 2 位小数、比例最多 4 位小数，float 乘法对这类十进制小数不保证精确。
 *
 * **百分比四舍五入到分，不是截断**（5.1「按百分比算出的售价四舍五入到分」）——注意跟
 * 商户返佣的"向下取整到分"方向相反：返佣是平台付出去的钱，向下取整对平台有利；
 * 售价是商户付的钱，文档明确要求四舍五入。两处都是文档白纸黑字写的，不要互相"统一"。
 * bcmath 的 scale 是截断，所以四舍五入靠"先加 0.005 再截断"实现（成本和加价都非负，
 * 不用考虑负数方向问题）。
 */
class PricingRuleService extends AbstractService
{
    /** 固定金额规则的加价上限（元），纯粹是防手滑输错一个天文数字 */
    private const MAX_FIXED = '9999.99';

    /** 百分比规则的上限：1000%，同样只是防手滑 */
    private const MAX_PERCENTAGE = '10';

    #[Inject]
    protected PricingRuleDao $pricingRuleDao;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    /**
     * 按当前规则把成本算成售价。
     *
     * @param string $cost 成本，非负的十进制字符串（快递传的是**运费**，见类注释）
     * @throws RuntimeException 这条业务线还没配规则——下单链路调用时必须当成配置缺失
     *                          报错，绝不能"没配就按成本卖"，那是平台白干还倒贴
     */
    public function salePriceFor(string $businessLine, string $cost): string
    {
        $rule = $this->pricingRuleDao->findByBusinessLine($businessLine);
        if ($rule === null) {
            throw new RuntimeException('PricingRuleService: no pricing rule configured for business line "' . $businessLine . '".');
        }

        return $this->apply($rule->rule_type, (string) $rule->value, $cost);
    }

    /**
     * 后台「价格设置」列表：两条业务线各一行，没配过的返回 null 规则（不是 0），
     * 前端要能分清"没设置"和"加价 0 元"。
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        $rules = $this->pricingRuleDao->all()->keyBy('business_line');
        $operators = $this->adminUserDao->newQuery()
            ->whereIn('id', $rules->pluck('updated_by')->filter()->unique()->all())
            ->pluck('real_name', 'id');

        $data = [];
        foreach (PricingRule::BUSINESS_LINES as $businessLine) {
            /** @var null|PricingRule $rule */
            $rule = $rules[$businessLine] ?? null;
            $data[] = [
                'business_line' => $businessLine,
                'rule_type' => $rule?->rule_type,
                'value' => $rule === null ? null : $this->normalizeValue($rule->rule_type, (string) $rule->value),
                'updated_by' => $rule?->updated_by === null ? null : ($operators[$rule->updated_by] ?? null),
                'updated_at' => $rule?->updated_at?->toDateTimeString(),
            ];
        }

        return ['data' => $data];
    }

    /**
     * 保存某条业务线的规则。改了只影响之后下的订单（订单已经存了售价快照）。
     *
     * @param array<string, mixed> $payload rule_type / value
     * @return array<string, mixed> 保存后的完整列表，前端不用再查一次
     */
    public function save(AdminUser $operator, string $businessLine, array $payload): array
    {
        $this->assertBusinessLine($businessLine);
        $ruleType = $payload['rule_type'] ?? null;
        if (! is_string($ruleType) || ! in_array($ruleType, PricingRule::TYPES, true)) {
            throw new HttpException(422, 'rule_type 只能是 fixed 或 percentage');
        }

        $value = $payload['value'] ?? null;
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw new HttpException(422, 'value 必须是数字');
        }
        $value = (string) $value;
        // 加价不能为负：5.5 要求提示"低于成本价"，而负加价是一定低于成本的，直接拒绝
        if (bccomp($value, '0', 4) < 0) {
            throw new HttpException(422, '加价不能为负数');
        }
        $max = $ruleType === PricingRule::TYPE_FIXED ? self::MAX_FIXED : self::MAX_PERCENTAGE;
        if (bccomp($value, $max, 4) > 0) {
            throw new HttpException(422, $ruleType === PricingRule::TYPE_FIXED ? '固定加价最多 ' . self::MAX_FIXED . ' 元' : '百分比最多 1000%');
        }

        $this->pricingRuleDao->upsertRule($businessLine, $ruleType, $value, (int) $operator->id);

        return $this->list();
    }

    /**
     * 价格预览（8.3「价格设置...价格预览」）：给一个成本，看按当前规则算出来的售价和毛利。
     * 运营调加价规则时最想知道的就是这个，不用真去下一单。
     *
     * 可以传 `rule_type`/`value` 预览"改成这样会是多少"（还没保存），不传就用已保存的规则。
     *
     * @param array<string, mixed> $query business_line / cost / rule_type / value
     * @return array<string, mixed>
     */
    public function preview(array $query): array
    {
        $businessLine = (string) ($query['business_line'] ?? '');
        $this->assertBusinessLine($businessLine);

        $cost = $query['cost'] ?? null;
        if ($cost === null || $cost === '' || ! is_numeric($cost) || bccomp((string) $cost, '0', 2) < 0) {
            throw new HttpException(422, 'cost 必须是非负数字');
        }
        $cost = bcadd((string) $cost, '0', 2);

        $ruleType = $query['rule_type'] ?? null;
        $value = $query['value'] ?? null;
        if ($ruleType !== null && $ruleType !== '') {
            // 预览未保存的规则：复用 save() 的校验，避免预览能算出保存不进去的组合
            if (! is_string($ruleType) || ! in_array($ruleType, PricingRule::TYPES, true)) {
                throw new HttpException(422, 'rule_type 只能是 fixed 或 percentage');
            }
            if ($value === null || $value === '' || ! is_numeric($value) || bccomp((string) $value, '0', 4) < 0) {
                throw new HttpException(422, 'value 必须是非负数字');
            }
            $salePrice = $this->apply($ruleType, (string) $value, $cost);
        } else {
            $rule = $this->pricingRuleDao->findByBusinessLine($businessLine);
            if ($rule === null) {
                throw new HttpException(422, '这条业务线还没设置加价规则');
            }
            $ruleType = $rule->rule_type;
            $value = $this->normalizeValue($rule->rule_type, (string) $rule->value);
            $salePrice = $this->apply($rule->rule_type, (string) $rule->value, $cost);
        }

        return [
            'business_line' => $businessLine,
            'rule_type' => $ruleType,
            'value' => (string) $value,
            'cost' => $cost,
            'sale_price' => $salePrice,
            // 毛利 = 售价 − 成本（requirements.md 1.2）。快递的保价费、耗材费按成本转给
            // 商户，不产生毛利，所以这里的成本只应该是运费，见类注释。
            'gross_profit' => bcsub($salePrice, $cost, 2),
        ];
    }

    /**
     * 规则本身的计算，`salePriceFor()` 和 `preview()` 共用一条实现——预览算出来的数
     * 必须跟真正下单时算出来的一模一样，两处各写一遍迟早会漂。
     */
    private function apply(string $ruleType, string $value, string $cost): string
    {
        if ($ruleType === PricingRule::TYPE_FIXED) {
            return bcadd($cost, $value, 2);
        }

        // 成本 ×(1 + X%)，四舍五入到分。bcmath 的 scale 是截断而不是四舍五入，
        // 所以先加半分再截断——成本和比例都非负（save() 已拒绝负数），
        // 不用考虑负数方向。中间结果留 4 位，避免先截断再进位少算一分。
        $exact = bcmul($cost, bcadd('1', $value, 4), 4);

        return bcadd(bcadd($exact, '0.005', 4), '0', 2);
    }

    /**
     * 存进 decimal(10,4) 的值读回来永远是 4 位小数（`2.0000`、`0.0500`）。
     * 固定金额按 2 位显示（它是"元"），百分比保留 4 位（它是比例，5.5% = 0.0550）。
     */
    private function normalizeValue(string $ruleType, string $value): string
    {
        return $ruleType === PricingRule::TYPE_FIXED ? bcadd($value, '0', 2) : bcadd($value, '0', 4);
    }

    private function assertBusinessLine(string $businessLine): void
    {
        if (! in_array($businessLine, PricingRule::BUSINESS_LINES, true)) {
            throw new HttpException(422, 'business_line 只能是 ' . implode(' / ', PricingRule::BUSINESS_LINES) . '（话费、卡券的售价在商品上直接设置）');
        }
    }
}
