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
use App\Supplier\Mango\MangoDriver;
use App\Supplier\Yunyang\YunyangDriver;
use Hyperf\Di\Annotation\Inject;
use RuntimeException;

/**
 * 供应商驱动派发，从 App\Service\Order\RechargeOrderPlacementService 抽出来的独立、
 * 可被 Hyperf DI 容器注入/替换的类。跟 App\Supplier\Kasushou\KasushouDriver 类注释
 * 同一个判断：用 `match($supplier->driver) {...}` 直接分支，不建 `DriverInterface` +
 * 多驱动工厂抽象。云洋驱动落地后重新量过一次，两家同形状的只有 queryBalance() 一个方法，
 * 理由见那边的类注释。
 *
 * **两个驱动分成两个方法建，不是一个 build() 返回联合类型**：`build()` 是话费/卡券
 * （卡速售）那条线，六个调用方全都只会用卡速售的方法；`buildYunyang()` 是快递那条线。
 * 合成一个方法就得把返回类型放宽成联合类型或接口，那六个调用方立刻要 instanceof 收窄，
 * 换来的类型正确性还不如现在直白（两家驱动同形状的只有 queryBalance()，量过的结果见
 * App\Supplier\Kasushou\KasushouDriver 类注释）。等快递订单流程有了自己的路由层、
 * 两条线真的需要同一个入口时再合并。
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
    /**
     * 快递（云洋）驱动。`suppliers.config` 密文解密后的 JSON 形状约定：
     * `{"base_url": "https://...", "app_id": "...", "secret_key": "..."}`。
     *
     * `base_url` 必须是 https，否则 YunyangDriver 构造时直接抛
     * InvalidArgumentException——云洋的签名不覆盖请求内容，明文 http 等于谁都能改
     * 收件地址和重量（yunyang.md 第 3 节）。这个校验故意留在驱动里而不是搬到这里：
     * 它是驱动自己的安全前提，任何构造路径都该拦住。
     */
    public function buildYunyang(Supplier $supplier): YunyangDriver
    {
        if ($supplier->driver !== 'yunyang') {
            throw new RuntimeException('SupplierDriverFactory: supplier #' . $supplier->id . ' is not a yunyang supplier (driver "' . $supplier->driver . '").');
        }

        $config = $this->decodeConfig($supplier);

        return new YunyangDriver(
            (string) ($config['base_url'] ?? ''),
            (string) ($config['app_id'] ?? ''),
            (string) ($config['secret_key'] ?? ''),
            fn (string $action, array $request, array $response, int $durationMs) => $this->callLogService->record(
                (int) $supplier->id,
                $action,
                $request,
                $response,
                $durationMs
            ),
        );
    }

    /**
     * 电影票（芒果）驱动。`suppliers.config` 密文解密后的 JSON 形状约定：
     * `{"base_url": "...", "agent_id": "...", "app_id": "...", "token": "...", "tel": "账户手机号"}`，
     * `tel` 只有查余额用（mango.md 第 1 节"余额"按 tel 查）。
     */
    public function buildMango(Supplier $supplier): MangoDriver
    {
        if ($supplier->driver !== 'mango') {
            throw new RuntimeException('SupplierDriverFactory: supplier #' . $supplier->id . ' is not a mango supplier (driver "' . $supplier->driver . '").');
        }

        $config = $this->decodeConfig($supplier);

        return new MangoDriver(
            (string) ($config['base_url'] ?? ''),
            (string) ($config['agent_id'] ?? ''),
            (string) ($config['app_id'] ?? ''),
            (string) ($config['token'] ?? ''),
            (string) ($config['tel'] ?? ''),
            fn (string $action, array $request, array $response, int $durationMs) => $this->callLogService->record(
                (int) $supplier->id,
                $action,
                $request,
                $response,
                $durationMs
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(Supplier $supplier): array
    {
        $config = json_decode($this->encryptor->decrypt($supplier->config), true);
        if (! is_array($config)) {
            throw new RuntimeException('SupplierDriverFactory: supplier #' . $supplier->id . ' config is not a valid JSON object.');
        }

        return $config;
    }

    private function buildKasushouDriver(Supplier $supplier): KasushouDriver
    {
        $config = $this->decodeConfig($supplier);

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
