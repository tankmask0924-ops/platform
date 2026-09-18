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

namespace App\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * 标在系统管理后台（web/admin）Controller 方法上，声明该动作需要的权限编码
 * （对应 admin_permissions.code，如 'merchant.view'）。
 *
 * App\Middleware\AdminPermissionMiddleware 在请求分发时用 ReflectionMethod 现读现取；
 * 继承 AbstractAnnotation 是为了让 Hyperf 收集它，App\Aspect\AdminOperationLogAspect
 * 按这个注解切入，给所有需要权限的写操作自动记操作日志。
 *
 * 一个方法上只挂一个权限编码（够用；没有出现过一个动作要同时满足多个权限编码的
 * 需求，真出现了再扩展成数组不迟），没有 #[RequiresPermission] 的方法视为
 * 「登录即可访问」，不做权限编码校验（见 AdminPermissionMiddleware 类注释）。
 */
#[Attribute(Attribute::TARGET_METHOD)]
class RequiresPermission extends AbstractAnnotation
{
    public function __construct(public string $code)
    {
    }
}
