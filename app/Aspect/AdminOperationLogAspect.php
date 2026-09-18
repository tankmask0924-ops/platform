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

namespace App\Aspect;

use App\Annotation\RequiresPermission;
use App\Dao\AdminOperationLogDao;
use App\Model\AdminUser;
use App\Network\ClientIpResolver;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * 管理后台"操作全部记操作日志"（requirements.md 9）的兜底：挂了 #[RequiresPermission] 的接口，
 * 只要是写请求（POST/PUT/PATCH/DELETE）并且执行成功，就自动记一条操作日志。
 *
 * - 模块取权限编码的前缀（merchant.balance_adjust → merchant），动作取方法名（adjustBalance → adjust_balance），
 *   目标 ID 取路由参数 {id}，after_data 是请求参数（密码、密钥、证件号、供应商配置等打码）；
 * - Service 里已经手动记过带前后对比的日志（AdminOperationLogDao::record()）的请求不再重复记；
 * - 失败的请求（抛异常）不记。
 */
#[Aspect]
class AdminOperationLogAspect extends AbstractAspect
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const SENSITIVE_KEY_PATTERN = '/password|secret|token|config|app_key|card_no|card_pwd|id_card_no/i';

    public array $annotations = [RequiresPermission::class];

    #[Inject]
    protected RequestInterface $request;

    #[Inject]
    protected AdminOperationLogDao $operationLogDao;

    #[Inject]
    protected ClientIpResolver $clientIpResolver;

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        $result = $proceedingJoinPoint->process();

        if (! in_array($this->request->getMethod(), self::WRITE_METHODS, true) || Context::get(AdminOperationLogDao::RECORDED_CONTEXT_KEY)) {
            return $result;
        }
        $admin = $this->request->getAttribute('admin');
        /** @var null|RequiresPermission $permission */
        $permission = $proceedingJoinPoint->getAnnotationMetadata()->method[RequiresPermission::class] ?? null;
        if (! $admin instanceof AdminUser || $permission === null) {
            return $result;
        }

        $module = explode('.', $permission->code)[0];
        $id = $proceedingJoinPoint->arguments['keys']['id'] ?? null;
        $this->operationLogDao->record(
            (int) $admin->id,
            $module,
            strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $proceedingJoinPoint->methodName)),
            $module,
            is_numeric($id) ? (int) $id : null,
            null,
            $this->mask($this->request->all()),
            $this->clientIpResolver->resolve($this->request)
        );

        return $result;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = $this->mask($value);
            }
        }

        return $data;
    }
}
