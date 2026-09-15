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

/**
 * 标在系统管理后台（web/admin）Controller 方法上，声明该动作需要的权限编码
 * （对应 admin_permissions.code，如 'merchant.view'）。
 *
 * 这是一个纯 PHP 8 attribute，不是 Hyperf 的 `Hyperf\Di\Annotation\AbstractAnnotation`
 * 体系下的注解——它不需要参与 Hyperf 的注解扫描/收集（`config/autoload/annotations.php`
 * 那套 AnnotationCollector 机制），因为 App\Middleware\AdminPermissionMiddleware
 * 在请求分发时通过 `Hyperf\HttpServer\Router\Dispatched::$handler->callback`
 * 直接用 ReflectionMethod 现读现取，没有预先收集的必要，也就没有理由为它多背一层
 * Hyperf 注解基类的开销。
 *
 * 一个方法上只挂一个权限编码（够用；没有出现过一个动作要同时满足多个权限编码的
 * 需求，真出现了再扩展成数组不迟），没有 #[RequiresPermission] 的方法视为
 * 「登录即可访问」，不做权限编码校验（见 AdminPermissionMiddleware 类注释）。
 */
#[Attribute(Attribute::TARGET_METHOD)]
class RequiresPermission
{
    public function __construct(public string $code)
    {
    }
}
