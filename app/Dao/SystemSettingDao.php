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

namespace App\Dao;

use App\Model\SystemSetting;

/**
 * `system_settings` 的主键是字符串 `key`，不是自增整数 id，父类
 * `AbstractDao::find(int $id)` 的参数类型（`int`）跟这张表的主键类型不兼容，
 * 没法直接重写（PHP 不允许把参数类型从 `int` 收窄/改写成不兼容的 `string`），
 * 所以这里不重写 `find()`，另起一个 `findByKey()`，父类 `find()`/`findOrFail()`/
 * `delete()` 这几个假设整数主键的方法对这张表不适用，本类和调用方都不应该用它们。
 */
class SystemSettingDao extends AbstractDao
{
    protected string $model = SystemSetting::class;

    public function findByKey(string $key): ?SystemSetting
    {
        return $this->newQuery()->where('key', $key)->first();
    }

    /**
     * 读一个系统参数并解码，key 不存在时返回调用方传入的代码级默认值——
     * 保证平台在这张表一行都没有时也能正确运行（requirements.md 5.4
     * "返佣固定期限...默认 7 天"就是靠这个默认值兜底，不依赖运营先去后台配置）。
     *
     * `value` 列的注释是"JSON 或标量字符串，应用层按 key 约定的类型解析"——这里
     * 选择让 `getValue()` 通用：先尝试 `json_decode()`，成功就返回解码后的值
     * （标量、数组都行，调用方拿到的是原生 PHP 类型，比如 `"7"` 会被解码成
     * int(7)），解码失败（说明 `value` 本身就是一个不是合法 JSON 字面量的普通
     * 字符串，比如一段说明文字）就原样返回这个字符串。不在这里为每个 key
     * 硬编码"这个 key 应该是 int/string/array"的类型断言，具体类型约定由调用方
     * （比如 `App\Service\Order\OrderResultApplier` 用 `rebate_due_period_days`
     * 时自己 `(int)` 转型）负责，符合类注释里"或者让 getValue 通用，调用方按 key
     * 约定的类型自己转型"这个选项。
     */
    public function getValue(string $key, mixed $default = null): mixed
    {
        $setting = $this->findByKey($key);
        if ($setting === null) {
            return $default;
        }

        $decoded = json_decode($setting->value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $setting->value;
    }
}
