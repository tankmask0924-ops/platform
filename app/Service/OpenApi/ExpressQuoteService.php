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

namespace App\Service\OpenApi;

use App\Dao\SupplierDao;
use App\Exception\OpenApiException;
use App\Express\ExpressChannelCodec;
use App\Model\Merchant;
use App\Model\Supplier;
use App\OpenApi\ErrorCode;
use App\Service\AbstractService;
use App\Service\Merchant\SubscriptionService;
use App\Service\Product\PricingRuleService;
use App\Supplier\SupplierDriverFactory;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Throwable;

/**
 * 开放 API「快递查价」（requirements.md 7.2 第一步、8.1，docs/modules.md 第 6 节）：
 * 按寄收件地址和重量检测可用快递渠道，返回**加价后的预估售价**和可选的快递公司列表。
 *
 * 几条硬规则，改这个类之前先看：
 *
 * 1. **只对运费加价**（requirements.md 5.1、7.2）：保价费、耗材费按成本原样转给商户。
 *    所以这里只把 `freight` 传进 PricingRuleService::salePriceFor()，其余项直接相加。
 * 2. **不暴露成本**：返回给商户的 `freight` 是加价后的售价，不是供应商成本；供应商渠道 ID
 *    也不外泄，换成平台自己的渠道编号（App\Express\ExpressChannelCodec）。
 * 3. **不缓存报价**：云洋没有报价单号也没有有效期（yunyang.md 第 1 节），下单时必须重新
 *    检测渠道取最新价格（requirements.md 7.2），这里查到的价只是给商户看的预估。
 * 4. **商户传了保价金额时，只返回支持保价的渠道**（yunyang.md 第 4 节 `allowInsured=1`）：
 *    否则商户会选中一个下单时才发现不能保价的渠道。
 * 5. **单个供应商查失败不影响整体**：多家快递供应商时逐个查、失败只记日志；
 *    但**一家都没查成**（没有启用中的快递供应商、驱动建不起来、全部异常）要报
 *    ErrorCode::ExpressChannelUnavailable，不能返回空列表——空列表的含义是"这个地址和
 *    重量确实没有可用渠道"，跟"平台这边没查成"是两件完全不同的事，混在一起商户会去改地址。
 *
 * 【透传给云洋的字段名是推断】平台对外的参数形状是本次设计的（见 validate()），转成云洋
 * `content` 时的字段拼写没有可核对的报文样例，跟 YunyangDriver 里的 serviceCode 一样是
 * 按文档行文拼的，集中在 toSupplierContent() 一个方法里，联调对不上只改这一处。
 */
class ExpressQuoteService extends AbstractService
{
    public const BUSINESS_LINE = 'express';

    /** 重量按整公斤传给云洋（yunyang.md 第 4 节），这里限定上限防手滑 */
    private const MAX_WEIGHT_KG = 1000;

    #[Inject]
    protected SupplierDao $supplierDao;

    #[Inject]
    protected SupplierDriverFactory $supplierDriverFactory;

    #[Inject]
    protected PricingRuleService $pricingRuleService;

    #[Inject]
    protected SubscriptionService $subscriptionService;

    #[Inject]
    protected ExpressChannelCodec $channelCodec;

    #[Inject]
    protected LoggerFactory $loggerFactory;

