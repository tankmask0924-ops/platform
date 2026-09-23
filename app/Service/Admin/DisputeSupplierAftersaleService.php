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

use App\Dao\AdminOperationLogDao;
use App\Dao\AftersaleDisputeDao;
use App\Dao\OrderAttemptDao;
use App\Dao\OrderDao;
use App\Dao\SupplierDao;
use App\Exception\CallbackOrderNotFoundException;
use App\Exception\InvalidSupplierCallbackSignatureException;
use App\Model\AftersaleDispute;
use App\Model\Supplier;
use App\Service\AbstractService;
use App\Service\Supplier\SupplierNotifyAddressService;
use App\Supplier\SupplierDriverFactory;
use Carbon\Carbon;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Throwable;

/**
 * 话费卡券争议提交给卡速售售后（requirements.md 7.7「供应商支持售后接口时（卡速售），客服可直接通过接口
 * 提交给供应商并接收处理结果」，kasushou.md 第 1 节「售后」）。
 *
 * - **提交**：只对处理中的争议；按这笔订单最新一次尝试的 `external_orderno`（`订单号-尝试序号`）提交给
 *   那家卡速售站点，处理结果回调地址是 `/notify/{code}/{token}/aftersale`。供应商售后还在处理中时不能重复提交；
 *   被终止或已处理完成的可以补充材料再提一次。卡速售明确拒绝 422、结果未知 502，都不记为已提交
 *   （售后也没有防重复参数，结果未知要先去卡速售后台确认）。
 * - **回调**：卡速售售后回调有签名（同订单回调），验签通过的状态和说明直接记到争议上。**不自动结案**：
 *   "处理完成"只说明卡速售处理完了，到账与否要客服看说明后在争议里驳回（已到账）或确认未到账（退款），
 *   跟没有售后接口时的人工核实是同一个出口，退款、返佣作废/扣回都只走 DisputeAdminService::confirm()。
 *   如果卡速售因为售后把订单退款了，订单状态变化会走"成功后被供应商退款"那条链路自动处理。
 */
class DisputeSupplierAftersaleService extends AbstractService
{
    private const MAX_IMAGES = 5;

