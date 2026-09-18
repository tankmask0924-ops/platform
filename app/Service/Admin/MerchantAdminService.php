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
use App\Dao\MerchantBalanceLogDao;
use App\Dao\MerchantDao;
use App\Dao\MerchantLevelDao;
use App\Dao\MerchantQualificationDao;
use App\Dao\MerchantRateLimitDao;
use App\Model\Merchant;
use App\Model\MerchantBalanceLog;
use App\Model\MerchantQualification;
use App\Service\AbstractService;
use App\Service\Merchant\BalanceService;
use App\Service\Merchant\RateLimitSettingService;
use Carbon\Carbon;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统管理后台（web/admin）「商户管理 - 商户列表 / 详情 / 入驻审核」（requirements.md 4.1、8.3），
 * docs/modules.md 第 8 节。
 *
 * 列表接口原本故意做得很薄（见历史提交），之后陆续补上详情 + 审核通过/驳回、
 * 手动调账、资金流水，以及针对已审核商户的后续变更：启用禁用 / 调整等级 / 限流设置。
 */
class MerchantAdminService extends AbstractService
{
    /**
     * 默认限流的 key 和兜底值，真正的定义在 RateLimitSettingService。
     */
    public const DEFAULT_RATE_LIMIT_SETTING_KEY = RateLimitSettingService::DEFAULT_SETTING_KEY;

    public const DEFAULT_RATE_LIMIT_PER_SECOND = RateLimitSettingService::DEFAULT_LIMIT_PER_SECOND;

    /**
     * 单独限流值的上限，只是防手滑（多敲几个 0）的合理性校验，不是业务规则。
     */
    public const MAX_RATE_LIMIT_PER_SECOND = 100000;

    /**
     * 启用禁用只在这两个状态之间切换；pending/rejected 走入驻审核流程，不能用
     * 启用禁用绕过审核。
     */
    private const TOGGLEABLE_STATUSES = ['active', 'disabled'];

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantQualificationDao $merchantQualificationDao;

    #[Inject]
    protected MerchantLevelDao $merchantLevelDao;

    #[Inject]
    protected Encryptor $encryptor;

    #[Inject]
    protected BalanceService $balanceService;

    #[Inject]
    protected MerchantBalanceLogDao $balanceLogDao;

    #[Inject]
    protected MerchantRateLimitDao $merchantRateLimitDao;

    #[Inject]
    protected RateLimitSettingService $rateLimitSettingService;

    /**
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(int $page, int $perPage): array
    {
        $merchants = $this->merchantDao->paginate($page, $perPage);

        $data = $merchants->map(static fn ($merchant) => [
            'id' => $merchant->id,
            'type' => $merchant->type,
            'phone' => $merchant->phone,
            'email' => $merchant->email,
            'status' => $merchant->status,
            'level_id' => $merchant->level_id,
            'created_at' => $merchant->created_at?->toDateTimeString(),
        ])->values()->all();

        return [
            'data' => $data,
            'total' => $this->merchantDao->count(),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(int $merchantId): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);

        $qualifications = $this->merchantQualificationDao->listByMerchantId($merchantId);
        $qualification = $qualifications->first();

        return [
            'id' => $merchant->id,
            'type' => $merchant->type,
            'phone' => $merchant->phone,
            'email' => $merchant->email,
            'status' => $merchant->status,
            'level_id' => $merchant->level_id,
            'available_balance' => $merchant->available_balance,
            'frozen_balance' => $merchant->frozen_balance,
            'debt_since' => $merchant->debt_since,
            'created_at' => $merchant->created_at?->toDateTimeString(),
            'qualification' => $qualification ? $this->formatQualification($qualification) : null,
            // 历次提交（驳回后重新提交会有多条），审核时对照之前的驳回原因
            'qualification_history' => $qualifications->map(fn (MerchantQualification $q) => [
                'id' => $q->id,
                'type' => $q->type,
                'status' => $q->status,
                'reject_reason' => $q->reject_reason,
                'submitted_at' => $q->created_at?->toDateTimeString(),
                'reviewed_at' => $q->reviewed_at?->toDateTimeString(),
            ])->values()->all(),
            'rate_limit' => $this->formatRateLimit($merchantId),
        ];
    }

    /**
     * 审核通过：商户启用 + 分配等级，资质记录标记为已通过。single 事务，
     * 跟 App\Service\Merchant\AuthService::register() 一样用 Db::transaction()。
     */
    public function approve(int $merchantId, mixed $levelId, int $reviewerId): void
    {
        $merchant = $this->findMerchantOrFail($merchantId);
        $level = is_numeric($levelId) ? $this->merchantLevelDao->find((int) $levelId) : null;
        if (! $level) {
            throw new HttpException(422, 'level_id 不能为空，且必须对应一个存在的商户等级');
        }

        $qualification = $this->requirePendingQualification($merchant, $merchantId);

        Db::transaction(function () use ($merchant, $qualification, $level, $reviewerId) {
            $merchant->fill(['status' => 'active', 'level_id' => $level->id])->save();

            $qualification->fill([
                'status' => 'approved',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => Carbon::now(),
            ])->save();
        });
    }

