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

use App\Dao\AdminOperationLogDao;
use App\Dao\AdminUserDao;
use App\Dao\SystemSettingDao;
use App\Model\AdminUser;
use App\Service\AbstractService;
use App\Service\Merchant\BalanceService;
use App\Service\Merchant\DisputeService;
use App\Service\Merchant\RateLimitSettingService;
use App\Service\Order\AbnormalOrderService;
use App\Service\Order\ExpressOrderSettlementService;
use App\Service\Order\OrderResultApplier;
use App\Service\Order\SupplierRouter;
use App\Service\Supplier\CircuitBreakerService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统设置 - 系统参数（requirements.md 8.3）。
 *
 * 只列出代码里真正在读的参数；key 和默认值直接引用读取方的常量，两边不会对不上。
 * 表里没有这一行时，读取方用代码默认值——所以"恢复默认"就是删掉这一行。
 * 电影票锁座有效期（三期）等功能上线时再加进来。
 * 每次修改记操作日志（requirements.md 9「系统参数相关操作重点审计」）。
 */
class SystemSettingAdminService extends AbstractService
{
    private const MODULE = 'system';

    /**
     * type：int 整数 / money 两位小数金额；min/max 为允许范围。
     *
     * @var array<string, array{name: string, type: string, unit: string, min: int|string, max: int|string, default: int|string, description: string}>
     */
    private const DEFINITIONS = [
        SupplierRouter::SWITCH_DURATION_SETTING_KEY => [
            'name' => '供应商切换时长',
            'type' => 'int',
            'unit' => '分钟',
            'min' => 0,
            'max' => 1440,
            'default' => SupplierRouter::DEFAULT_SWITCH_DURATION_MINUTES,
            'description' => '订单下单后超过这个时长，供应商明确失败时不再切换下一个供应商，直接判失败',
        ],
        AbnormalOrderService::HOURS_SETTING_KEY => [
            'name' => '异常单时长',
            'type' => 'int',
            'unit' => '小时',
            'min' => 1,
            'max' => 720,
            'default' => AbnormalOrderService::DEFAULT_HOURS,
            'description' => '订单处理中超过这个时长仍拿不到供应商结果，标记为异常单转人工',
        ],
        RateLimitSettingService::DEFAULT_SETTING_KEY => [
            'name' => '默认限流',
            'type' => 'int',
            'unit' => '次/秒',
            'min' => 1,
            'max' => 10000,
            'default' => RateLimitSettingService::DEFAULT_LIMIT_PER_SECOND,
            'description' => '商户没有单独设置限流时，开放 API 每秒允许的请求数',
        ],
        BalanceService::DEBT_WARNING_THRESHOLD_SETTING_KEY => [
            'name' => '欠款预警线',
            'type' => 'money',
            'unit' => '元',
            'min' => '0.00',
            'max' => '10000000.00',
            'default' => BalanceService::DEFAULT_DEBT_WARNING_THRESHOLD,
            'description' => '商户欠款超过这个金额时，商户后台醒目提示并告警',
        ],
        DisputeService::DEADLINE_SETTING_KEY => [
            'name' => '售后争议时限',
            'type' => 'int',
            'unit' => '天',
            'min' => 1,
            'max' => 90,
            'default' => DisputeService::DEFAULT_DEADLINE_DAYS,
            'description' => '话费、卡券订单成功后多少天内，商户可以提交未到账争议',
        ],
        OrderResultApplier::REBATE_DUE_PERIOD_SETTING_KEY => [
            'name' => '返佣固定期限',
            'type' => 'int',
            'unit' => '天',
            'min' => 1,
            'max' => 365,
            'default' => OrderResultApplier::DEFAULT_REBATE_DUE_PERIOD_DAYS,
            'description' => '订单完成后多少天返佣到账；只影响之后完成的订单。短于售后争议时限时，到账后才核实未到账的订单要从余额扣回返佣',
        ],
        ExpressOrderSettlementService::COMPLETE_FALLBACK_DAYS_SETTING_KEY => [
            'name' => '快递完成兜底天数',
            'type' => 'int',
            'unit' => '天',
            'min' => 1,
            'max' => 90,
            'default' => ExpressOrderSettlementService::DEFAULT_COMPLETE_FALLBACK_DAYS,
            'description' => '快递扣费后超过这个天数仍未签收（拒收退回、丢件等），自动记为订单完成',
        ],
        CircuitBreakerService::WINDOW_MINUTES_SETTING_KEY => [
            'name' => '熔断统计窗口',
            'type' => 'int',
            'unit' => '分钟',
            'min' => 1,
            'max' => 1440,
            'default' => CircuitBreakerService::DEFAULT_WINDOW_MINUTES,
            'description' => '判断供应商失败率时，往前看多长时间的下单记录',
        ],
        CircuitBreakerService::MIN_ORDERS_SETTING_KEY => [
            'name' => '熔断最小订单数',
            'type' => 'int',
            'unit' => '单',
            'min' => 1,
            'max' => 10000,
            'default' => CircuitBreakerService::DEFAULT_MIN_ORDERS,
            'description' => '统计窗口内出结果的订单不到这个数就不熔断，避免订单少时一两单失败就误暂停',
        ],
        CircuitBreakerService::FAIL_RATE_PERCENT_SETTING_KEY => [
            'name' => '熔断失败率阈值',
            'type' => 'money',
            'unit' => '%',
            'min' => '1.00',
            'max' => '100.00',
            'default' => CircuitBreakerService::DEFAULT_FAIL_RATE_PERCENT,
            'description' => '统计窗口内失败率超过这个百分比就暂停给该供应商分配新订单；100% 相当于只有全部失败才熔断',
        ],
        CircuitBreakerService::PAUSE_MINUTES_SETTING_KEY => [
            'name' => '熔断暂停时长',
            'type' => 'int',
            'unit' => '分钟',
            'min' => 1,
            'max' => 1440,
            'default' => CircuitBreakerService::DEFAULT_PAUSE_MINUTES,
            'description' => '触发熔断后暂停多久，到期自动恢复；运营也可以在供应商详情页手动暂停/恢复',
        ],
    ];

