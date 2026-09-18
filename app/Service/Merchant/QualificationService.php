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

namespace App\Service\Merchant;

use App\Crypto\Encryptor;
use App\Dao\MerchantDao;
use App\Dao\MerchantLevelDao;
use App\Dao\MerchantQualificationDao;
use App\Model\Merchant;
use App\Model\MerchantQualification;
use App\Network\HttpUrl;
use App\Service\AbstractService;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户资质资料（requirements.md 4.1「提交资质资料 → 平台审核 → 驳回后重新提交」、8.2「资质资料提交与审核状态」）。
 *
 * 注册（AuthService::register()）和驳回后重新提交共用这里的校验和落库字段。
 * 每次提交都新插一条记录，历史记录保留，最新一条是当前状态。
 * 只有被驳回的商户能重新提交；审核通过后资质不能在线修改（变更要走平台，没有"已启用商户重新审核"的流程）。
 * 身份证号加密存储，商户自己看也只显示后 4 位。
 */
class QualificationService extends AbstractService
{
    public const TYPES = ['company', 'individual'];

    /**
     * 按 requirements.md 4.1「企业：公司名称、营业执照、法人信息、联系人」「个人：姓名、身份证信息、联系方式」
     * 逐项落地的必填字段；证件照片可选（文件上传还没做，只能填链接）。
     */
    private const REQUIRED_FIELDS = [
        'company' => ['company_name', 'business_license_no', 'legal_person_name', 'contact_name', 'contact_phone'],
        'individual' => ['id_card_name', 'id_card_no', 'contact_phone'],
    ];

    /** 字段长度上限，跟 merchant_qualifications 列定义一致 */
    private const MAX_LENGTHS = [
        'company_name' => 128,
        'business_license_no' => 64,
        'legal_person_name' => 64,
        'contact_name' => 64,
        'contact_phone' => 20,
        'id_card_name' => 64,
        'id_card_no' => 32,
        'business_license_image' => 255,
    ];

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantLevelDao $levelDao;

    #[Inject]
    protected MerchantQualificationDao $qualificationDao;

    #[Inject]
    protected Encryptor $encryptor;

