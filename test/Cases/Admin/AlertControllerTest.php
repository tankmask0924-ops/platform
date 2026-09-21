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

use App\Model\AdminPermission;
use App\Model\AdminRole;
use App\Model\AdminRolePermission;
use App\Model\AdminUser;
use App\Model\Alert;
use App\Service\Alert\AlertService;
use HyperfTest\HttpTestCase;

use function Hyperf\Support\make;

/**
 * 告警：产生与去重（App\Service\Alert\AlertService）、后台列表与标记处理
 * （requirements.md 8.3「告警」，database-design.md 4.14）。
 *
 * @internal
 * @coversNothing
 */
class AlertControllerTest extends HttpTestCase
{
    private const PASSWORD = 'correct-password';

    private array $adminUserIds = [];

    private array $roleIds = [];

    private array $permissionIds = [];

    /** 本用例造的告警关联的 related_id 都用这个区间，避免删到别的数据 */
    private array $relatedIds = [];

    protected function tearDown(): void
    {
        Alert::whereIn('related_id', $this->relatedIds)->delete();
        $this->relatedIds = [];
        AdminUser::destroy($this->adminUserIds);
        AdminRolePermission::whereIn('role_id', $this->roleIds)->delete();
        AdminRole::destroy($this->roleIds);
        AdminPermission::destroy($this->permissionIds);

        parent::tearDown();
    }

    /**
     * database-design.md 4.14 的去重：同一 (type, related_type, related_id) 已有 open
     * 的记录时不新插，只累加次数、刷新时间和内容。
     */
    public function testRepeatedRaiseUpdatesTheSameOpenAlertInsteadOfPilingUp()
    {
        $service = make(AlertService::class);
        $supplierId = $this->nextRelatedId();

        $service->raise(Alert::TYPE_SUPPLIER_LOW_BALANCE, Alert::LEVEL_WARNING, '余额 80 元，低于预警线', 'supplier', $supplierId);
        $service->raise(Alert::TYPE_SUPPLIER_LOW_BALANCE, Alert::LEVEL_WARNING, '余额 50 元，低于预警线', 'supplier', $supplierId);
        $service->raise(Alert::TYPE_SUPPLIER_LOW_BALANCE, Alert::LEVEL_CRITICAL, '余额 10 元，低于预警线', 'supplier', $supplierId);

        $rows = Alert::where('related_id', $supplierId)->get();
        $this->assertCount(1, $rows, '同一对象的同类告警只有一条');
        $this->assertSame(3, $rows[0]->occurrence_count);
        $this->assertSame('余额 10 元，低于预警线', $rows[0]->message, '内容刷新成最新数值');
        $this->assertSame(Alert::LEVEL_CRITICAL, $rows[0]->level, '级别也跟着最新一次');
    }

    public function testDifferentObjectsAndTypesAreSeparateAlerts()
    {
        $service = make(AlertService::class);
        $a = $this->nextRelatedId();
        $b = $this->nextRelatedId();

        $service->raise(Alert::TYPE_SUPPLIER_LOW_BALANCE, Alert::LEVEL_WARNING, '余额低', 'supplier', $a);
        $service->raise(Alert::TYPE_SUPPLIER_LOW_BALANCE, Alert::LEVEL_WARNING, '余额低', 'supplier', $b);
        $service->raise(Alert::TYPE_SUPPLIER_CIRCUIT_BROKEN, Alert::LEVEL_CRITICAL, '熔断了', 'supplier', $a);

        $this->assertSame(1, Alert::where('related_id', $a)->where('type', Alert::TYPE_SUPPLIER_LOW_BALANCE)->count());
        $this->assertSame(1, Alert::where('related_id', $b)->count());
        $this->assertSame(1, Alert::where('related_id', $a)->where('type', Alert::TYPE_SUPPLIER_CIRCUIT_BROKEN)->count());
    }

    /**
     * 处理掉之后再触发，是新的一条——"这个问题又回来了"应该重新冒出来，
     * 而不是悄悄把已处理的那条改回未处理。
     */
    public function testRaisingAgainAfterResolutionCreatesANewAlert()
    {
        $service = make(AlertService::class);
        $supplierId = $this->nextRelatedId();
        $token = $this->loginWith(['alert.view', 'alert.handle']);

        $service->raise(Alert::TYPE_SUPPLIER_LOW_BALANCE, Alert::LEVEL_WARNING, '第一次', 'supplier', $supplierId);
        $first = Alert::where('related_id', $supplierId)->first();
        $this->postJson('/admin/alerts/' . $first->id . '/resolve', $token, []);

        $service->raise(Alert::TYPE_SUPPLIER_LOW_BALANCE, Alert::LEVEL_WARNING, '又来了', 'supplier', $supplierId);

        $rows = Alert::where('related_id', $supplierId)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(Alert::STATUS_RESOLVED, $rows[0]->status);
        $this->assertSame(Alert::STATUS_OPEN, $rows[1]->status);
        $this->assertSame(1, $rows[1]->occurrence_count);
    }