    #[Inject]
    protected SystemSettingDao $systemSettingDao;

    #[Inject]
    protected AdminUserDao $adminUserDao;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $rows = $this->systemSettingDao->newQuery()->whereIn('key', array_keys(self::DEFINITIONS))->get()->keyBy('key');
        $admins = $this->adminUserDao->newQuery()->whereIn('id', $rows->pluck('updated_by')->filter()->unique()->all())->pluck('real_name', 'id');

        $result = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $row = $rows->get($key);
            $result[] = [
                'key' => $key,
                'name' => $definition['name'],
                'type' => $definition['type'],
                'unit' => $definition['unit'],
                'min' => $definition['min'],
                'max' => $definition['max'],
                'description' => $definition['description'],
                'default' => $definition['default'],
                'value' => $this->currentValue($key),
                'is_default' => $row === null,
                'updated_by' => $row?->updated_by !== null ? ($admins[$row->updated_by] ?? null) : null,
                'updated_at' => $row?->updated_at?->toDateTimeString(),
            ];
        }

        return $result;
    }

    /**
     * $value 为 null 表示恢复默认值。
     *
     * @return list<array<string, mixed>>
     */
    public function update(AdminUser $operator, string $key, mixed $value, ?string $ip): array
    {
        $definition = self::DEFINITIONS[$key] ?? null;
        if ($definition === null) {
            throw new HttpException(404, '参数不存在');
        }

        $newValue = $value === null || $value === '' ? $definition['default'] : $this->normalize($definition, $value);

        Db::transaction(function () use ($operator, $key, $value, $newValue, $ip) {
            $before = ['key' => $key, 'value' => $this->currentValue($key)];
            if ($value === null || $value === '') {
                $this->systemSettingDao->newQuery()->where('key', $key)->delete();
            } else {
                $attrs = [
                    'value' => json_encode($newValue),
                    'description' => self::DEFINITIONS[$key]['name'],
                    'updated_by' => $operator->id,
                ];
                $row = $this->systemSettingDao->findByKey($key);
                $row ? $row->fill($attrs)->save() : $this->systemSettingDao->create(['key' => $key] + $attrs);
            }
            $this->operationLogDao->record($operator->id, self::MODULE, 'update_setting', 'system_setting', null, $before, ['key' => $key, 'value' => $newValue], $ip);
        });

        return $this->list();
    }

    private function currentValue(string $key): int|string
    {
        $definition = self::DEFINITIONS[$key];
        $value = $this->systemSettingDao->getValue($key, $definition['default']);

        return $definition['type'] === 'int' ? (int) $value : number_format((float) $value, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function normalize(array $definition, mixed $value): int|string
    {
        $raw = is_int($value) || is_float($value) ? (string) $value : trim((string) $value);
        $label = "{$definition['name']}";

        if ($definition['type'] === 'int') {
            if (! preg_match('/^\d+$/', $raw)) {
                throw new HttpException(422, "{$label}必须是整数");
            }
            $int = (int) $raw;
            if ($int < $definition['min'] || $int > $definition['max']) {
                throw new HttpException(422, "{$label}的范围是 {$definition['min']}~{$definition['max']} {$definition['unit']}");
            }

            return $int;
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
            throw new HttpException(422, "{$label}必须是最多两位小数的金额");
        }
        $money = bcadd($raw, '0', 2);
        if (bccomp($money, (string) $definition['min'], 2) < 0 || bccomp($money, (string) $definition['max'], 2) > 0) {
            throw new HttpException(422, "{$label}的范围是 {$definition['min']}~{$definition['max']} {$definition['unit']}");
        }

        return $money;
    }
}