    /**
     * @param array<string, mixed> $data
     */
    public function validate(string $type, array $data): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new HttpException(422, 'type 必须是 company 或 individual');
        }

        foreach (self::REQUIRED_FIELDS[$type] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                throw new HttpException(422, "{$field} 不能为空");
            }
        }

        foreach (self::MAX_LENGTHS as $field => $max) {
            $value = $data[$field] ?? null;
            if (is_string($value) && mb_strlen(trim($value)) > $max) {
                throw new HttpException(422, "{$field} 不能超过 {$max} 个字符");
            }
        }

        $images = is_array($data['id_card_images'] ?? null) ? $data['id_card_images'] : [];
        if (is_string($data['business_license_image'] ?? null)) {
            $images[] = $data['business_license_image'];
        }
        foreach ($images as $image) {
            $image = is_string($image) ? trim($image) : null;
            if ($image !== '' && ($image === null || ! HttpUrl::isValid($image))) {
                throw new HttpException(422, '证件照片必须是 http:// 或 https:// 开头的链接');
            }
        }
    }

    /**
     * 新建一条待审核的资质记录。调用方负责先 validate()、并在事务里调用。
     *
     * @param array<string, mixed> $data
     */
    public function createPending(int $merchantId, string $type, array $data): MerchantQualification
    {
        // 只落当前类型的字段，切换类型重新提交时不会带上另一种类型残留的值
        $fields = $type === 'company'
            ? ['company_name', 'business_license_no', 'business_license_image', 'legal_person_name', 'contact_name']
            : ['id_card_name', 'contact_name'];

        $row = [
            'merchant_id' => $merchantId,
            'type' => $type,
            'contact_phone' => trim((string) ($data['contact_phone'] ?? '')),
            'status' => 'pending',
        ];
        foreach ($fields as $field) {
            $row[$field] = $this->nullableString($data[$field] ?? null);
        }
        if ($type === 'individual') {
            $images = is_array($data['id_card_images'] ?? null) ? array_values(array_filter(array_map(
                fn ($image) => is_string($image) ? trim($image) : '',
                $data['id_card_images']
            ))) : [];
            $row['id_card_images'] = $images === [] ? null : $images;
            $row['id_card_no'] = $this->encryptor->encrypt(trim((string) $data['id_card_no']));
        }

        return $this->qualificationDao->create($row);
    }

    /**
     * 商户后台「资质资料」页：账户状态、当前等级、最新一次提交的资料和历次审核结果。
     *
     * @return array<string, mixed>
     */
    public function current(Merchant $merchant): array
    {
        $qualifications = $this->qualificationDao->listByMerchantId((int) $merchant->id);
        $latest = $qualifications->first();

        return [
            'status' => $merchant->status,
            'type' => $merchant->type,
            'level_name' => $merchant->level_id ? $this->levelDao->find((int) $merchant->level_id)?->name : null,
            'can_resubmit' => $merchant->status === 'rejected',
            'qualification' => $latest ? $this->format($latest) : null,
            'history' => $qualifications->map(fn (MerchantQualification $q) => [
                'id' => $q->id,
                'type' => $q->type,
                'status' => $q->status,
                'reject_reason' => $q->reject_reason,
                'submitted_at' => $q->created_at?->toDateTimeString(),
                'reviewed_at' => $q->reviewed_at?->toDateTimeString(),
            ])->values()->all(),
        ];
    }

    /**
     * 驳回后重新提交：新插一条待审核记录，商户回到待审核状态，类型可以改。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function resubmit(Merchant $merchant, array $data): array
    {
        $type = is_string($data['type'] ?? null) ? $data['type'] : (string) $merchant->type;
        $this->validate($type, $data);

        $merchant = Db::transaction(function () use ($merchant, $type, $data) {
            // 锁住商户行再判断状态：两次并发提交只有一次能成功，不会出现两条待审核记录
            $locked = $this->merchantDao->lockForUpdate((int) $merchant->id);
            if ($locked->status === 'pending') {
                throw new HttpException(409, '资质资料正在审核中，请等待审核结果');
            }
            if ($locked->status !== 'rejected') {
                throw new HttpException(409, '资质审核已通过，资料变更请联系平台');
            }

            $this->createPending((int) $locked->id, $type, $data);
            $locked->fill(['type' => $type, 'status' => 'pending'])->save();

            return $locked;
        });

        return $this->current($merchant);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(MerchantQualification $qualification): array
    {
        $idCardNo = $qualification->id_card_no !== null ? $this->encryptor->decrypt($qualification->id_card_no) : null;

        return [
            'id' => $qualification->id,
            'type' => $qualification->type,
            'company_name' => $qualification->company_name,
            'business_license_no' => $qualification->business_license_no,
            'business_license_image' => $qualification->business_license_image,
            'legal_person_name' => $qualification->legal_person_name,
            'contact_name' => $qualification->contact_name,
            'contact_phone' => $qualification->contact_phone,
            'id_card_name' => $qualification->id_card_name,
            'id_card_no_masked' => $idCardNo !== null ? $this->mask($idCardNo) : null,
            'id_card_images' => $qualification->id_card_images,
            'status' => $qualification->status,
            'reject_reason' => $qualification->reject_reason,
            'submitted_at' => $qualification->created_at?->toDateTimeString(),
            'reviewed_at' => $qualification->reviewed_at?->toDateTimeString(),
        ];
    }

    /** 只留后 4 位 */
    private function mask(string $value): string
    {
        $length = mb_strlen($value);

        return $length <= 4 ? str_repeat('*', $length) : str_repeat('*', $length - 4) . mb_substr($value, -4);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
