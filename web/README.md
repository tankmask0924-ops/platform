# platform-web

平台前端，包含两个管理后台，使用 npm workspaces 管理。

| 目录 | 说明 | 开发端口 | 部署路径 |
|---|---|---|---|
| `admin/` | 系统管理后台（平台运营方使用） | 5173 | `/admin/` |
| `merchant/` | 商户管理后台（商户使用） | 5174 | `/merchant/` |
| `shared/` | 两个后台共用的代码：布局、登录面板、HTTP 封装、登录态、路由守卫 | — | — |

技术栈：Vue 3 + TypeScript + Vite + Element Plus + Pinia + Vue Router + Axios。

## 常用命令

在 `web/` 目录下执行：

```bash
npm install              # 安装依赖（两个后台共用一份 node_modules）
npm run dev:admin        # 启动系统管理后台 http://localhost:5173/admin/
npm run dev:merchant     # 启动商户管理后台 http://localhost:5174/merchant/
npm run type-check       # 类型检查
npm run build            # 构建两个后台，产物在 admin/dist、merchant/dist
```

## 接口联调

- 前端请求统一以 `/api` 开头（见各后台的 `.env` 里的 `VITE_API_BASE_URL`）
- 开发时 Vite 把 `/api/*` 代理到 `http://127.0.0.1:9501`（Hyperf，先 `docker compose up -d`），并去掉 `/api` 前缀
- 生产环境需要在 nginx 里做同样的转发

## 目录结构（以 admin 为例）

```
admin/
├── .env                 # VITE_APP_TITLE、VITE_API_BASE_URL
├── vite.config.ts       # base 路径、端口、代理
└── src/
    ├── api/             # 接口定义，每个模块一个文件，统一通过 api/http.ts 发请求
    ├── layout/          # 布局 + 左侧菜单配置（menus.ts）
    ├── router/          # 路由
    ├── stores/          # Pinia store
    └── views/           # 页面
```

新增一个页面：
1. `src/api/xxx.ts` 定义接口
2. `src/views/xxx/XxxView.vue` 写页面
3. `src/router/index.ts` 加路由（`meta.title` 会显示在顶栏和浏览器标题）
4. `src/layout/menus.ts` 加菜单项

## 登录

后端还没有登录接口，`src/api/auth.ts` 里的 `login()` 目前是本地假登录（任意账号密码都能进），接好后端后替换成真实请求即可。
请求会自动带上 `Authorization: Bearer <token>`，接口返回 401 时自动清除登录态并跳转登录页。

两个后台的登录态存在 localStorage 的不同 key 下（`admin-auth:*` / `merchant-auth:*`），部署在同一域名下也不会互相影响。
