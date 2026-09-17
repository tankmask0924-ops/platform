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

use App\Dao\MerchantRateLimitDao;
use App\Dao\SystemSettingDao;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 商户实际生效的限流值（requirements.md 8.1「平台设默认值，可对单个商户单独调整」）。
 * 后台商户详情（App\Service\Admin\MerchantAdminService）和开放 API 限流
 * （App\Middleware\OpenApiSignatureMiddleware）共用这一份取值规则，不各自实现。
 */
class RateLimitSettingService extends AbstractService
{
    /**
     * docs/database-design.md「system_settings」里的默认限流 key，及零配置时的
     * 代码级兜底值（跟文档里的默认值 50 一致）。
     */
    public const DEFAULT_SETTING_KEY = 'default_rate_limit_per_second';

    public const DEFAULT_LIMIT_PER_SECOND = 50;

    #[Inject]
    protected MerchantRateLimitDao $merchantRateLimitDao;

    #[Inject]
    protected SystemSettingDao $systemSettingDao;

    /**
     * 有单独配置就用单独配置，否则用全局默认。`is_custom` 让后台能区分
     * 「单独设成了 50」和「没设、走默认 50」。
     *
     * 全局默认值配置得不合法（非正整数）时退回代码级兜底值，避免一行写坏的
     * system_settings 把所有商户的开放 API 全部限死。
     *
     * @return array{limit_per_second: int, is_custom: bool}
     */
    public function effectiveLimit(int $merchantId): array
    {
        $custom = $this->merchantRateLimitDao->findByMerchantId($merchantId);
        if ($custom) {
            return ['limit_per_second' => $custom->limit_per_second, 'is_custom' => true];
        }

        $default = $this->systemSettingDao->getValue(self::DEFAULT_SETTING_KEY, self::DEFAULT_LIMIT_PER_SECOND);
        $default = is_numeric($default) ? (int) $default : 0;

        return [
            'limit_per_second' => $default >= 1 ? $default : self::DEFAULT_LIMIT_PER_SECOND,
            'is_custom' => false,
        ];
    }
}