    public function testListPutsOpenAlertsFirstAndFiltersByTypeAndStatus()
    {
        $service = make(AlertService::class);
        $a = $this->nextRelatedId();
        $b = $this->nextRelatedId();
        $token = $this->loginWith(['alert.view', 'alert.handle']);

        $service->raise(Alert::TYPE_SUPPLIER_LOW_BALANCE, Alert::LEVEL_WARNING, '余额低', 'supplier', $a);
        $service->raise(Alert::TYPE_SUPPLIER_CIRCUIT_BROKEN, Alert::LEVEL_CRITICAL, '熔断', 'supplier', $b);
        $resolved = Alert::where('related_id', $a)->first();
        $this->postJson('/admin/alerts/' . $resolved->id . '/resolve', $token, []);

        $mine = $this->getJson('/admin/alerts?related_type=supplier&related_id=' . $b, $token);
        $this->assertSame(1, $mine['total']);
        $this->assertSame(Alert::TYPE_SUPPLIER_CIRCUIT_BROKEN, $mine['data'][0]['type']);
        $this->assertSame('critical', $mine['data'][0]['level']);
        $this->assertGreaterThanOrEqual(1, $mine['open_count']);

        $byType = $this->getJson('/admin/alerts?type=' . Alert::TYPE_SUPPLIER_LOW_BALANCE . '&related_id=' . $a, $token);
        $this->assertSame(1, $byType['total']);
        $this->assertSame('resolved', $byType['data'][0]['status']);
        $this->assertNotNull($byType['data'][0]['resolved_by'], '处理人显示姓名');
        $this->assertNotNull($byType['data'][0]['resolved_at']);

        $this->assertSame(0, $this->getJson('/admin/alerts?status=ignored&related_id=' . $a, $token)['total']);
    }

    public function testIgnoreIsRecordedSeparatelyFromResolve()
    {
        $service = make(AlertService::class);
        $supplierId = $this->nextRelatedId();
        $token = $this->loginWith(['alert.view', 'alert.handle']);

        $service->raise(Alert::TYPE_SUPPLIER_LOW_BALANCE, Alert::LEVEL_WARNING, '测试环境触发的', 'supplier', $supplierId);
        $alert = Alert::where('related_id', $supplierId)->first();

        $this->postJson('/admin/alerts/' . $alert->id . '/ignore', $token, []);

        $this->assertSame(Alert::STATUS_IGNORED, $alert->refresh()->status);
    }

    public function testHandlingAnAlreadyHandledAlertReturns409()
    {
        $service = make(AlertService::class);
        $supplierId = $this->nextRelatedId();
        $token = $this->loginWith(['alert.view', 'alert.handle']);

        $service->raise(Alert::TYPE_SUPPLIER_LOW_BALANCE, Alert::LEVEL_WARNING, '余额低', 'supplier', $supplierId);
        $alert = Alert::where('related_id', $supplierId)->first();

        $this->assertSame(200, $this->post('/admin/alerts/' . $alert->id . '/resolve', $token, [])->getStatusCode());
        $this->assertSame(409, $this->post('/admin/alerts/' . $alert->id . '/resolve', $token, [])->getStatusCode());
        $this->assertSame(409, $this->post('/admin/alerts/' . $alert->id . '/ignore', $token, [])->getStatusCode());
        $this->assertSame(404, $this->post('/admin/alerts/999999999/resolve', $token, [])->getStatusCode());
    }

    public function testInvalidFiltersAreRejected()
    {
        $token = $this->loginWith(['alert.view']);

        foreach (['status=nope', 'type=nope', 'level=nope', 'related_type=nope', 'related_id=abc', 'triggered_from=not-a-date'] as $query) {
            $response = $this->client->request('GET', '/admin/alerts?' . $query, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);
            $this->assertSame(422, $response->getStatusCode(), $query);
        }
    }

    public function testViewPermissionCannotHandleAlerts()
    {
        $service = make(AlertService::class);
        $supplierId = $this->nextRelatedId();
        $service->raise(Alert::TYPE_SUPPLIER_LOW_BALANCE, Alert::LEVEL_WARNING, '余额低', 'supplier', $supplierId);
        $alert = Alert::where('related_id', $supplierId)->first();

        $viewer = $this->loginWith(['alert.view']);

        $this->assertSame(200, $this->client->request('GET', '/admin/alerts', [
            'headers' => ['Authorization' => 'Bearer ' . $viewer],
        ])->getStatusCode());
        $this->assertSame(403, $this->post('/admin/alerts/' . $alert->id . '/resolve', $viewer, [])->getStatusCode());
    }

    private function nextRelatedId(): int
    {
        $id = random_int(900000000, 999999999);
        $this->relatedIds[] = $id;

        return $id;
    }

    private function getJson(string $path, string $token): array
    {
        $response = $this->client->request('GET', $path, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function postJson(string $path, string $token, array $data): array
    {
        $response = $this->post($path, $token, $data);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function post(string $path, string $token, array $data)
    {
        return $this->client->request('POST', $path, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'json' => $data,
        ]);
    }

    /**
     * @param list<string> $codes
     */
    private function loginWith(array $codes): string
    {
        $role = AdminRole::create(['name' => 'role_' . uniqid('', true), 'is_system' => false]);
        $this->roleIds[] = $role->id;
        foreach ($codes as $code) {
            $permission = AdminPermission::firstOrCreate(['code' => $code], ['module' => 'alert', 'name' => $code, 'type' => 'action']);
            if ($permission->wasRecentlyCreated) {
                $this->permissionIds[] = $permission->id;
            }
            AdminRolePermission::create(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }

        $admin = AdminUser::create([
            'username' => 'admin_' . uniqid('', true),
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'real_name' => 'Test Admin',
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $this->adminUserIds[] = $admin->id;

        $login = $this->client->request('POST', '/admin/auth/login', [
            'form_params' => ['username' => $admin->username, 'password' => self::PASSWORD],
        ]);

        return json_decode((string) $login->getBody(), true)['token'];
    }
}
