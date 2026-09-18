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

use App\Dao\MerchantDao;
use App\Dao\MerchantNotifyLogDao;
use App\Dao\OrderAttemptDao;
use App\Dao\OrderDao;
use App\Dao\OrderRechargeDao;
use App\Dao\ProductDao;
use App\Dao\SupplierDao;
use App\Model\Order;
use App\OpenApi\ErrorCode;
use App\Service\AbstractService;
use App\Service\Merchant\BalanceService;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use InvalidArgumentException;

/**
 * 前端联调用：给指定商户造一批话费模拟订单，覆盖成功/失败/处理中/异常、超过争议时限、
 * 回调全部失败等状态。只写库，不调用供应商、不发回调。
 *
 * 余额走真实的 BalanceService（freeze → deduct / unfreeze），商户余额、冻结金额和资金流水
 * 跟订单对得上，管理端处理异常单、确认退款时不会把余额算乱。
 * 回调地址指向本机不存在的端口，在页面上点"重推回调"只会失败，不会打到外部。
 */
class MockOrderService extends AbstractService
{
    private const CALLBACK_URL = 'http://127.0.0.1:9/mock-callback';

    #[Inject]
    protected ConfigInterface $config;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected ProductDao $productDao;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected OrderDao $orderDao;

    #[Inject]
    protected OrderRechargeDao $orderRechargeDao;

    #[Inject]
    protected OrderAttemptDao $orderAttemptDao;

    #[Inject]
    protected MerchantNotifyLogDao $notifyLogDao;

    #[Inject]
    protected BalanceService $balanceService;

    /**
     * @return list<array{order_no: string, status: string, sale_price: string, note: string}>
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

        $product = $this->productDao->newQuery()->where('business_line', 'recharge')->orderBy('id')->first();
        $supplier = $this->supplierDao->newQuery()->where('business_line', 'recharge')->orderBy('id')->first();
        if ($product === null || $supplier === null) {
            throw new InvalidArgumentException('库里至少要有一个话费商品和一个话费供应商');
        }

        // 失败单放在前面：先冻结再解冻，不占最终余额
        $scenarios = [
            ['status' => 'failed', 'price' => '30.00', 'hours' => 30, 'fail' => ErrorCode::OrderFailed, 'attempt' => 'failed', 'notify' => ['ok'], 'note' => '供应商返回失败，已解冻'],
            ['status' => 'failed', 'price' => '8.00', 'hours' => 6, 'fail' => ErrorCode::NoSupplierAvailable, 'attempt' => null, 'notify' => ['ok'], 'note' => '没有可用供应商'],
            ['status' => 'success', 'price' => '10.00', 'hours' => 1, 'attempt' => 'success', 'notify' => ['ok'], 'note' => '成功，可提交争议'],
            ['status' => 'success', 'price' => '20.00', 'hours' => 50, 'attempt' => 'success', 'notify' => ['500', 'timeout', 'ok'], 'note' => '成功，回调第 3 次才成功'],
            ['status' => 'success', 'price' => '12.00', 'hours' => 3, 'attempt' => 'success', 'notify' => array_fill(0, 7, '500'), 'note' => '成功，回调 7 次都失败，可测重推'],
            ['status' => 'success', 'price' => '5.00', 'hours' => 24 * 10, 'attempt' => 'success', 'notify' => ['ok'], 'note' => '成功，已超过争议时限'],
            ['status' => 'processing', 'price' => '10.00', 'hours' => 0, 'attempt' => 'processing', 'notify' => [], 'note' => '处理中，金额冻结'],
            ['status' => 'abnormal', 'price' => '15.00', 'hours' => 26, 'attempt' => 'unknown', 'notify' => [], 'note' => '异常单，待管理端人工处理'],
        ];

        $created = [];
        foreach ($scenarios as $i => $scenario) {
            $order = $this->createOrder($merchantId, $product->id, $supplier->id, $i, $scenario);
            $created[] = [
                'order_no' => $order->order_no,
                'status' => $order->status,
                'sale_price' => $scenario['price'],
                'note' => $scenario['note'],
            ];
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private function createOrder(int $merchantId, int $productId, int $supplierId, int $index, array $scenario): Order
    {
        $price = $scenario['price'];
        $createdAt = date('Y-m-d H:i:s', time() - $scenario['hours'] * 3600 - 300);
        $finishedAt = date('Y-m-d H:i:s', strtotime($createdAt) + 20);
        $status = $scenario['status'];

        /** @var Order $order */
        $order = $this->orderDao->create([
            'order_no' => 'R' . date('YmdHis') . random_int(100000, 999999),
            'merchant_id' => $merchantId,
            'merchant_order_no' => 'MOCK' . date('YmdHis') . $index . random_int(100, 999),
            'business_line' => 'recharge',
            'status' => 'processing',
            'sale_price' => $price,
            'cost_price' => bcmul($price, '0.98', 2),
            'supplier_id' => $scenario['attempt'] === null ? null : $supplierId,
            'frozen_amount' => $price,
            'refunded_amount' => '0.00',
            'callback_url' => self::CALLBACK_URL,
        ]);
        $this->orderRechargeDao->create([
            'order_id' => $order->id,
            'product_id' => $productId,
            'recharge_account' => '1380013' . str_pad((string) (8000 + $index), 4, '0', STR_PAD_LEFT),
            'rebate_amount' => '0.00',
        ]);