    /**
     * @param array<string, mixed> $params 商户传进来的原始参数
     * @return array{channels: list<array<string, mixed>>}
     */
    public function quote(Merchant $merchant, array $params): array
    {
        if (! $this->subscriptionService->isSubscribed((int) $merchant->id, self::BUSINESS_LINE)) {
            throw new OpenApiException(ErrorCode::BusinessNotSubscribed);
        }

        $request = $this->validate($params);
        $suppliers = $this->supplierDao->newQuery()
            ->where('status', 'active')
            ->where('business_line', self::BUSINESS_LINE)
            ->orderBy('id')
            ->get();

        $channels = [];
        $queriedAny = false;
        foreach ($suppliers as $supplier) {
            /* @var Supplier $supplier */
            try {
                $driver = $this->supplierDriverFactory->buildYunyang($supplier);
                $supplierChannels = $driver->checkChannels($this->toSupplierContent($request));
            } catch (Throwable $e) {
                $this->loggerFactory->get('express')->error('express quote failed for supplier', [
                    'supplier_id' => $supplier->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $queriedAny = true;
            foreach ($supplierChannels as $channel) {
                $priced = $this->priceChannel($supplier, $channel, $request['insured_amount']);
                if ($priced !== null) {
                    $channels[] = $priced;
                }
            }
        }

        if (! $queriedAny) {
            // 一家都没查成，不是"没有可用渠道"，见类注释第 5 条
            throw new OpenApiException(ErrorCode::ExpressChannelUnavailable);
        }

        // 便宜的排前面：商户最常见的选择依据，也让返回顺序稳定
        usort($channels, static fn (array $a, array $b) => bccomp($a['total_price'], $b['total_price'], 2));

        return ['channels' => $channels];
    }

    /**
     * 单个渠道的定价。返回 null 表示这个渠道不该给商户看到。
     *
     * @param array<string, mixed> $channel YunyangDriver::checkChannels() 归一化后的渠道
     * @return null|array<string, mixed>
     */
    private function priceChannel(Supplier $supplier, array $channel, ?string $insuredAmount): ?array
    {
        $channelId = $channel['channel_id'] ?? null;
        $freightCost = $channel['freight'] ?? null;
        if (! is_string($channelId) || $channelId === '' || ! is_string($freightCost)) {
            // 渠道 id 或运费缺失的渠道没法下单也没法报价，直接跳过
            return null;
        }
        // 商户要保价，就只给能保价的渠道（见类注释第 4 条）
        if ($insuredAmount !== null && ! $channel['allow_insured']) {
            return null;
        }

        $freight = $this->pricingRuleService->salePriceFor(self::BUSINESS_LINE, $freightCost);
        $insuredFee = $this->money($channel['freight_insured'] ?? null);
        $materialFee = $this->money($channel['freight_haocai'] ?? null);

        return [
            'channel_code' => $this->channelCodec->encode((int) $supplier->id, $channelId),
            'company_name' => $channel['channel_name'],
            // 运费是加价后的售价；保价费、耗材费按成本转给商户
            'freight' => $freight,
            'insured_fee' => $insuredFee,
            'material_fee' => $materialFee,
            'total_price' => bcadd(bcadd($freight, $insuredFee, 2), $materialFee, 2),
            'billing_rule' => $channel['billing_rule'],
            'allow_insured' => $channel['allow_insured'],
            'appointment_times' => $channel['appointment_times'],
        ];
    }

    /**
     * 参数校验。平台对外的参数形状在这里定义（不是照抄云洋的字段名——商户不该被供应商的
     * 字段命名绑住，换供应商时对外形状也不用变）。
     *
     * **全部是扁平的标量参数**，不用嵌套对象：开放 API 的签名算法是"除 sign 外的参数按 key
     * 排序拼成 k1=v1&k2=v2 再 HMAC"（requirements.md 8.1，App\Signature\SignatureSigner），
     * 嵌套数组根本没法参与这个拼接。寄收件地址拆成 `sender_province` 这样的前缀字段，
     * 商户按现有的签名方式签就行，不需要为这一个接口再定一套签名规则。
     *
     * @param array<string, mixed> $params
     * @return array{sender: array<string, string>, receiver: array<string, string>, weight: int,
     *     volume: null|array{length: int, width: int, height: int}, insured_amount: null|string}
     */
    private function validate(array $params): array
    {
        $sender = $this->validateAddress($params, 'sender');
        $receiver = $this->validateAddress($params, 'receiver');

        $weight = $params['weight'] ?? null;
        // 云洋的重量是整数公斤，不整就没法下单，查价阶段就按同一个口径拒掉，
        // 免得商户查到价、下单时才发现重量不合法
        if (! is_numeric($weight) || (int) $weight != $weight || (int) $weight < 1 || (int) $weight > self::MAX_WEIGHT_KG) {
            throw new OpenApiException(ErrorCode::InvalidParams, 'weight 必须是 1 ~ ' . self::MAX_WEIGHT_KG . ' 之间的整数（公斤）');
        }

        // 体积三边要么都不传，要么都传：只传一两边算不出体积重，静默忽略会让商户
        // 以为自己传了体积
        $volumeKeys = ['length', 'width', 'height'];
        $given = array_filter($volumeKeys, static fn (string $side) => isset($params[$side]) && $params[$side] !== '');
        $volume = null;
        if ($given !== []) {
            if (count($given) !== count($volumeKeys)) {
                throw new OpenApiException(ErrorCode::InvalidParams, 'length / width / height 要么都不传，要么都传');
            }
            $volume = [];
            foreach ($volumeKeys as $side) {
                $value = $params[$side];
                if (! is_numeric($value) || (int) $value != $value || (int) $value < 1) {
                    throw new OpenApiException(ErrorCode::InvalidParams, $side . ' 必须是正整数（厘米）');
                }
                $volume[$side] = (int) $value;
            }
        }

        $insuredAmount = $params['insured_amount'] ?? null;
        if ($insuredAmount !== null && $insuredAmount !== '') {
            if (! is_numeric($insuredAmount) || bccomp((string) $insuredAmount, '0', 2) <= 0) {
                throw new OpenApiException(ErrorCode::InvalidParams, 'insured_amount 必须是大于 0 的金额');
            }
            $insuredAmount = bcadd((string) $insuredAmount, '0', 2);
        } else {
            $insuredAmount = null;
        }

        return [
            'sender' => $sender,
            'receiver' => $receiver,
            'weight' => (int) $weight,
            'volume' => $volume,
            'insured_amount' => $insuredAmount,
        ];
    }

    /**
     * 查价只需要到区县一级（运费按地区算），详细地址下单时才要，所以这里不强制要
     * `{prefix}_address`。
     *
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private function validateAddress(array $params, string $prefix): array
    {
        $result = [];
        foreach (['province', 'city', 'district'] as $part) {
            $value = $params[$prefix . '_' . $part] ?? null;
            if (! is_string($value) || trim($value) === '') {
                throw new OpenApiException(ErrorCode::InvalidParams, $prefix . '_' . $part . ' 不能为空');
            }
            $result[$part] = trim($value);
        }
        $detail = $params[$prefix . '_address'] ?? null;
        $result['address'] = is_string($detail) ? trim($detail) : '';

        return $result;
    }

    /**
     * 平台参数 → 云洋 `content`。字段拼写是推断，见类注释。
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function toSupplierContent(array $request): array
    {
        $content = [
            'senderProvince' => $request['sender']['province'],
            'senderCity' => $request['sender']['city'],
            'senderDistrict' => $request['sender']['district'],
            'senderAddress' => $request['sender']['address'],
            'receiverProvince' => $request['receiver']['province'],
            'receiverCity' => $request['receiver']['city'],
            'receiverDistrict' => $request['receiver']['district'],
            'receiverAddress' => $request['receiver']['address'],
            'weight' => $request['weight'],
        ];
        if ($request['volume'] !== null) {
            $content += [
                'length' => $request['volume']['length'],
                'width' => $request['volume']['width'],
                'height' => $request['volume']['height'],
            ];
        }
        if ($request['insured_amount'] !== null) {
            $content['insured'] = $request['insured_amount'];
        }

        return $content;
    }

    private function money(mixed $value): string
    {
        return is_string($value) && is_numeric($value) ? bcadd($value, '0', 2) : '0.00';
    }
}