    #[Inject]
    protected AftersaleDisputeDao $disputeDao;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderAttemptDao $orderAttemptDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected SupplierNotifyAddressService $notifyAddressService;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    /**
     * @param array<string, mixed> $input content / images（截图链接列表，可选）
     */
    public function submit(int $disputeId, array $input, int $adminUserId, ?string $ip): void
    {
        $content = is_string($input['content'] ?? null) ? trim($input['content']) : '';
        if ($content === '' || mb_strlen($content) > 500) {
            throw new HttpException(422, '请填写售后说明（最多 500 字）');
        }
        $images = $this->normalizeImages($input['images'] ?? []);

        $dispute = $this->disputeDao->find($disputeId);
        if (! $dispute instanceof AftersaleDispute) {
            throw new HttpException(404, '争议不存在');
        }
        if ($dispute->status !== AftersaleDispute::STATUS_PROCESSING) {
            throw new HttpException(409, '争议已经处理完，不能再提交供应商售后');
        }
        if ($dispute->supplier_aftersale_status === AftersaleDispute::SUPPLIER_AFTERSALE_PROCESSING) {
            throw new HttpException(409, '供应商售后还在处理中，请等待卡速售的处理结果');
        }
        $order = $this->orderDao->find((int) $dispute->order_id);
        $attempt = $order === null ? null : $this->orderAttemptDao->findLatestForOrder((int) $order->id);
        $supplier = $attempt === null ? null : $this->supplierDao->find((int) $attempt->supplier_id);
        if ($order === null || $attempt === null || $supplier === null) {
            throw new HttpException(409, '订单没有供应商尝试记录，无法提交供应商售后');
        }
        if ($supplier->driver !== 'kasushou') {
            throw new HttpException(409, '这家供应商没有售后接口，请人工核实');
        }
        if (! $this->notifyAddressService->isConfigured()) {
            throw new HttpException(409, '没有配置平台回调地址，收不到卡速售的处理结果');
        }

        try {
            $outcome = $this->supplierDriverFactory->build($supplier)->submitAftersale(
                $order->order_no . '-' . $attempt->attempt_no,
                $content,
                $images,
                $this->notifyAddressService->aftersaleNotifyUrl($supplier)
            );
        } catch (Throwable $e) {
            throw new HttpException(502, '提交卡速售售后失败：' . $e->getMessage(), 0, $e);
        }
        if ($outcome['unknown']) {
            throw new HttpException(502, '卡速售没有返回明确结果（' . $outcome['message'] . '），请先到卡速售后台确认是否已受理');
        }
        if (! $outcome['accepted']) {
            throw new HttpException(422, '卡速售拒绝了售后申请：' . $outcome['message']);
        }

        $dispute->fill([
            'supplier_id' => $supplier->id,
            'supplier_aftersale_no' => $outcome['aftersale_no'],
            'supplier_aftersale_status' => AftersaleDispute::SUPPLIER_AFTERSALE_PROCESSING,
            'supplier_aftersale_reply' => null,
            'supplier_aftersale_submitted_at' => Carbon::now()->toDateTimeString(),
            'supplier_aftersale_updated_at' => null,
        ])->save();

        $this->operationLogDao->record($adminUserId, DisputeAdminService::MODULE, 'submit_supplier_aftersale', 'aftersale_dispute', (int) $dispute->id, null, [
            'order_id' => $dispute->order_id,
            'supplier_id' => $supplier->id,
            'supplier_aftersale_no' => $outcome['aftersale_no'],
            'content' => $content,
            'images' => $images,
        ], $ip);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handleCallback(Supplier $supplier, array $payload): void
    {
        if ($supplier->driver !== 'kasushou') {
            throw new CallbackOrderNotFoundException('this supplier has no aftersale callback');
        }
        $callback = $this->supplierDriverFactory->build($supplier)->parseAftersaleCallback($payload);
        if ($callback === null) {
            throw new InvalidSupplierCallbackSignatureException('kasushou aftersale callback signature mismatch');
        }

        $dispute = $this->findDispute((int) $supplier->id, $callback['aftersale_no'], $callback['external_orderno']);
        if ($dispute === null) {
            throw new CallbackOrderNotFoundException('no dispute matches this aftersale callback');
        }

        $dispute->fill([
            'supplier_aftersale_status' => $callback['status'] ?? $dispute->supplier_aftersale_status,
            'supplier_aftersale_reply' => $callback['reply'] === null ? $dispute->supplier_aftersale_reply : mb_substr($callback['reply'], 0, 500),
            'supplier_aftersale_updated_at' => Carbon::now()->toDateTimeString(),
        ]);
        if ($dispute->supplier_aftersale_no === null && $callback['aftersale_no'] !== null) {
            $dispute->supplier_aftersale_no = $callback['aftersale_no'];
        }
        $dispute->save();
    }

    /**
     * 先按售后单号认；提交时卡速售没给单号的，按 `external_orderno`（`订单号-尝试序号`）反查订单再找争议。
     * 两种都要求争议是提交给这家供应商的。
     */
    private function findDispute(int $supplierId, ?string $aftersaleNo, ?string $externalOrderNo): ?AftersaleDispute
    {
        if ($aftersaleNo !== null) {
            $dispute = $this->disputeDao->newQuery()
                ->where('supplier_id', $supplierId)
                ->where('supplier_aftersale_no', $aftersaleNo)
                ->first();
            if ($dispute instanceof AftersaleDispute) {
                return $dispute;
            }
        }
        if ($externalOrderNo === null || ! str_contains($externalOrderNo, '-')) {
            return null;
        }

        $order = $this->orderDao->findByOrderNo(substr($externalOrderNo, 0, (int) strrpos($externalOrderNo, '-')));
        if ($order === null) {
            return null;
        }
        $dispute = $this->disputeDao->newQuery()
            ->where('order_id', $order->id)
            ->where('supplier_id', $supplierId)
            ->first();

        return $dispute instanceof AftersaleDispute ? $dispute : null;
    }

    /**
     * @return list<string>
     */
    private function normalizeImages(mixed $images): array
    {
        if ($images === null || $images === '' || $images === []) {
            return [];
        }
        if (! is_array($images) || ! array_is_list($images) || count($images) > self::MAX_IMAGES) {
            throw new HttpException(422, 'images 最多 ' . self::MAX_IMAGES . ' 个链接');
        }
        foreach ($images as $url) {
            if (! is_string($url) || ! preg_match('#^https?://#i', $url) || mb_strlen($url) > 500) {
                throw new HttpException(422, '截图必须是 http(s) 链接');
            }
        }

        return array_values($images);
    }
}
