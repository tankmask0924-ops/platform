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

namespace App\Service\Dev;

use App\Crypto\Encryptor;
use App\Dao\MerchantDao;
use App\Model\AftersaleDispute;
use App\Model\ExpressWorkorder;
use App\Model\MerchantRebate;
use App\Model\Order;
use App\Model\OrderExpress;
use App\Model\OrderExpressFeeAdjustment;
use App\Model\OrderMovie;
use App\Model\ReconciliationDiff;
use App\Model\Supplier;
use App\Service\AbstractService;
use App\Service\Merchant\BalanceService;
use Carbon\Carbon;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use InvalidArgumentException;

/**
 * 前端联调用：给指定商户造快递、电影票订单，以及快递工单、带卡速售售后进展的争议、返佣对账差异，
 * 让两个后台 2026-09-23 新增的页面有东西可看。跟 MockOrderService 一样只写库、不调供应商、不发回调。
 *
 * - 供应商用两个专门的测试供应商（「联调测试云洋」「联调测试芒果」，停用状态，接口地址 https://example.invalid），
 *   页面上点查询供应商、提交工单只会拿到网络失败，不会打到真实供应商。
 * - 余额走真实的 BalanceService；商户余额不够时先按「联调造数据」调账补足。
 * - 处理中的快递、电影票订单不建尝试记录，定时查询不会去查它们（电影票锁座有效期设成 2 小时后，免得马上被超时释放）。
 */
class MockExpressMovieService extends AbstractService
{
    private const CALLBACK_URL = 'http://127.0.0.1:9/mock-callback';

    /** 这批订单一共要占用的余额，不够时先调账补到这么多 */
    private const REQUIRED_BALANCE = '200.00';

    #[Inject]
    protected ConfigInterface $config;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected BalanceService $balanceService;

    #[Inject]
    protected Encryptor $encryptor;

