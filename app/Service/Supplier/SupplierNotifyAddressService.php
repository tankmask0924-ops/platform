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

namespace App\Service\Supplier;

use App\Dao\SupplierDao;
use App\Exception\SupplierNotFoundException;
use App\Model\Supplier;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

use function Hyperf\Support\env;

/**
 * 供应商回调地址（requirements.md 6.3）：`{SUPPLIER_NOTIFY_BASE_URL}/notify/{编码}/{令牌}`，
 * 商品变更通知在后面加 `/goods`。令牌每个供应商一个（`suppliers.notify_token`），
 * 回调不做来源 IP 白名单，靠令牌挡掉随便猜编码的请求，真实性再靠查询接口兜底。
 *
 * SUPPLIER_NOTIFY_BASE_URL 是供应商能访问到的平台公网地址。没配置时仍然给出一个
 * 本机地址让下单能正常进行（卡速售下单必须带 url），但供应商回调到不了，订单结果
 * 只能靠定时查询，后台供应商页会提示。
 */
class SupplierNotifyAddressService extends AbstractService
{
    public const BASE_URL_ENV = 'SUPPLIER_NOTIFY_BASE_URL';

    private const FALLBACK_BASE_URL = 'http://127.0.0.1:9501';

    #[Inject]
    protected SupplierDao $supplierDao;

    public function isConfigured(): bool
    {
        return $this->configuredBaseUrl() !== null;
    }

    public function orderNotifyUrl(Supplier $supplier): string
    {
        return sprintf('%s/notify/%s/%s', $this->baseUrl(), rawurlencode($supplier->code), $supplier->notify_token);
    }

    public function goodsNotifyUrl(Supplier $supplier): string
    {
        return $this->orderNotifyUrl($supplier) . '/goods';
    }

    /**
     * 芒果影院更新回调地址（mango.md：影院数据变化时推送，只发一次），同样带令牌。
     */
    public function cinemaNotifyUrl(Supplier $supplier): string
    {
        return $this->orderNotifyUrl($supplier) . '/cinema';
    }

    /**
     * 卡速售售后处理结果回调地址（提交售后申请时逐单传给卡速售），同样带令牌。
     */
    public function aftersaleNotifyUrl(Supplier $supplier): string
    {
        return $this->orderNotifyUrl($supplier) . '/aftersale';
    }

    /**
     * 按编码和令牌找供应商；编码不存在和令牌不对都当成找不到，不区分，免得被用来探测编码。
     */
    public function resolve(string $code, string $token): Supplier
    {
        $supplier = $this->supplierDao->findByCode($code);
        if ($supplier === null || $supplier->notify_token === '' || ! hash_equals($supplier->notify_token, $token)) {
            throw new SupplierNotFoundException('unknown supplier notify address');
        }

        return $supplier;
    }

    private function baseUrl(): string
    {
        return $this->configuredBaseUrl() ?? self::FALLBACK_BASE_URL;
    }

    private function configuredBaseUrl(): ?string
    {
        $raw = env(self::BASE_URL_ENV);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return rtrim(trim($raw), '/');
    }
}
