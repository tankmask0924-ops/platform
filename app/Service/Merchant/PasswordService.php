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

use App\Auth\MerchantJwtGuard;
use App\Dao\MerchantDao;
use App\Model\Merchant;
use App\Notify\Sms\SmsSender;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Redis\Redis;

/**
 * 商户修改密码 / 找回密码（requirements.md 8.2「账户」）。
 *
 * 改密码后旧 token 的密码版本对不上，MerchantAuthMiddleware 会让它们全部失效；
 * 修改密码接口顺带签发一个新 token，当前页面不用重新登录。
 *
 * 找回密码：只支持短信，用注册手机号收 6 位验证码，10 分钟有效、最多输错 5 次；
 * 同一手机号 60 秒内只能获取一次。只填了邮箱的商户没法自助找回，需要联系平台。
 * 获取验证码接口不管手机号有没有注册、短信有没有发成功，都返回同样的结果，
 * 避免被用来探测哪些手机号注册过（发送失败由 SmsSender 记日志）。
 */
class PasswordService extends AbstractService
{
    public const CODE_TTL_SECONDS = 600;

    public const RESEND_COOLDOWN_SECONDS = 60;

    public const MAX_ATTEMPTS = 5;

    private const MIN_PASSWORD_LENGTH = 8;

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected MerchantJwtGuard $tokenGuard;

    #[Inject]
    protected SmsSender $smsSender;

    #[Inject]
    protected Redis $redis;

    /**
     * @return array{token: string}
     */
    public function change(Merchant $merchant, string $oldPassword, string $newPassword): array
    {
        if (! password_verify($oldPassword, $merchant->password)) {
            throw new HttpException(422, '原密码不正确');
        }
        $this->validateNewPassword($newPassword);
        if ($oldPassword === $newPassword) {
            throw new HttpException(422, '新密码不能和原密码相同');
        }

        $hash = $this->updatePassword($merchant, $newPassword);

        return ['token' => $this->tokenGuard->issue($merchant->id, $hash)];
    }

    /**
     * @return array{expires_in: int, resend_after: int}
     */
    public function sendResetCode(string $phone): array
    {
        $phone = trim($phone);
        if (! preg_match('/^1\d{10}$/', $phone)) {
            throw new HttpException(422, '请输入注册时的 11 位手机号');
        }

        // 冷却按输入的手机号算，不管是否注册，否则能通过"有没有冷却"探测账号
        $cooldownKey = 'merchant:pwd_reset:cooldown:' . $phone;
        if (! $this->redis->set($cooldownKey, '1', ['nx', 'ex' => self::RESEND_COOLDOWN_SECONDS])) {
            throw new HttpException(429, '验证码发送太频繁，请稍后再试');
        }

        $merchant = $this->findByPhone($phone);
        if ($merchant !== null && $merchant->status !== 'disabled') {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $this->redis->set($this->codeKey($merchant->id), json_encode([
                'hash' => hash('sha256', $code),
                'attempts' => 0,
            ]), ['ex' => self::CODE_TTL_SECONDS]);

            $this->smsSender->sendVerificationCode($phone, $code);
        }

        return ['expires_in' => self::CODE_TTL_SECONDS, 'resend_after' => self::RESEND_COOLDOWN_SECONDS];
    }

    public function reset(string $phone, string $code, string $newPassword): void
    {
        $this->validateNewPassword($newPassword);

        $merchant = $this->findByPhone(trim($phone));
        $key = $merchant !== null ? $this->codeKey($merchant->id) : null;
        $stored = $key !== null ? json_decode((string) $this->redis->get($key), true) : null;
        if (! is_array($stored)) {
            throw new HttpException(422, '验证码错误或已过期，请重新获取');
        }

        if (! hash_equals($stored['hash'], hash('sha256', trim($code)))) {
            $attempts = (int) $stored['attempts'] + 1;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->redis->del($key);
                throw new HttpException(422, '验证码错误次数过多，请重新获取');
            }
            $stored['attempts'] = $attempts;
            // KEEPTTL：只改次数，不延长有效期
            $this->redis->set($key, json_encode($stored), ['keepttl']);
            throw new HttpException(422, '验证码错误或已过期，请重新获取');
        }

        // 先删验证码再改密码：同一个验证码不能用两次
        if (! $this->redis->del($key)) {
            throw new HttpException(422, '验证码错误或已过期，请重新获取');
        }
        $this->updatePassword($merchant, $newPassword);
    }

    private function findByPhone(string $phone): ?Merchant
    {
        if ($phone === '') {
            return null;
        }

        return $this->merchantDao->newQuery()->where('phone', $phone)->first();
    }

    private function validateNewPassword(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new HttpException(422, '新密码长度至少 ' . self::MIN_PASSWORD_LENGTH . ' 位');
        }
    }

    private function updatePassword(Merchant $merchant, string $password): string
    {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $this->merchantDao->update($merchant->id, ['password' => $hash]);

        return $hash;
    }

    private function codeKey(int $merchantId): string
    {
        return "merchant:pwd_reset:code:{$merchantId}";
    }
}
