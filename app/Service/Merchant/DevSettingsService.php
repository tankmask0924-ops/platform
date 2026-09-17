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

use App\Crypto\Encryptor;
use App\Dao\MerchantDao;
use App\Model\Merchant;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户管理后台（web/merchant）「开发设置」：生成/重置 AppKey、AppSecret，配置 IP 白名单
 * （requirements.md 4.1、8.2）。
 *
 * 跟 App\Middleware\OpenApiSignatureMiddleware 的关系：那边验签时会
 * `$this->encryptor->decrypt($merchant->app_secret)` 拿明文去验签，这边生成/重置时
 * 用同一个 Encryptor 加密后存库，两边共享同一份密钥（APP_ENCRYPTION_KEY），互不感知实现细节。
 * 重置密钥后旧密文自然失效——OpenApiSignatureMiddleware 每次都是现读现解密当前存的那份，
 * 不存在旧 token/旧密钥缓存问题，不需要额外处理「旧签名失效」。
 *
 * AppKey / AppSecret 格式（本任务判断，requirements.md 没有规定具体格式）：
 * - AppKey：`ak_` 前缀 + 32 位十六进制（`bin2hex(random_bytes(16))`，128 bit 随机），
 *   前缀纯粹是方便人眼识别「这是个 AppKey」，随机性跟 App\Auth\MerchantJwtGuard 签发的
 *   登录态 JWT 不是一回事（那是自包含 token，不是纯随机字节串），这里单纯参照同项目里
 *   其它随机凭证一贯的量级。varchar(64) 列宽绰绰有余。
 * - AppSecret：`bin2hex(random_bytes(32))`，64 位十六进制（256 bit 随机），任务描述里
 *   直接给出的写法，不加前缀——它只在生成时的响应体里出现一次，不需要人眼识别。
 * - AppKey 唯一性：生成前查一次 MerchantDao::findByAppKey() 避免撞库，128 bit 随机空间下
 *   实际冲突概率可忽略，不做 DB 唯一约束异常兜底（对比 AuthService::register() 对手机号/
 *   邮箱唯一性做的兜底捕获——那是因为手机号只有十进制 11 位、场景可预期到并发注册撞同一个
 *   手机号，这里的随机 128 bit key 撞库属于天文数字级别，暂不处理）。
 *
 * ip_whitelist 空数组的含义：「不做限制」——“空数组”与“从未配置（null）”效果等价。
 * 强制校验在 App\Middleware\OpenApiSignatureMiddleware 里（判定规则见
 * App\Network\IpWhitelist，按“空/null 即放行”实现），这里只做配置 CRUD。
 */
class DevSettingsService extends AbstractService
{
    private const APP_KEY_PREFIX = 'ak_';

    private const APP_KEY_RANDOM_BYTES = 16;

    private const APP_SECRET_RANDOM_BYTES = 32;

    private const MAX_APP_KEY_GENERATION_ATTEMPTS = 5;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected Encryptor $encryptor;

    /**
     * @return array{app_key: null|string, app_secret_generated: bool, app_secret_reset_at: null|string, ip_whitelist: array}
     */
    public function getSettings(Merchant $merchant): array
    {
        return [
            'app_key' => $merchant->app_key,
            // 只回传「是否生成过」，绝不回传加密或明文的 app_secret 本身（requirements.md 4.1：
            // AppSecret 只在生成/重置那一次响应里完整显示一次，之后连商户自己都不能再查看）。
            'app_secret_generated' => $merchant->app_secret !== null,
            'app_secret_reset_at' => $merchant->app_secret_reset_at,
            'ip_whitelist' => $merchant->ip_whitelist ?? [],
        ];
    }

    /**
     * @return array{app_key: string, app_secret: string, app_secret_reset_at: string}
     */
    public function generateAppKey(Merchant $merchant): array
    {
        if ($merchant->status !== 'active') {
            throw new HttpException(403, '商户审核通过后才能生成接口密钥');
        }

        if ($merchant->app_key !== null) {
            throw new HttpException(409, '已存在 AppKey，如需更换密钥请使用重置 AppSecret 接口');
        }

        $appKey = $this->generateUniqueAppKey();
        $plainSecret = $this->generateAppSecret();
        $resetAt = date('Y-m-d H:i:s');

        $this->merchantDao->update($merchant->id, [
            'app_key' => $appKey,
            'app_secret' => $this->encryptor->encrypt($plainSecret),
            'app_secret_reset_at' => $resetAt,
        ]);

        return [
            'app_key' => $appKey,
            'app_secret' => $plainSecret,
            'app_secret_reset_at' => $resetAt,
        ];
    }

    /**
     * @return array{app_key: string, app_secret: string, app_secret_reset_at: string}
     */
    public function resetAppSecret(Merchant $merchant): array
    {
        if ($merchant->status !== 'active') {
            throw new HttpException(403, '商户审核通过后才能重置接口密钥');
        }

        if ($merchant->app_key === null) {
            throw new HttpException(409, '尚未生成 AppKey，请先生成接口密钥');
        }

        $plainSecret = $this->generateAppSecret();
        $resetAt = date('Y-m-d H:i:s');

        $this->merchantDao->update($merchant->id, [
            'app_secret' => $this->encryptor->encrypt($plainSecret),
            'app_secret_reset_at' => $resetAt,
        ]);

        return [
            'app_key' => $merchant->app_key,
            'app_secret' => $plainSecret,
            'app_secret_reset_at' => $resetAt,
        ];
    }

    /**
     * @param mixed $ips 期望是 JSON 数组（PUT body 顶层就是数组，不是 {ip_whitelist: [...]} 这种包了一层的对象）
     * @return array{ip_whitelist: array<int, string>}
     */
    public function updateIpWhitelist(Merchant $merchant, mixed $ips): array
    {
        if (! is_array($ips) || ! array_is_list($ips)) {
            throw new HttpException(422, 'ip_whitelist 必须是 IP 地址字符串组成的 JSON 数组');
        }

        foreach ($ips as $ip) {
            if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $shown = is_string($ip) ? $ip : gettype($ip);
                throw new HttpException(422, "无效的 IP 地址：{$shown}");
            }
        }

        // 校验全部通过后才整体写库，不整体校验通过就不落库——避免一条非法 IP
        // 导致合法 IP 被部分写入、留下一半新一半旧的中间状态。
        $this->merchantDao->update($merchant->id, ['ip_whitelist' => $ips]);

        return ['ip_whitelist' => $ips];
    }

    private function generateUniqueAppKey(): string
    {
        for ($i = 0; $i < self::MAX_APP_KEY_GENERATION_ATTEMPTS; ++$i) {
            $appKey = self::APP_KEY_PREFIX . bin2hex(random_bytes(self::APP_KEY_RANDOM_BYTES));
            if (! $this->merchantDao->findByAppKey($appKey)) {
                return $appKey;
            }
        }

        throw new HttpException(500, 'AppKey 生成失败，请重试');
    }

    private function generateAppSecret(): string
    {
        return bin2hex(random_bytes(self::APP_SECRET_RANDOM_BYTES));
    }
}
