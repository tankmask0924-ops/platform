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

namespace App\Service\Admin;

use App\Dao\MerchantDao;
use App\Service\AbstractService;
use Hyperf\Di\Annotation\Inject;

/**
 * 系统管理后台（web/admin）「商户管理 - 商户列表」（requirements.md 8.3），
 * docs/modules.md 第 8 节。故意做得很薄：只分页读 App\Dao\MerchantDao，
 * 不碰审核 / 启用禁用 / 等级调整 / 限流设置 / 详情——那些涉及状态流转的业务逻辑，
 * 属于单独的、更大的后续工作，本任务只是把 RBAC 中间件用一个真实、有用的接口
 * 跑通端到端（见 App\Middleware\AdminPermissionMiddleware 类注释）。
 */
class MerchantAdminService extends AbstractService
{
    #[Inject]
    protected MerchantDao $merchantDao;

    /**
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(int $page, int $perPage): array
    {
        $merchants = $this->merchantDao->paginate($page, $perPage);

        $data = $merchants->map(static fn ($merchant) => [
            'id' => $merchant->id,
            'type' => $merchant->type,
            'phone' => $merchant->phone,
            'email' => $merchant->email,
            'status' => $merchant->status,
            'level_id' => $merchant->level_id,
            'created_at' => $merchant->created_at?->toDateTimeString(),
        ])->values()->all();

        return [
            'data' => $data,
            'total' => $this->merchantDao->count(),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }
}
