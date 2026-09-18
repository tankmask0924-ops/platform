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

namespace HyperfTest\Cases\Admin;

use App\Model\AdminOperationLog;
use App\Model\MerchantLevel;
use App\Model\SystemSetting;
use HyperfTest\HttpTestCase;

use function Hyperf\Collection\collect;

/**
 * 系统参数、操作日志，以及 AdminOperationLogAspect 自动记日志。
 * system_settings 是共享开发库里的真实配置，测试前备份、测试后原样恢复。
 *
 * @internal
 * @coversNothing
 */
class SystemSettingControllerTest extends HttpTestCase
{
    use CreatesAdmins;

    private const PASSWORD = 'correct-password';

    private array $settingsBackup = [];

    private array $levelIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsBackup = SystemSetting::query()->get()->map(fn ($s) => $s->getAttributes())->all();
    }

    protected function tearDown(): void
    {
        SystemSetting::query()->delete();
        foreach ($this->settingsBackup as $row) {
            SystemSetting::query()->insert($row);
        }
        MerchantLevel::destroy($this->levelIds);
        $this->cleanUpAdmins();
        parent::tearDown();
    }

    public function testListShowsDefaultsAndUpdateValidatesAndLogs()
    {
        SystemSetting::query()->delete();
        $admin = $this->createAdminWithPermissions(['setting.view', 'setting.manage']);
        $token = $this->loginAs($admin);

        $list = collect($this->body($this->jsonRequest('GET', '/admin/settings', $token)))->keyBy('key');
        $this->assertSame(24, $list['abnormal_order_hours']['value']);
        $this->assertTrue($list['abnormal_order_hours']['is_default']);
        $this->assertSame('1000.00', $list['debt_warning_threshold']['value']);

        $this->assertSame(422, $this->jsonRequest('PUT', '/admin/settings/abnormal_order_hours', $token, ['value' => 0])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('PUT', '/admin/settings/abnormal_order_hours', $token, ['value' => '1.5'])->getStatusCode());
        $this->assertSame(404, $this->jsonRequest('PUT', '/admin/settings/no_such_key', $token, ['value' => 1])->getStatusCode());

        $updated = collect($this->body($this->jsonRequest('PUT', '/admin/settings/abnormal_order_hours', $token, ['value' => 12])))->keyBy('key');
        $this->assertSame(12, $updated['abnormal_order_hours']['value']);
        $this->assertFalse($updated['abnormal_order_hours']['is_default']);
        $this->assertSame('Test Admin', $updated['abnormal_order_hours']['updated_by']);

        $money = collect($this->body($this->jsonRequest('PUT', '/admin/settings/debt_warning_threshold', $token, ['value' => '500.5'])))->keyBy('key');
        $this->assertSame('500.50', $money['debt_warning_threshold']['value']);

        // 只有一条带前后对比的日志，切面不再重复记
        $logs = AdminOperationLog::where('admin_user_id', $admin->id)->where('module', 'system')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['key' => 'abnormal_order_hours', 'value' => 24], $logs[0]->before_data);

        // 恢复默认：删掉这一行
        $this->jsonRequest('PUT', '/admin/settings/abnormal_order_hours', $token, ['value' => null]);
        $this->assertNull(SystemSetting::find('abnormal_order_hours'));
    }

    public function testRebatePeriodCannotBeShorterThanDisputeDeadline()
    {
        SystemSetting::query()->delete();
        $token = $this->loginAs($this->createAdminWithPermissions(['setting.manage']));

        $this->assertSame(422, $this->jsonRequest('PUT', '/admin/settings/rebate_due_period_days', $token, ['value' => 5])->getStatusCode());
        $this->assertSame(422, $this->jsonRequest('PUT', '/admin/settings/dispute_deadline_days', $token, ['value' => 10])->getStatusCode());
        $this->assertSame(200, $this->jsonRequest('PUT', '/admin/settings/rebate_due_period_days', $token, ['value' => 10])->getStatusCode());
        $this->assertSame(200, $this->jsonRequest('PUT', '/admin/settings/dispute_deadline_days', $token, ['value' => 10])->getStatusCode());
    }

    public function testWriteWithoutExplicitLogIsLoggedAutomaticallyWithSecretsMasked()
    {
        $admin = $this->createAdminWithPermissions(['merchant_level.manage']);
        $token = $this->loginAs($admin);

        $response = $this->jsonRequest('POST', '/admin/merchant-levels', $token, ['name' => 'lv_' . bin2hex(random_bytes(6)), 'password' => 'should-be-masked']);
        $this->levelIds[] = $this->body($response)['id'] ?? 0;
        $this->assertSame(200, $response->getStatusCode());

        $log = AdminOperationLog::where('admin_user_id', $admin->id)->sole();
        $this->assertSame('merchant_level', $log->module);
        $this->assertSame('store', $log->action);
        $this->assertSame('***', $log->after_data['password']);

        // 失败的写请求和读请求都不记
        $this->jsonRequest('POST', '/admin/merchant-levels', $token, ['name' => '']);
        $this->jsonRequest('GET', '/admin/merchant-levels', $token);
        $this->assertSame(1, AdminOperationLog::where('admin_user_id', $admin->id)->count());
    }

    public function testOperationLogListFiltersByAdmin()
    {
        $admin = $this->createAdminWithPermissions(['setting.manage']);
        $this->jsonRequest('PUT', '/admin/settings/abnormal_order_hours', $this->loginAs($admin), ['value' => 30]);
        $viewer = $this->loginAs($this->createAdminWithPermissions(['operation_log.view']));

        $body = $this->body($this->jsonRequest('GET', '/admin/operation-logs?admin_user_id=' . $admin->id, $viewer));

        $this->assertSame(1, $body['total']);
        $this->assertSame('update_setting', $body['data'][0]['action']);
        $this->assertSame($admin->username, $body['data'][0]['admin_username']);
        $this->assertSame(403, $this->jsonRequest('GET', '/admin/operation-logs', $this->loginAs($admin))->getStatusCode());
    }
}
