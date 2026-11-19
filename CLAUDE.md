# platform

商户接口开放平台。后端是 Hyperf 3.1 + PHP 8.4 + Swoole 6，放在本目录；前端是两个 Vue 管理后台，放在 `web/`。项目概况见 [README.md](README.md)。

## 动手前先读对应文档

| 要改的东西 | 先读 |
|---|---|
| 业务规则：资金、余额、价格、返佣、订单流程 | [docs/requirements.md](docs/requirements.md)，末尾的「决策记录」也要看 |
| 表结构、新增迁移 | [docs/database-design.md](docs/database-design.md)，以 `migrations/` 为准 |
| 某个模块的设计或进度 | [docs/modules.md](docs/modules.md) |
| 某个供应商的对接（卡速售、云洋、芒果） | 对应的 [docs/suppliers/](docs/suppliers/)`*.md` |
| 对商户开放的 API：签名、接口、回调、错误码 | [docs/open-api-integration.md](docs/open-api-integration.md) |
| 前端 | [web/README.md](web/README.md) |

改了开放 API 的请求、响应、错误码或回调，要同步更新 `docs/open-api-integration.md`。这份文档是发给商户的。

## 必守约定

- 本机没装 PHP 和 Composer，所有 PHP 命令都要在容器里跑：`docker exec pf ...`。如果 Docker 连不上，说明 OrbStack 没启动，先执行 `orb start`，再 `docker compose up -d`。
- 改完 PHP 代码要 `docker compose restart` 才生效，因为 Swoole 常驻内存。
- 写、改或审 PHP 代码前，先加载 `hyperf-conventions` 技能：编码规范、分层、注解、金额与并发、测试的规定都在里面。