        if (! $this->balanceService->freeze($merchantId, $order->id, $price)) {
            throw new InvalidArgumentException("商户可用余额不足 {$price}，先在管理端给商户调账加款（这批订单一共要占用约 72 元）");
        }

        $attrs = ['status' => $status, 'created_at' => $createdAt, 'updated_at' => $finishedAt];
        if ($status === 'success') {
            $this->balanceService->deduct($merchantId, $order->id, $price);
            $attrs += [
                'deducted_amount' => $price,
                'supplier_order_no' => 'MOCK-SUP-' . $order->id,
                'completed_at' => $finishedAt,
                'finished_at' => $finishedAt,
            ];
        } elseif ($status === 'failed') {
            $this->balanceService->unfreeze($merchantId, $order->id, $price);
            $attrs += ['fail_reason' => $scenario['fail']->message(), 'finished_at' => $finishedAt];
        }
        // 直接按主键写：created_at 不在 fillable 里，走 fill() 会被丢掉
        $this->orderDao->newQuery()->whereKey($order->id)->update($attrs);

        if ($scenario['attempt'] !== null) {
            $attempt = $this->orderAttemptDao->create([
                'order_id' => $order->id,
                'supplier_id' => $supplierId,
                'attempt_no' => 1,
                'result' => $scenario['attempt'],
                'fail_reason' => $scenario['attempt'] === 'failed' ? '模拟：供应商返回充值失败' : null,
                'request_snapshot' => ['mock' => true],
                'response_snapshot' => ['mock' => true, 'result' => $scenario['attempt']],
            ]);
            $this->orderAttemptDao->newQuery()->whereKey($attempt->id)->update(['created_at' => $createdAt, 'updated_at' => $finishedAt]);
        }

        foreach ($scenario['notify'] as $n => $outcome) {
            $this->notifyLogDao->create([
                'order_id' => $order->id,
                'url' => self::CALLBACK_URL,
                'payload' => ['order_no' => $order->order_no, 'status' => $status, 'mock' => true],
                'response_body' => match ($outcome) {
                    'ok' => 'success',
                    '500' => 'Internal Server Error',
                    default => null,
                },
                'http_status' => match ($outcome) {
                    'ok' => 200,
                    '500' => 500,
                    default => null,
                },
                'attempt_no' => $n + 1,
                'success' => $outcome === 'ok',
                'created_at' => date('Y-m-d H:i:s', strtotime($finishedAt) + $n * 60),
            ]);
        }

        return $order->refresh();
    }
}
