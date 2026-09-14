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

namespace App\Model;

use Carbon\Carbon;

/**
 * 商户资质资料（requirements.md 4.1）。id_card_no 加密存储，解密是 Service 层职责
 * （跟 Merchant.app_secret、OrderRecharge.card_no/card_pwd 的处理方式一致），
 * Model 本身不做加解密。
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $type
 * @property null|string $company_name
 * @property null|string $business_license_no
 * @property null|string $business_license_image
 * @property null|string $legal_person_name
 * @property null|string $contact_name
 * @property string $contact_phone
 * @property null|string $id_card_name
 * @property null|string $id_card_no
 * @property null|array $id_card_images
 * @property string $status
 * @property null|string $reject_reason
 * @property null|int $reviewed_by
 * @property null|Carbon $reviewed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MerchantQualification extends Model
{
    protected ?string $table = 'merchant_qualifications';

    protected array $fillable = [
        'merchant_id',
        'type',
        'company_name',
        'business_license_no',
        'business_license_image',
        'legal_person_name',
        'contact_name',
        'contact_phone',
        'id_card_name',
        'id_card_no',
        'id_card_images',
        'status',
        'reject_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected array $casts = [
        'id_card_images' => 'array',
        'reviewed_at' => 'datetime',
    ];
}
