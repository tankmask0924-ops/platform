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

        $channels = [];
        $queriedAny = false;
        foreach ($this->querySuppliers($request) as [$supplier, $supplierChannels]) {
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
     * 下单用：按商户选的平台渠道编号**重新查价**，换回供应商和供应商渠道
     * （requirements.md 7.2「下单前重新向供应商检测价格，不采用商户传入的金额」）。
     * 跟 quote() 同一套查询、同一套保价过滤，查到的就是商户此刻查价会看到的那个渠道。
     *
     * 一家都没查成报 ExpressChannelUnavailable（同 quote()）；查成了但编号对不上任何渠道
     * （渠道下线了、换了地址/重量、或者编号是乱填的）报 ExpressChannelNotFound，让商户重新查价。
     *
     * @param array<string, mixed> $request validate() 的返回值
     * @return array{supplier: Supplier, channel: array<string, mixed>}
     */
    public function resolveChannel(array $request, string $channelCode): array
    {
        $queriedAny = false;
        foreach ($this->querySuppliers($request) as [$supplier, $supplierChannels]) {
            $queriedAny = true;
            foreach ($supplierChannels as $channel) {
                $channelId = $channel['channel_id'] ?? null;
                if (! is_string($channelId) || $channelId === ''
                    || ! $this->channelCodec->matches($channelCode, (int) $supplier->id, $channelId)) {
                    continue;
                }
                if (! is_string($channel['freight'] ?? null)
                    || ($request['insured_amount'] !== null && ! $channel['allow_insured'])) {
                    // 能对上编号但这次不能下（没有运费、要保价却不支持保价）：跟查价时
                    // priceChannel() 把它藏起来是同一个口径
                    throw new OpenApiException(ErrorCode::ExpressChannelNotFound);
                }

                return ['supplier' => $supplier, 'channel' => $channel];
            }
        }

        if (! $queriedAny) {
            throw new OpenApiException(ErrorCode::ExpressChannelUnavailable);
        }

        throw new OpenApiException(ErrorCode::ExpressChannelNotFound);
    }

    /**
     * 参数校验，平台对外的参数形状在这里定义（不是照抄云洋的字段名——商户不该被供应商的
     * 字段命名绑住，换供应商时对外形状也不用变）。
     *
     * **全部是扁平的标量参数**，不用嵌套对象：开放 API 的签名算法是"除 sign 外的参数按 key
     * 排序拼成 k1=v1&k2=v2 再 HMAC"（requirements.md 8.1，App\Signature\SignatureSigner），
     * 嵌套数组根本没法参与这个拼接。寄收件地址拆成 `sender_province` 这样的前缀字段，
     * 商户按现有的签名方式签就行，不需要为这一个接口再定一套签名规则。
     *
     * `$forOrder`：下单比查价多要求寄收件人姓名、电话、详细地址和物品名称；查价只需要到
     * 区县（运费按地区算）。两边共用同一个校验，保证"查价能过的地址和重量，下单也认"。
     *
     * @param array<string, mixed> $params
     * @return array{sender: array<string, string>, receiver: array<string, string>, weight: int,
     *     volume: null|array{length: int, width: int, height: int}, insured_amount: null|string,
     *     item_name: null|string}
     */
    public function validate(array $params, bool $forOrder = false): array
    {
        $sender = $this->validateAddress($params, 'sender', $forOrder);
        $receiver = $this->validateAddress($params, 'receiver', $forOrder);

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

        $itemName = null;
        if ($forOrder) {
            $itemName = $this->requiredString($params, 'item_name', 64);
        }

        return [
            'sender' => $sender,
            'receiver' => $receiver,
            'weight' => (int) $weight,
            'volume' => $volume,
            'insured_amount' => $insuredAmount,
            'item_name' => $itemName,
        ];
    }

    /**
     * 平台参数 → 云洋 `content`。字段拼写是推断，见类注释。下单时（校验过的参数里带了
     * 姓名电话和物品名称）一并带上。
     *
     * @param array<string, mixed> $request validate() 的返回值
     * @return array<string, mixed>
     */
    public function toSupplierContent(array $request): array
    {
        $content = [];
        foreach (['sender', 'receiver'] as $side) {
            foreach (['name' => 'Name', 'mobile' => 'Mobile', 'province' => 'Province', 'city' => 'City', 'district' => 'District', 'address' => 'Address'] as $key => $suffix) {
                if (isset($request[$side][$key])) {
                    $content[$side . $suffix] = $request[$side][$key];
                }
            }
        }
        $content['weight'] = $request['weight'];
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
        if (($request['item_name'] ?? null) !== null) {
            $content['itemName'] = $request['item_name'];
        }

        return $content;
    }

    /**
     * 逐家查询启用中的快递供应商，单家失败只记日志跳过（见类注释第 5 条）。
     * 只 yield 查成了的供应商——调用方靠"有没有 yield 过"区分"没有可用渠道"和"一家都没查成"。
     *
     * @param array<string, mixed> $request
     * @return iterable<array{0: Supplier, 1: list<array<string, mixed>>}>
     */
    private function querySuppliers(array $request): iterable
    {
        $suppliers = $this->supplierDao->newQuery()
            ->where('status', 'active')
            ->where('business_line', self::BUSINESS_LINE)
            ->orderBy('id')
            ->get();

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

            yield [$supplier, $supplierChannels];
        }
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
     * 查价只需要到区县一级（运费按地区算），详细地址下单时才要；下单还要姓名和电话。
     *
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private function validateAddress(array $params, string $prefix, bool $forOrder): array
    {
        $result = [];
        foreach (['province', 'city', 'district'] as $part) {
            $value = $params[$prefix . '_' . $part] ?? null;
            if (! is_string($value) || trim($value) === '') {
                throw new OpenApiException(ErrorCode::InvalidParams, $prefix . '_' . $part . ' 不能为空');
            }
            $result[$part] = trim($value);
        }

        if (! $forOrder) {
            $detail = $params[$prefix . '_address'] ?? null;
            $result['address'] = is_string($detail) ? trim($detail) : '';

            return $result;
        }

        $result['address'] = $this->requiredString($params, $prefix . '_address', 200);
        $result['name'] = $this->requiredString($params, $prefix . '_name', 32);
        $mobile = $this->requiredString($params, $prefix . '_mobile', 20);
        // 手机或座机（区号-号码），只挡明显不是电话的输入，真实性由快递员上门时确认
        if (! preg_match('/^[0-9+\-]{7,20}$/', $mobile)) {
            throw new OpenApiException(ErrorCode::InvalidParams, $prefix . '_mobile 格式不正确');
        }
        $result['mobile'] = $mobile;

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function requiredString(array $params, string $key, int $maxLength): string
    {
        $value = $params[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new OpenApiException(ErrorCode::InvalidParams, $key . ' 不能为空');
        }
        $value = trim($value);
        if (mb_strlen($value) > $maxLength) {
            throw new OpenApiException(ErrorCode::InvalidParams, $key . ' 不能超过 ' . $maxLength . ' 个字');
        }

        return $value;
    }

    private function money(mixed $value): string
    {
        return is_string($value) && is_numeric($value) ? bcadd($value, '0', 2) : '0.00';
    }
}
