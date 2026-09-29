# 接口开放平台（platform）

面向商户的话费、卡券、电影票、快递接口开放平台：商户预存余额，通过开放 API 下单，平台按路由规则转给供应商（卡速售、芒果、云洋），赚取售价差和供应商返佣。

- 后端：Hyperf 3.1 + PHP 8.4 + Swoole，本目录
- 前端：商户管理后台、系统管理后台，见 [web/README.md](web/README.md)

## 文档

| 文档 | 内容 |
|---|---|
| [docs/requirements.md](docs/requirements.md) | 需求：业务规则、资金、返佣、订单流程、功能清单 |
| [docs/database-design.md](docs/database-design.md) | 数据库设计（表结构以 `migrations/` 为准） |
| [docs/modules.md](docs/modules.md) | 模块设计与开发进度 |
| [docs/suppliers/](docs/suppliers/) | 卡速售、云洋、芒果接口要点 |

## 本地开发

本机不装 PHP，所有 PHP 命令都在 Docker 容器 `pf` 里执行。MySQL / Redis 在局域网服务器上，连接信息写在 `.env`（从 `.env.example` 复制）。

```bash
docker compose up -d                                   # 启动，HTTP 端口 9501
docker compose restart                                 # 改完代码后重启生效（Swoole 常驻内存）
docker exec pf php bin/hyperf.php migrate              # 执行数据库迁移
docker exec pf php bin/hyperf.php admin:sync-permissions  # 同步后台权限项（新增权限后执行）
docker exec pf php bin/hyperf.php admin:create         # 创建后台管理员账号
docker exec pf composer test                           # 全部测试
docker exec pf composer analyse                        # 静态分析
```

编码规范、测试写法等见 [.claude/skills/hyperf-conventions/SKILL.md](.claude/skills/hyperf-conventions/SKILL.md)。

## 部署要点

- `.env` 必须配置 `APP_ENCRYPTION_KEY`、`MERCHANT_JWT_SECRET`、`ADMIN_JWT_SECRET`，`DB_CHARSET=utf8mb4`
- `SUPPLIER_NOTIFY_BASE_URL`：供应商能访问到的平台公网地址，不配则收不到供应商回调
- 确认容器里有 `crontab-dispatcher` 和 `async-queue` 两个进程，否则定时任务和异步下单不执行
