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
 * 商户充值申请（requirements.md 4.3「充值与调账」）：商户线下打款到平台对公账户后，
 * 在商户后台提交这条申请（金额、打款凭证截图 URL、可选的转账流水号），管理后台核对
 * 银行到账后审核通过（加余额）或驳回（填写原因），不做线上支付。
 *
 * `proof_image` 只是一个 URL/路径字符串——文件上传处理本身不在这个项目范围内，
 * 跟 App\Model\MerchantQualification.business_license_image/id_card_images 的既有
 * 约定完全一致（同一个"先接受字符串，上传落地是另一个前端/存储层的事"判断）。
 *
 * `status` 是单向状态机：pending -> approved 或 pending -> rejected，不可逆、不可
 * 二次审核，审核动作的并发安全（拿行锁 + 重新检查 pending）由
 * App\Service\Admin\RechargeRequestAdminService 负责，本 Model 不做任何状态约束。
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $amount
 * @property string $proof_image
 * @property null|string $transfer_no
 * @property string $status
 * @property null|string $reject_reason
 * @property null|int $reviewed_by
 * @property null|Carbon $reviewed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MerchantRechargeRequest extends Model
{
    protected ?string $table = 'merchant_recharge_requests';

    protected array $fillable = [
        'merchant_id',
        'amount',
        'proof_image',
        'transfer_no',
        'status',
        'reject_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected array $casts = [
        'reviewed_at' => 'datetime',
    ];
}