    /**
     * 驳回：资质记录标记为已驳回并记录原因，商户状态置为 rejected（商户回去
     * 重新提交资质资料是商户端流程，不在本任务范围内）。
     */
    public function reject(int $merchantId, mixed $reason, int $reviewerId): void
    {
        $merchant = $this->findMerchantOrFail($merchantId);

        $reason = is_string($reason) ? trim($reason) : '';
        if ($reason === '') {
            throw new HttpException(422, 'reason 不能为空');
        }

        $qualification = $this->requirePendingQualification($merchant, $merchantId);

        Db::transaction(function () use ($merchant, $qualification, $reason, $reviewerId) {
            $qualification->fill([
                'status' => 'rejected',
                'reject_reason' => $reason,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => Carbon::now(),
            ])->save();

            $merchant->fill(['status' => 'rejected'])->save();
        });
    }

    /**
     * 手动调账（requirements.md 4.3「手动调账」），独立权限编码 'merchant.balance_adjust'
     * ——财务直接改商户余额是有实际资金影响的动作，跟"查看商户列表/详情"
     * （merchant.view）不是同一档权限，理由跟当初把 'merchant.review' 从
     * 'merchant.view' 拆出来完全一致（见 App\Controller\Admin\MerchantController
     * 类注释）。记得同步维护 App\Service\Admin\AdminBootstrapService::KNOWN_PERMISSIONS。
     *
     * reason 非空校验放在这里（调用方），不下推到 App\Service\Merchant\BalanceService::
     * adjust() 里——跟 reject() 校验自己的 reason 参数是同一个理由：这是纯粹的
     * 输入形状校验，不依赖任何需要行锁保护的数据库状态。amount 的格式/非零校验
     * 属于 BalanceService::adjust() 自己的职责（金额这个值对象本身该满足的约束），
     * 这里不重复做，直接原样透传给它。
     *
     * 不在这里先 findMerchantOrFail() 校验商户存在再调用 adjust()——跟
     * App\Service\Admin\RechargeRequestAdminService::approve() 调用
     * BalanceService::recharge() 的方式一致，商户不存在时让 adjust() 自己在锁行
     * 那一步抛 HttpException(404)，不重复查一次。
     */
    public function adjustBalance(int $merchantId, mixed $amount, mixed $reason, int $operatorId): void
    {
        $reason = is_string($reason) ? trim($reason) : '';
        if ($reason === '') {
            throw new HttpException(422, 'reason 不能为空');
        }

        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            throw new HttpException(422, 'amount 必须是合法的金额');
        }

