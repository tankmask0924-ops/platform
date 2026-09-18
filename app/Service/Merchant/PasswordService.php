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
use App\Notify\VerificationCodeSender;
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
 * 找回密码：用注册时的手机号或邮箱收 6 位验证码（手机号走短信、邮箱走邮件），
 * 10 分钟有效、最多输错 5 次；同一账号 60 秒内只能获取一次。
 * 获取验证码接口不管账号存不存在都返回同样的结果，避免被用来探测哪些手机号注册过。
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
    protected VerificationCodeSender $codeSender;

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
    public function sendResetCode(string $username): array
    {
        $username = trim($username);
        if ($username === '') {
            throw new HttpException(422, '请输入注册时的手机号或邮箱');
        }

        // 冷却按输入的账号算，不管账号是否存在，否则能通过"有没有冷却"探测账号
        $cooldownKey = 'merchant:pwd_reset:cooldown:' . sha1(mb_strtolower($username));
        if (! $this->redis->set($cooldownKey, '1', ['nx', 'ex' => self::RESEND_COOLDOWN_SECONDS])) {
            throw new HttpException(429, '验证码发送太频繁，请稍后再试');
        }

        $merchant = $this->findByUsername($username);
        if ($merchant !== null && $merchant->status !== 'disabled') {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $this->redis->set($this->codeKey($merchant->id), json_encode([
                'hash' => hash('sha256', $code),
                'attempts' => 0,
            ]), ['ex' => self::CODE_TTL_SECONDS]);

            $channel = $merchant->phone === $username ? VerificationCodeSender::CHANNEL_SMS : VerificationCodeSender::CHANNEL_EMAIL;
            $this->codeSender->send($channel, $username, $code);
        }

        return ['expires_in' => self::CODE_TTL_SECONDS, 'resend_after' => self::RESEND_COOLDOWN_SECONDS];
    }

    public function reset(string $username, string $code, string $newPassword): void
    {
        $this->validateNewPassword($newPassword);

        $merchant = $this->findByUsername(trim($username));
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

    private function findByUsername(string $username): ?Merchant
    {
        if ($username === '') {
            return null;
        }

        return $this->merchantDao->newQuery()
            ->where('phone', $username)
            ->orWhere('email', $username)
            ->first();
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
