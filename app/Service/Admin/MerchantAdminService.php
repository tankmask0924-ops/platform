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
use App\Dao\MerchantDao;
use App\Dao\MerchantLevelDao;
use App\Dao\MerchantQualificationDao;
use App\Model\Merchant;
use App\Model\MerchantQualification;
use App\Service\AbstractService;
use Carbon\Carbon;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 系统管理后台（web/admin）「商户管理 - 商户列表 / 详情 / 入驻审核」（requirements.md 4.1、8.3），
 * docs/modules.md 第 8 节。
 *
 * 列表接口原本故意做得很薄（见历史提交），本任务在此基础上补上详情 + 审核通过/驳回——
 * 仍然不碰启用禁用 / 等级调整（针对已 active 商户的后续变更）/ 限流设置，那些是
 * 单独的、更大的后续工作。
 */
class MerchantAdminService extends AbstractService
{
    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantQualificationDao $merchantQualificationDao;

    #[Inject]
    protected MerchantLevelDao $merchantLevelDao;

    #[Inject]
    protected Encryptor $encryptor;

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

        $qualification = $this->merchantQualificationDao->findByMerchantId($merchantId);

        return [
            'id' => $merchant->id,
            'type' => $merchant->type,
            'phone' => $merchant->phone,
            'email' => $merchant->email,
            'status' => $merchant->status,
            'level_id' => $merchant->level_id,
            'created_at' => $merchant->created_at?->toDateTimeString(),
            'qualification' => $qualification ? $this->formatQualification($qualification) : null,
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
            'reviewed_at' => $qualification->reviewed_at?->toDateTimeString(),
        ];
    }
}