        $this->balanceService->adjust($merchantId, (string) $amount, $reason, $operatorId);
    }

    /**
     * 资金流水（requirements.md 4.4/4.5、7.2「支持筛选」），跟商户自己在
     * App\Service\Merchant\BalanceLogService::list() 看到的数据一模一样，只是
     * 按路径参数 {id} 指定的商户查，供客服/审计核对用——用 'merchant.view' 权限
     * （看商户资金流水跟看商户详情是同一档权限，没有理由为"多看一点字段"单独
     * 设一个权限编码，见 App\Controller\Admin\MerchantController::show() 同类注释），
     * 不是新增的 'merchant.balance_adjust'（那个权限是"能不能改余额"，跟"能不能
     * 看流水"是两回事）。
     *
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function balanceLogs(int $merchantId, int $page, int $perPage, mixed $type): array
    {
        $this->findMerchantOrFail($merchantId);

        $type = $this->normalizeBalanceLogTypeFilter($type);

        $logs = $this->balanceLogDao->paginateByMerchantId($merchantId, $page, $perPage, $type);

        return [
            'data' => $logs->map(fn (MerchantBalanceLog $log) => $this->formatBalanceLog($log))->values()->all(),
            'total' => $this->balanceLogDao->countByMerchantId($merchantId, $type),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 启用 / 禁用（requirements.md 8.3「启用/禁用」），只允许 active <-> disabled。
     * 禁用后的拦截点都在读 merchants.status 的地方，这里只改状态：开放 API
     * （App\Middleware\OpenApiSignatureMiddleware，非 active 一律拒绝）、商户后台登录
     * （App\Service\Merchant\AuthService::login()）和已登录的商户后台请求
     * （App\Middleware\MerchantAuthMiddleware，禁用前签发的 token 也会被拦下）。
     * 禁用不动余额、冻结金额和等级，已经在途的订单照常走完，重新启用即恢复原样。
     * 目标状态跟当前状态相同时视为幂等成功。
     */
    public function changeStatus(int $merchantId, mixed $status): void
    {
        if (! is_string($status) || ! in_array($status, self::TOGGLEABLE_STATUSES, true)) {
            throw new HttpException(422, 'status 只能是 active 或 disabled');
        }

        $merchant = $this->findMerchantOrFail($merchantId);
        if (! in_array($merchant->status, self::TOGGLEABLE_STATUSES, true)) {
            throw new HttpException(409, '只有已审核通过的商户才能启用或禁用');
        }

        if ($merchant->status === $status) {
            return;
        }

        $merchant->fill(['status' => $status])->save();
    }

    /**
     * 调整等级（requirements.md 5.2「商户等级之后运营可调整」）：只针对审核通过过的
     * 商户（active/disabled），pending 商户的首次分配走 approve()。返佣是在订单
     * 成功那一刻按商户当时的等级算的（见 App\Service\Order\OrderResultApplier），
     * 所以改完对之后成功的订单立即生效，已生成的返佣记录不回溯。
     */
    public function changeLevel(int $merchantId, mixed $levelId): void
    {
        $merchant = $this->findMerchantOrFail($merchantId);

        $level = is_numeric($levelId) ? $this->merchantLevelDao->find((int) $levelId) : null;
        if (! $level) {
            throw new HttpException(422, 'level_id 不能为空，且必须对应一个存在的商户等级');
        }

        if (! in_array($merchant->status, self::TOGGLEABLE_STATUSES, true)) {
            throw new HttpException(409, '只有已审核通过的商户才能调整等级，待审核商户请在审核通过时分配');
        }

        if ((int) $merchant->level_id === (int) $level->id) {
            return;
        }

        $merchant->fill(['level_id' => $level->id])->save();
    }

    /**
     * 设置单独限流值（requirements.md 8.1/8.3），覆盖全局默认。只存配置，不关心
     * 商户当前状态——给待审核商户预先配好也没有副作用。
     *
     * @return array{limit_per_second: int, is_custom: bool}
     */
    public function setRateLimit(int $merchantId, mixed $limitPerSecond): array
    {
        $this->findMerchantOrFail($merchantId);

        $limit = $this->normalizeRateLimit($limitPerSecond);
        $this->merchantRateLimitDao->upsertLimit($merchantId, $limit);

        return $this->formatRateLimit($merchantId);
    }

    /**
     * 删除单独限流值，商户回落到全局默认。本来就没有单独配置时也返回成功（幂等）。
     *
     * @return array{limit_per_second: int, is_custom: bool}
     */
    public function resetRateLimit(int $merchantId): array
    {
        $this->findMerchantOrFail($merchantId);

        $this->merchantRateLimitDao->deleteByMerchantId($merchantId);

        return $this->formatRateLimit($merchantId);
    }

    private function normalizeBalanceLogTypeFilter(mixed $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        if (! is_string($type) || ! in_array($type, MerchantBalanceLog::TYPES, true)) {
            throw new HttpException(422, 'type 不合法');
        }

        return $type;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatBalanceLog(MerchantBalanceLog $log): array
    {
        return [
            'id' => $log->id,
            'merchant_id' => $log->merchant_id,
            'type' => $log->type,
            'amount' => $log->amount,
            'available_before' => $log->available_before,
            'available_after' => $log->available_after,
            'frozen_before' => $log->frozen_before,
            'frozen_after' => $log->frozen_after,
            'order_id' => $log->order_id,
            'rebate_id' => $log->rebate_id,
            'reason' => $log->reason,
            'operator_id' => $log->operator_id,
            'created_at' => $log->created_at?->toDateTimeString(),
        ];
    }

    /**
     * 只接受正整数（JSON 数字或纯数字字符串），拒绝 0、负数、小数和超出上限的值。
     */
    private function normalizeRateLimit(mixed $value): int
    {
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1 || $value > self::MAX_RATE_LIMIT_PER_SECOND) {
            throw new HttpException(
                422,
                sprintf('limit_per_second 必须是 1 到 %d 之间的整数', self::MAX_RATE_LIMIT_PER_SECOND)
            );
        }

        return $value;
    }

    /**
     * @return array{limit_per_second: int, is_custom: bool}
     */
    private function formatRateLimit(int $merchantId): array
    {
        return $this->rateLimitSettingService->effectiveLimit($merchantId);
    }

    private function findMerchantOrFail(int $merchantId): Merchant
    {
        $merchant = $this->merchantDao->find($merchantId);
        if (! $merchant) {
            throw new HttpException(404, '商户不存在');
        }

        return $merchant;
    }

    /**
     * 审核通过/驳回共用的前置校验：只有 pending 状态的商户才能被审核
     * （审核只应该发生一次），且必须存在一条真正待审核的资质记录——理论上不应该
     * 出现「商户是 pending 但没有对应待审核资质」这种数据不一致，但校验成本很低，
     * 出现时给一个干净的 409 而不是让后续代码在 null 上崩溃。
     */
    private function requirePendingQualification(Merchant $merchant, int $merchantId): MerchantQualification
    {
        if ($merchant->status !== 'pending') {
            throw new HttpException(409, '只有待审核状态的商户才能进行审核操作');
        }

        $qualification = $this->merchantQualificationDao->findLatestPendingByMerchantId($merchantId);
        if (! $qualification) {
            throw new HttpException(409, '未找到待审核的资质资料');
        }

        return $qualification;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatQualification(MerchantQualification $qualification): array
    {
        return [
            'type' => $qualification->type,
            'company_name' => $qualification->company_name,
            'business_license_no' => $qualification->business_license_no,
            'business_license_image' => $qualification->business_license_image,
            'legal_person_name' => $qualification->legal_person_name,
            'contact_name' => $qualification->contact_name,
            'contact_phone' => $qualification->contact_phone,
            'id_card_name' => $qualification->id_card_name,
            // 内部管理后台专用视图，跟商户自己的商户后台不同——那边永远不回显身份证号，
            // 这里是平台审核人员需要核对证件信息的场景，明文解密后返回是合理的
            // （见 requirements.md 4.1 + App\Crypto\Encryptor 类注释）。
            'id_card_no' => $qualification->id_card_no !== null
                ? $this->encryptor->decrypt($qualification->id_card_no)
                : null,
            'id_card_images' => $qualification->id_card_images,
            'status' => $qualification->status,
            'reject_reason' => $qualification->reject_reason,
            'submitted_at' => $qualification->created_at?->toDateTimeString(),
            'reviewed_at' => $qualification->reviewed_at?->toDateTimeString(),
        ];
    }
}
