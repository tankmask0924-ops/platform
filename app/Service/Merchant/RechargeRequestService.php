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

use App\Dao\MerchantRechargeRequestDao;
use App\Model\Merchant;
use App\Model\MerchantRechargeRequest;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户管理后台（web/merchant）「充值：提交申请 / 查看记录」（requirements.md 4.3）。
 * 只负责创建 pending 状态的申请、查询商户自己的历史记录，不做任何余额变动——
 * 审核通过后真正加余额是 App\Service\Admin\RechargeRequestAdminService 的事，
 * 这条线只到「提交」为止，跟 requirements.md 4.3 时序图里「商户后台」和
 * 「平台财务」是两个独立参与者完全对应。
 *
 * IDOR 防护：list() 强制按调用方传入的 $merchant->id 过滤，Controller 层
 * 只能传认证中间件（MerchantAuthMiddleware）从 token 解出的那个商户模型，
 * 商户没有办法在请求里指定别的 merchant_id 去看别人的充值记录。
 */
class RechargeRequestService extends AbstractService
{
    /**
     * amount 校验：非负整数部分 + 最多两位小数，跟 decimal(10,2) 列精度对齐，
     * 不接受科学计数法/前导加号/千分位分隔符等 is_numeric() 会放过的写法——
     * 充值金额是用户直接输入的自由文本，必须比"数据库能存"更严格地限定格式。
     */
    private const AMOUNT_PATTERN = '/^\d+(\.\d{1,2})?$/';

    #[Inject]
    protected MerchantRechargeRequestDao $rechargeRequestDao;

    /**
     * @return array<string, mixed>
     */
    public function submit(Merchant $merchant, mixed $amount, mixed $proofImage, mixed $transferNo): array
    {
        $amount = $this->normalizeAmount($amount);
        $proofImage = is_string($proofImage) ? trim($proofImage) : '';
        if ($proofImage === '') {
            throw new HttpException(422, 'proof_image 不能为空');
        }

        $transferNo = is_string($transferNo) ? trim($transferNo) : '';

        $request = $this->rechargeRequestDao->create([
            'merchant_id' => $merchant->id,
            'amount' => $amount,
            'proof_image' => $proofImage,
            'transfer_no' => $transferNo !== '' ? $transferNo : null,
            'status' => 'pending',
        ]);

        return $this->format($request);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(Merchant $merchant, int $page, int $perPage): array
    {
        $requests = $this->rechargeRequestDao->paginateByMerchantId($merchant->id, $page, $perPage);

        return [
            'data' => $requests->map(fn (MerchantRechargeRequest $request) => $this->format($request))->values()->all(),
            'total' => $this->rechargeRequestDao->countByMerchantId($merchant->id),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    private function normalizeAmount(mixed $amount): string
    {
        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            throw new HttpException(422, 'amount 必须是正数金额');
        }

        $amount = (string) $amount;
        if (! preg_match(self::AMOUNT_PATTERN, $amount)) {
            throw new HttpException(422, 'amount 格式不合法，最多两位小数');
        }

        if (bccomp($amount, '0', 2) <= 0) {
            throw new HttpException(422, 'amount 必须大于 0');
        }

        return $amount;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(MerchantRechargeRequest $request): array
    {
        return [
            'id' => $request->id,
            'merchant_id' => $request->merchant_id,
            'amount' => $request->amount,
            'proof_image' => $request->proof_image,
            'transfer_no' => $request->transfer_no,
            'status' => $request->status,
            'reject_reason' => $request->reject_reason,
            'reviewed_by' => $request->reviewed_by,
            'reviewed_at' => $request->reviewed_at?->toDateTimeString(),
            'created_at' => $request->created_at?->toDateTimeString(),
        ];
    }
}
