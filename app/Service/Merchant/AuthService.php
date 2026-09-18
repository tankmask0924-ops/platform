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
use App\Service\AbstractService;
use Hyperf\Database\Exception\QueryException;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户管理后台（web/merchant）账户注册/登录（requirements.md 4.1、8.2）。
 *
 * 跟 App\Service\OpenApi\* 是两套不相干的东西：这里服务的是人类浏览器会话
 * （App\Controller\Merchant\AuthController + App\Middleware\MerchantAuthMiddleware），
 * 不是第三方服务器对服务器的开放 API 调用。
 *
 * 校验方式选择：没有用 App\Request\*+FormRequest+#[Scene]（参考 app/Request/UserRequest.php
 * 的既有写法），因为 register 的必填字段依赖 type 是 company 还是 individual，
 * 这种「按另一个字段的值切换必填集合」的条件校验，用 Hyperf 的 Scene（选择一组固定字段名）
 * 表达不直接，容易写成两个 Scene 再在 Controller 里根据 type 选 Scene，反而更绕；
 * 手写校验能把「至少二选一」「跟另一列做唯一性检查」这类逻辑放在同一个方法里，逻辑更直白。
 * login 同理，为了跟 register 风格一致也走手写校验，没有单独混用 FormRequest。
 *
 * 密码最低要求：8 位以上，没有更复杂的强度规则（大小写/数字/符号），仅为跟明显弱密码
 * （空、几位数字）划清界限，requirements.md 没有给出具体强度要求，这是本任务的判断。
 */
class AuthService extends AbstractService
{
    private const MIN_PASSWORD_LENGTH = 8;

    private const GENERIC_LOGIN_FAIL_MESSAGE = '账号或密码错误';

    #[Inject]
    protected MerchantDao $merchantDao;

    #[Inject]
    protected QualificationService $qualificationService;

    #[Inject]
    protected MerchantJwtGuard $tokenGuard;

    /**
     * @param array<string, mixed> $data
     */
    public function register(array $data): Merchant
    {
        $type = is_string($data['type'] ?? null) ? $data['type'] : '';
        $phone = trim((string) ($data['phone'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        $this->validateRegister($type, $phone, $email, $password, $data);

        return Db::transaction(function () use ($type, $phone, $email, $password, $data) {
            try {
                $merchant = $this->merchantDao->create([
                    'type' => $type,
                    'phone' => $phone !== '' ? $phone : null,
                    'email' => $email !== '' ? $email : null,
                    'password' => password_hash($password, PASSWORD_BCRYPT),
                    'status' => 'pending',
                ]);
            } catch (QueryException $e) {
                // 兜底：跟上面 validateRegister() 里的唯一性预检查一起构成防线，
                // 防止两个并发注册请求都通过预检查后在数据库唯一约束上撞车，
                // 让原始 DB 异常以 500 的形式泄露出去。
                throw new HttpException(422, '手机号或邮箱已被注册', 0, $e);
            }

            $this->qualificationService->createPending((int) $merchant->id, $type, $data);

            return $merchant;
        });
    }

    /**
     * @return array{token: string, username: string}
     */
    public function login(string $username, string $password): array
    {
        $username = trim($username);

        $merchant = $this->merchantDao->newQuery()
            ->where('phone', $username)
            ->orWhere('email', $username)
            ->first();

        if (! $merchant || ! password_verify($password, $merchant->password)) {
            throw new HttpException(401, self::GENERIC_LOGIN_FAIL_MESSAGE);
        }

        if ($merchant->status === 'disabled') {
            // 密码本身是对的，只是账号被禁用了，跟「账号或密码错误」是不同性质的失败，
            // 用 403（access denied）而不是 401（unauthenticated）更准确地表达
            // 「你是谁我们认，但不许进」。
            throw new HttpException(403, '账号已被禁用');
        }

        $token = $this->tokenGuard->issue($merchant->id, $merchant->password);

        $matchedUsername = $merchant->phone === $username ? $merchant->phone : $merchant->email;

        return [
            'token' => $token,
            'username' => (string) $matchedUsername,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateRegister(string $type, string $phone, string $email, string $password, array $data): void
    {
        if (! in_array($type, QualificationService::TYPES, true)) {
            throw new HttpException(422, 'type 必须是 company 或 individual');
        }

        if ($phone === '' && $email === '') {
            throw new HttpException(422, '手机号和邮箱至少填写一个');
        }

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new HttpException(422, '密码长度至少 ' . self::MIN_PASSWORD_LENGTH . ' 位');
        }

        if ($phone !== '' && $this->merchantDao->newQuery()->where('phone', $phone)->exists()) {
            throw new HttpException(422, '手机号已被注册');
        }

        if ($email !== '' && $this->merchantDao->newQuery()->where('email', $email)->exists()) {
            throw new HttpException(422, '邮箱已被注册');
        }

        $this->qualificationService->validate($type, $data);
    }
}
