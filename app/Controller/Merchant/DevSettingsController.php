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

namespace App\Controller\Merchant;

use App\Controller\AbstractController;
use App\Middleware\MerchantAuthMiddleware;
use App\Model\Merchant;
use App\Service\Merchant\DevSettingsService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;

/**
 * 商户管理后台（web/merchant）「开发设置」：生成/重置 AppKey、AppSecret，配置 IP 白名单
 * （requirements.md 4.1、8.2）。
 *
 * 跟 App\Controller\Merchant\AuthController 不同，这个 Controller 下的四个接口全部
 * 需要登录态（不像 AuthController 那样 register/login 公开、只有 me 受保护），
 * 所以这里用类级 #[Middleware(MerchantAuthMiddleware::class)] 一次性覆盖全部路由，
 * 而不是逐方法加注解——类级/方法级 #[Middleware] 在这个 Hyperf 版本里都支持
 * （TARGET_CLASS + TARGET_METHOD，见 vendor/hyperf/http-server/src/Annotation/Middleware.php），
 * 但不能通过 #[Controller(options: ['middleware' => [...]])] 挂载，会被
 * DispatcherFactory::handleController() 整个覆盖掉，详见
 * app/Controller/OpenApi/BalanceController.php 类注释里记录的坑。
 *
 * 响应同样是普通 JSON body（不是开放 API 的 {code,message,data} 信封），风格对齐
 * App\Controller\Merchant\AuthController。
 */
#[Controller(prefix: '/merchant/dev-settings')]
#[Middleware(MerchantAuthMiddleware::class)]
class DevSettingsController extends AbstractController
{
    #[Inject]
    protected DevSettingsService $devSettingsService;

    #[GetMapping(path: '')]
    public function show(): array
    {
        return $this->devSettingsService->getSettings($this->currentMerchant());
    }

    #[PostMapping(path: 'app-key')]
    public function generateAppKey(): array
    {
        return $this->devSettingsService->generateAppKey($this->currentMerchant());
    }

    #[PostMapping(path: 'app-secret/reset')]
    public function resetAppSecret(): array
    {
        return $this->devSettingsService->resetAppSecret($this->currentMerchant());
    }

    /**
     * body 顶层直接是 IP 字符串组成的 JSON 数组（例如 `["1.2.3.4", "::1"]`），
     * 不是 `{"ip_whitelist": [...]}` 这种包了一层的对象——跟任务描述「accepts a JSON
     * array of IP address strings」的字面表述保持一致。
     */
    #[PutMapping(path: 'ip-whitelist')]
    public function updateIpWhitelist(): array
    {
        return $this->devSettingsService->updateIpWhitelist($this->currentMerchant(), $this->request->getParsedBody());
    }

    private function currentMerchant(): Merchant
    {
        /** @var Merchant $merchant */
        return $this->request->getAttribute('merchant');
    }
}