    /**
     * @return list<array{order_no: string, business_line: string, status: string, note: string}>
     */
    public function create(int $merchantId): array
    {
        if ($this->config->get('app_env') === 'prod') {
            throw new InvalidArgumentException('生产环境不能造模拟订单');
        }
        $merchant = $this->merchantDao->find($merchantId);
        if ($merchant === null) {
            throw new InvalidArgumentException("商户 #{$merchantId} 不存在");
        }
        if (bccomp((string) $merchant->available_balance, self::REQUIRED_BALANCE, 2) < 0) {
            $this->balanceService->adjust(
                $merchantId,
                bcsub(self::REQUIRED_BALANCE, (string) $merchant->available_balance, 2),
                '联调造数据：快递、电影票模拟订单占用',
                null
            );
        }

        $yunyang = $this->supplier('mock_yunyang', '联调测试云洋', 'express', 'yunyang');
        $mango = $this->supplier('mock_mango', '联调测试芒果', 'movie', 'mango');
        $created = [];

        // ---- 快递 ----
        $e1 = $this->order($merchantId, 'express', 'E', '14.00', '12.00', $yunyang);
        $this->balanceService->freeze($merchantId, $e1->id, '14.00');
        $this->express($e1, 'pending_pickup', ['estimated_freight' => '12.00', 'frozen_freight' => '12.00', 'freight_sale_price' => '14.00']);
        $created[] = $this->row($e1, '待揽收，已冻结');

        $e2 = $this->order($merchantId, 'express', 'E', '14.50', '12.50', $yunyang);
        $this->balanceService->freeze($merchantId, $e2->id, '14.50');
        $this->balanceService->deduct($merchantId, $e2->id, '14.50');
        $this->finish($e2, 'success', '14.50', 30);
        $this->express($e2, 'signed', [
            'estimated_freight' => '12.00', 'frozen_freight' => '12.00', 'actual_freight' => '12.00', 'freight_sale_price' => '14.00',
            'actual_insured_fee' => '0.50', 'actual_material_fee' => '1.00', 'actual_reverse_fee' => '0.00',
            'fee_over_at' => $this->ago(29), 'signed_at' => $this->ago(5),
        ]);
        $this->balanceService->supplementDeduct($merchantId, $e2->id, '1.00', '快递费用调整：耗材费补扣');
        OrderExpressFeeAdjustment::create([
            'order_id' => $e2->id, 'type' => 'supplement', 'item' => 'material', 'amount' => '1.00',
            'reason' => '快递费用调整：耗材费补扣', 'created_at' => $this->ago(20),
        ]);
        // 费用调整后订单的售价、实扣、成本跟着变（同 ExpressOrderSettlementService）
        Order::query()->whereKey($e2->id)->update(['sale_price' => '15.50', 'deducted_amount' => '15.50', 'cost_price' => '13.50']);
        $this->workorder($e2, $yunyang, 'weight_verify', '商户反馈实际 1kg，按 3kg 计费，请核实重量', 'processing', [
            'supplier_reply' => '[已处理] 核实实际重量 1kg，退回运费 3 元（模拟回调）',
            'supplier_amount' => '3.00',
            'supplier_replied_at' => $this->ago(2),
        ]);
        $this->workorder($e2, $yunyang, 'urge_delivery', '收件人反馈三天未派送', 'completed', [
            'result_remark' => '云洋已催促，次日派送', 'resolved_at' => $this->ago(10),
        ]);
        $created[] = $this->row($e2, '已签收，有耗材费补扣；重量核实工单（云洋已回复）+ 已完成的催派送工单');

        $e3 = $this->order($merchantId, 'express', 'E', '14.00', '12.00', $yunyang);
        $this->balanceService->freeze($merchantId, $e3->id, '14.00');
        $this->balanceService->deduct($merchantId, $e3->id, '14.00');
        $this->finish($e3, 'success', '14.00', 48, completed: false);
        $this->express($e3, 'in_transit', [
            'estimated_freight' => '12.00', 'frozen_freight' => '12.00', 'actual_freight' => '12.00', 'freight_sale_price' => '14.00',
            'actual_insured_fee' => '0.00', 'actual_material_fee' => '0.00', 'actual_reverse_fee' => '0.00', 'fee_over_at' => $this->ago(47),
        ]);
        $this->workorder($e3, $yunyang, 'claim', '外箱破损，内物损坏，申请理赔', 'processing');
        $created[] = $this->row($e3, '运输中（已扣费）；理赔工单待结单，可测理赔调账');

        $e4 = $this->order($merchantId, 'express', 'E', '14.00', '12.00', $yunyang);
        $this->balanceService->freeze($merchantId, $e4->id, '14.00');
        $this->balanceService->unfreeze($merchantId, $e4->id, '14.00');
        $this->finish($e4, 'cancelled', null, 6);
        $this->express($e4, 'cancelled', ['estimated_freight' => '12.00', 'frozen_freight' => '12.00', 'freight_sale_price' => '14.00']);
        $created[] = $this->row($e4, '已取消，已解冻');

        // ---- 电影票 ----
        $m1 = $this->order($merchantId, 'movie', 'M', '42.00', '38.00', $mango);
        $this->balanceService->freeze($merchantId, $m1->id, '42.00');
        $this->balanceService->deduct($merchantId, $m1->id, '42.00');
        $this->finish($m1, 'success', '42.00', 26);
        $this->movie($m1, '3.00', [['code' => 'T8812-3366']], confirmed: true);
        MerchantRebate::create([
            'order_id' => $m1->id, 'merchant_id' => $merchantId, 'business_line' => 'movie', 'level_id' => (int) ($this->merchantDao->find($merchantId)?->level_id ?? 0),
            'rebate_base' => '3.00', 'rebate_base_source' => 'supplier', 'rebate_rate' => '0.8000', 'rebate_rate_source' => 'level',
            'amount' => '2.40', 'status' => 'pending', 'order_completed_at' => $this->ago(26), 'due_at' => Carbon::now()->addDays(5)->toDateTimeString(),
        ]);
        $created[] = $this->row($m1, '已出票，供应商返佣 3.00，商户返佣 2.40 待到账');

        $m2 = $this->order($merchantId, 'movie', 'M', '42.00', '38.00', $mango);
        $this->balanceService->freeze($merchantId, $m2->id, '42.00');
        $this->balanceService->deduct($merchantId, $m2->id, '42.00');
        $this->finish($m2, 'success', '42.00', 30);
        $this->movie($m2, null, [['code' => 'T8812-4477']], confirmed: true);
        ReconciliationDiff::query()->where('type', ReconciliationDiff::TYPE_REBATE)->where('order_id', $m2->id)->delete();
        ReconciliationDiff::create([
            'type' => ReconciliationDiff::TYPE_REBATE, 'order_id' => $m2->id, 'supplier_id' => $mango->id,
            'reconciliation_date' => date('Y-m-d'), 'field' => ReconciliationDiff::FIELD_REBATE_AMOUNT,
            'platform_value' => '0.00', 'supplier_value' => '2.50', 'diff_amount' => '-2.50',
            'status' => ReconciliationDiff::STATUS_OPEN, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $created[] = $this->row($m2, '已出票，供应商返佣未返回；今天的返佣对账报了一条差异');

        $m3 = $this->order($merchantId, 'movie', 'M', '42.00', '38.00', $mango);
        $this->balanceService->freeze($merchantId, $m3->id, '42.00');
        $this->movie($m3, null, [], confirmed: false);
        $created[] = $this->row($m3, '已锁座待确认出票（锁座有效期 2 小时后）');

        // ---- 话费争议：一条带卡速售售后进展，一条还没提交 ----
        $disputeOrders = Order::query()->where('merchant_id', $merchantId)->where('business_line', 'recharge')->where('status', 'success')
            ->whereNotExists(static fn ($q) => $q->selectRaw('1')->from('aftersale_disputes')->whereColumn('aftersale_disputes.order_id', 'orders.id'))
            ->orderByDesc('id')->limit(2)->get();
        $kasushou = Supplier::query()->where('driver', 'kasushou')->where('business_line', 'recharge')->orderBy('id')->first();
        foreach ($disputeOrders as $i => $order) {
            $attributes = ['order_id' => $order->id, 'merchant_id' => $merchantId, 'status' => 'processing', 'submitted_at' => $this->ago(3)];
            if ($i === 0 && $kasushou !== null) {
                $attributes += [
                    'supplier_id' => $kasushou->id,
                    'supplier_aftersale_no' => 'AS-MOCK-' . $order->id,
                    'supplier_aftersale_status' => AftersaleDispute::SUPPLIER_AFTERSALE_COMPLETED,
                    'supplier_aftersale_reply' => '运营商核实该号码已到账 100 元（模拟回调）',
                    'supplier_aftersale_submitted_at' => $this->ago(2),
                    'supplier_aftersale_updated_at' => $this->ago(1),
                ];
            }
            AftersaleDispute::create($attributes);
            $created[] = [
                'order_no' => $order->order_no, 'business_line' => 'recharge', 'status' => 'success',
                'note' => $i === 0 ? '争议处理中，卡速售售后已回复处理完成' : '争议处理中，还没提交卡速售售后',
            ];
        }

        return $created;
    }

    private function supplier(string $code, string $name, string $businessLine, string $driver): Supplier
    {
        $existing = Supplier::query()->where('code', $code)->first();
        if ($existing !== null) {
            return $existing;
        }

        return Supplier::create([
            'name' => $name,
            'code' => $code,
            'business_line' => $businessLine,
            'driver' => $driver,
            // 可以解密、能建出驱动，但地址不可达：页面上的供应商操作只会拿到网络失败
            'config' => $this->encryptor->encrypt(json_encode(['base_url' => 'https://example.invalid', 'app_id' => 'mock', 'secret_key' => 'mock', 'agent_id' => 'mock', 'token' => 'mock', 'tel' => '13800000000'])),
            'status' => 'disabled',
        ]);
    }

    private function order(int $merchantId, string $businessLine, string $prefix, string $price, string $cost, Supplier $supplier): Order
    {
        return Order::create([
            'order_no' => $prefix . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchantId,
            'merchant_order_no' => 'MOCK' . strtoupper($prefix) . date('YmdHis') . random_int(1000, 9999),
            'business_line' => $businessLine,
            'status' => 'processing',
            'sale_price' => $price,
            'cost_price' => $cost,
            'supplier_id' => $supplier->id,
            'supplier_order_no' => 'MOCK-' . strtoupper($prefix) . '-' . random_int(100000, 999999),
            'frozen_amount' => $price,
            'refunded_amount' => '0.00',
            'callback_url' => self::CALLBACK_URL,
        ]);
    }

    private function finish(Order $order, string $status, ?string $deducted, int $hoursAgo, bool $completed = true): void
    {
        $at = $this->ago($hoursAgo);
        Order::query()->whereKey($order->id)->update(array_filter([
            'status' => $status,
            'deducted_amount' => $deducted,
            'completed_at' => $status === 'success' && $completed ? $at : null,
            'finished_at' => $at,
            'created_at' => $this->ago($hoursAgo + 1),
        ], static fn ($v) => $v !== null));
    }

    /**
     * @param array<string, mixed> $fees
     */
    private function express(Order $order, string $logisticsStatus, array $fees): void
    {
        OrderExpress::create([
            'order_id' => $order->id,
            'express_company_code' => 'EXmock0001',
            'express_company_name' => '顺丰（联调）',
            'sender_info' => ['name' => '张三', 'mobile' => '13800000001', 'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'address' => '科技园 1 号'],
            'receiver_info' => ['name' => '李四', 'mobile' => '13900000002', 'province' => '上海市', 'city' => '上海市', 'district' => '浦东新区', 'address' => '世纪大道 100 号'],
            'item_info' => ['name' => '文件', 'volume' => null],
            'weight' => '1.00',
            'waybill_no' => $logisticsStatus === 'cancelled' ? null : 'SF' . random_int(1000000000, 9999999999),
            'logistics_status' => $logisticsStatus,
        ] + $fees);
    }

    /**
     * @param list<array<string, string>> $tickets
     */
    private function movie(Order $order, ?string $supplierRebate, array $tickets, bool $confirmed): void
    {
        OrderMovie::create([
            'order_id' => $order->id,
            'cinema_id' => 'C1001', 'cinema_name' => '万达影城（联调）',
            'film_id' => 'F2001', 'film_name' => '长安三万里',
            'show_id' => 'S' . $order->id, 'show_time' => Carbon::now()->addDays(2)->setTime(19, 30)->toDateTimeString(),
            'area_id' => null,
            'seats' => [['seat_code' => '5-7', 'row_label' => '5', 'col_label' => '7', 'love_status' => 0]],
            'seat_count' => 1, 'unit_price' => '42.00', 'unit_cost' => '38.00', 'mobile' => '13800000003',
            'lock_expire_at' => Carbon::now()->addHours(2)->toDateTimeString(),
            'confirmed_at' => $confirmed ? $this->ago(26) : null,
            'ticket_codes' => $tickets === [] ? null : $tickets,
            'supplier_rebate' => $supplierRebate,
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function workorder(Order $order, Supplier $supplier, string $type, string $content, string $status, array $extra = []): void
    {
        ExpressWorkorder::create([
            'order_id' => $order->id,
            'supplier_id' => $supplier->id,
            'type' => $type,
            'status' => $status,
            'content' => $content,
            'supplier_workorder_no' => 'WO-MOCK-' . random_int(100000, 999999),
            'submitted_by' => 0,
        ] + $extra);
    }

    /**
     * @return array{order_no: string, business_line: string, status: string, note: string}
     */
    private function row(Order $order, string $note): array
    {
        $order->refresh();

        return ['order_no' => $order->order_no, 'business_line' => $order->business_line, 'status' => $order->status, 'note' => $note];
    }

    private function ago(int $hours): string
    {
        return Carbon::now()->subHours($hours)->toDateTimeString();
    }
}
