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

namespace App\Supplier;

use App\Crypto\Encryptor;
use App\Model\Supplier;
use App\Service\Supplier\SupplierCallLogService;
use App\Supplier\Kasushou\KasushouDriver;
use Hyperf\Di\Annotation\Inject;
use RuntimeException;

/**
 * 供应商驱动派发，从 App\Service\Order\RechargeOrderPlacementService 抽出来的独立、
 * 可被 Hyperf DI 容器注入/替换的类。跟 App\Supplier\Kasushou\KasushouDriver 类注释
 * 同一个判断：目前只有卡速售一个驱动实现，用 `match($supplier->driver) {...}` 直接
 * 分支即可，不建 `DriverInterface` + 多驱动工厂抽象——等云洋/芒果任一个驱动落地、
 * 真正验证过这几个方法签名对两家供应商都合适之后再抽取。
 *
 * `suppliers.config` 对 `kasushou` 驱动的 JSON 形状（解密后）：
 * `{"base_url": "...", "user_id": "...", "api_key": "..."}`，这是
 * RechargeOrderPlacementService 那次任务定下的事实上的约定，后台配置卡速售供应商
 * 时必须遵守这个形状。
 *
 * 【测试方式】本类通过 `#[Inject]` 被 RechargeOrderPlacementService 注入，测试要
 * 替身掉真实的配置解密 + HTTP 驱动构造时，直接用 Hyperf\Testing\TestCase 自带的
 * 容器 swap——`$this->instance(SupplierDriverFactory::class, Mockery::mock(...))`，
 * 在解析被测 Service 之前调用，效果跟 test/Cases/Job/NotifyMerchantJobTest.php 把
 * Hyperf\AsyncQueue\Driver\DriverFactory 换成 Mockery 双重完全一样，不需要在本类
 * 或调用方留任何生产代码不该有的测试专用 setter/hook。
 */
class SupplierDriverFactory
{
    #[Inject]
    protected Encryptor $encryptor;

    #[Inject]
    protected SupplierCallLogService $callLogService;

    public function build(Supplier $supplier): KasushouDriver
    {
        return match ($supplier->driver) {
            'kasushou' => $this->buildKasushouDriver($supplier),
            default => throw new RuntimeException('RechargeOrderPlacementService: unsupported supplier driver "' . $supplier->driver . '" (only kasushou is implemented so far).'),
        };
    }

    /**
     * `suppliers.config` 密文解密后对 kasushou 驱动的 JSON 形状约定：
     * `{"base_url": "...", "user_id": "...", "api_key": "..."}`（见类注释）。
     */
    private function buildKasushouDriver(Supplier $supplier): KasushouDriver
    {
        $config = json_decode($this->encryptor->decrypt($supplier->config), true);
        if (! is_array($config)) {
            throw new RuntimeException('RechargeOrderPlacementService: supplier #' . $supplier->id . ' config is not a valid JSON object.');
        }

        return new KasushouDriver(
            (string) ($config['base_url'] ?? ''),
            (string) ($config['user_id'] ?? ''),
            (string) ($config['api_key'] ?? ''),
            fn (string $action, array $request, array $response, int $durationMs) => $this->callLogService->record(
                (int) $supplier->id,
                $action,
                $request,
                $response,
                $durationMs
            ),
        );
    }
}
