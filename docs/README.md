# DSW（DeepSeekWebAPIChat，PHP + MySQL）

基于官方 [deepseek-ai/deepseek-demo](https://github.com/deepseek-ai/deepseek-demo) 前端实现的自建网页版。网站正式名称为 **DSW**，全称 **DeepSeekWebAPIChat**。前端保持官方交互与视觉，后端使用 PHP + MySQL 提供账号、会话、附件存储，并代理 DeepSeek API，浏览器不接触 API Key。

## 功能

- 自建账号系统：注册、登录、退出、修改密码、修改用户名；用户、会话、消息 ID 全部使用雪花算法。
- 图形验证码：注册与登录在提交表单后弹出统一验证码弹窗，管理后台登录保留内嵌验证码；统一由 `Captcha` 服务生成 / 校验 / 销毁，一次性消费、5 分钟过期。
- 服务协议：统一由 `/docs` 文档页承载（`/terms`、`/terms.html` 会自动跳转到 `用户协议.md`），登录 / 注册时必须勾选「已阅读并同意服务协议」。
- 未登录也可以直接进入聊天页面；发送消息、上传附件或使用语音时提示登录，并在设置中打开登录 / 注册页，登录后草稿保留。
- API Key 由用户在设置中填写，**明文保存在服务器数据库**，由服务端代理聊天、附件、语音等全部 DeepSeek 请求；「数据管理」中 API Key 输入框下方提供「DeepSeek 开放平台」跳转入口，方便管理 Key、充值与查看用量。
- 与官方一致的聊天体验：流式回复、深度思考、智能搜索、分支切换、消息编辑/重试、附件上传、图片预览、语音输入与朗读。
- 会话与附件保存在服务器：支持多设备登录，历史会话列表按游标分页，支持重命名、删除、清空。
- 删除会话时自动删除不再被引用的附件文件，并清理空目录。
- 消息分段加载：打开会话先加载最近 20 条消息，向上滚动到顶部自动加载更早的 20 条，并保持当前阅读位置；列表顶部会显示「正在加载更早的对话」，没有更早记录时自动隐藏。
- 右侧消息导航：与官方网页版一致，右侧短横对应每条提问，桌面端鼠标悬浮即展开预览、点击直接跳转（无平滑等待）；每页 10 条，可用上下箭头翻页，展开时自动把当前记录滚动到预览列表中；移动端同样显示，触屏点按先展开预览再点击跳转。
- 会话分享：会话标题栏或会话菜单点击分享后直接进入选择模式，所有消息左侧出现圆形复选框，聊天输入框隐藏，勾选后生成只读快照链接（`/?share={token}`，同时兼容 `/share/{token}`）；公开分享页可查看所选对话，登录后可一键「继续对话」，把快照复制成自己的新会话继续使用；「设置 → 数据管理 → 我的分享链接」可进入二级面板查看与撤销，撤销后记录直接删除，管理后台保留分享管理。
- 对话搜索：侧边栏搜索按钮支持按标题与消息正文检索全部会话；弹窗顶部输入，输入关键词后才展开结果框，点击结果直接打开命中消息所在的 20 条窗口并定位高亮。
- 会话批量管理：侧边栏「最近」标题右侧的滑块图标进入多选模式，会话前出现圆形复选框，新建对话变为「选择对话 + 关闭」，底部设置替换为置顶与删除，支持全选 / 取消全选与批量置顶 / 删除。
- 会话置顶：会话菜单支持置顶（粗头钉子图标）/ 取消置顶、分享与多选，置顶会话固定排在列表最前。
- 撤销某轮对话：用户消息下方与 AI 回答的「更多」菜单都提供撤销图标，确认后删除该轮提问、回答及其后的所有消息（含其他分支），并立即同步服务端。
- 文档路由：`/docs` 读取 `docs/` 下的 Markdown 并自动渲染为 HTML，左侧为可折叠文档列表（样式与会话列表一致），用户协议、隐私政策、README、更新日志与开发规范统一在此查看。
- 设置中心：新增「用户协议」标签页（用户协议 / 隐私政策入口）；账号页将修改用户名、修改密码改为两个按钮并进入二级面板，退出登录下方新增注销账号；数据管理新增 Token 用量入口（仅保留「查看」入口，不再内嵌预览进度条）、「查看」进入用量二级面板，以及「我的分享链接 → 去管理」。
- 交互动画与官方保持一致：侧边栏展开 / 收起宽度过渡、弹窗淡入上浮、菜单缩放、会话项悬浮过渡、批量操作栏滑入、搜索结果与分享页逐条淡入、搜索跳转短暂高亮，并完整适配 `prefers-reduced-motion`。
- 支持官方格式聊天记录 ZIP 导入（导入即彻底覆盖服务器上的全部会话与附件），导入过程带进度条，并兼容不带索引的旧版归档；导出时由浏览器先向服务端拉取全部会话与消息，再在本地生成 ZIP，全程显示进度，不上传任何数据到第三方。
- 完整管理后台：用户管理、用户详情（全部字段含明文 API Key、密码哈希、会话与附件统计）、角色 / 状态权限调整、会话查看、分享管理、站点设置、CSV 导出；管理面板与会话列表使用同款左侧列表（可展开 / 收起并记忆状态），切换页面通过局部渲染完成，选中后不刷新整页。
- 管理后台、安装向导、404 页与文档页统一使用同一套官方原版组件样式 `assets/ui.css`（胶囊按钮、40px 控件、24px 弹窗圆角、0.2s 缓动，自动适配浅色 / 深色模式），样式只有一份来源，不再各页各写一套。
- 站点名称集中维护：服务端页面通过语言文件 `app/lang/zh-CN.php` + `app/Support/Lang.php` 读取 **DSW / DeepSeekWebAPIChat**，前端默认名称与数据库默认值同步。

## 目录结构

```text
.
├── index.php              # 入口：PHP 输出首页 + API 路由 + 安装/文档/404 分发
├── .user.ini              # PHP-FPM/CGI 上传与执行时间限制
├── index.html             # 前端构建产物（由 frontend 构建生成）
├── static/                # 前端构建产物
├── 404.html               # 公开错误页（Apache/Nginx 直接指向它）
├── install/               # 安装向导目录入口（/install/）
├── assets/                # 服务端页面共享样式（ui.css，官方组件规范唯一来源）
├── app/                   # 业务代码
│   ├── Controllers/       # API 控制器
│   ├── Services/          # 会话、附件、导入、验证码、首页渲染、后台数据
│   ├── Support/           # 配置、数据库、认证、雪花、请求响应、语言文件读取
│   ├── lang/              # 站点文案（zh-CN.php）
│   ├── Install/           # 安装向导逻辑与建表脚本
│   └── Dev/               # PHP 内置服务器开发路由
├── admin/                 # 服务端渲染管理后台（/admin/）
├── docs/                  # 实现计划、更新日志、开发规范、README 副本
├── frontend/              # 官方前端源码（独立构建）
├── scripts/               # 官方 demo 参考仓库（不提交 Git）
└── uploads/               # 运行期数据（不入库）
    ├── config/            # config.php、installed.lock（安装生成）
    ├── logs/              # 应用日志
    ├── attachments/{用户ID}/{附件ID}
    └── staging/{用户ID}/  # 历史导入暂存
```

## 环境要求

- PHP 8.1+（需要 `pdo_mysql`、`curl`、`mbstring`、`zip`；无需 `fileinfo`）
- MySQL 5.7+ / MariaDB 10.4+
- Node.js 20+、pnpm 9+（仅构建前端需要）

## 安装

1. 将网站根目录指向本项目根目录（包含 `index.php` 的那一层，不是 `frontend/`）。
   - 若面板提供“运行目录”选项，留空或填 `/`，**不要**设为 `/public`、`/frontend` 等子目录。
   - 前端产物与接口都使用 `/static`、`/api` 等根路径，因此必须部署在域名根目录；如需部署到子目录，需同步修改前端 `publicPath` 与接口前缀。
2. 确保 `uploads/` 可写（安装向导会自动创建 `uploads/config/` 等子目录）。
3. 浏览器访问 `/install`（或 `/install/`），填写 MySQL 地址、库名、账号密码，创建管理员账号。
   - 安装程序会自动建库、建表、生成 `uploads/config/config.php` 与 `uploads/config/installed.lock`。
   - 若数据库已存在同名库，请确认使用空库或备份后继续。
4. 安装完成后访问 `/` 即可直接进入聊天；未登录时发送消息会引导在设置中登录 / 注册。管理员访问 `/admin/` 登录后台。
5. 导入历史或上传大附件需要更高的 PHP 限制：PHP-FPM/CGI 读取根目录 `.user.ini`（128M / 900s），Apache `mod_php` 读取 `.htaccess` 中的 `php_value`；Nginx + PHP-FPM 需同步 `client_max_body_size 128m;`。
6. 首页由 `index.php` 读取构建产物 `index.html` 后注入站点名称与 `window.__SITE__` 再输出，因此默认首页需优先 `index.php`。

## 前端构建

```bash
cd frontend
pnpm install
pnpm build
```

构建产物直接输出到项目根目录的 `index.html` 与 `static/`，与 PHP 接口同源部署，无需额外配置跨域。`pnpm build` 会先清理旧的 `static/css`、`static/js`、`static/media`，避免残留过期产物。

开发调试（可选）：

```bash
# 终端 1：后端（必须带开发路由，否则 /api 路由会 404）
php -S 127.0.0.1:8080 app/Dev/router.php

# 终端 2：前端热更新，/api 与 /admin 自动代理到 8080
cd frontend && pnpm dev
```

## API Key 与安全说明

- 用户填写的 API Key 会先调用 DeepSeek `/models` 校验，通过后**明文存入 `users.api_key`**，前端仅拿到 `sk-****后 4 位` 的掩码。
- 所有 DeepSeek 请求都由服务端 cURL 转发，Key 不会下发到浏览器。
- 登录态使用随机 32 字节 Token（Cookie `ds_token`，HttpOnly + SameSite=Lax），数据库只保存 SHA-256 摘要。
- 所有写接口校验 `Origin` 与 `X-CSRF-Token`；登录失败有频率限制。
- 登录 / 注册与后台登录均校验图形验证码：答案仅存 SHA-256，校验后立即销毁，5 分钟过期，错误一次即失效。
- 登录 / 注册必须勾选同意服务协议（文档页 `/docs` 中的「用户协议」），服务端仍会校验账号、密码与验证码。
- 建议生产环境只启用 HTTPS，并限制 `uploads/`、`app/` 的直接访问。Apache 由根目录 `.htaccess` 自动处理；Nginx 参考：

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/deepseek;
    index index.php index.html;

    client_max_body_size 128m;
    error_page 404 /404.html;

    location ~ ^/(app|uploads|frontend|scripts)(/|$) { deny all; }
    location ~ ^/\.(git|gitignore) { deny all; }

    location /api/ { try_files $uri /index.php$is_args$args; }
    location /admin/ { try_files $uri $uri/ /admin/index.php$is_args$args; }
    location / { try_files $uri $uri/ /index.php$is_args$args; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_read_timeout 900s;
        fastcgi_buffering off;
    }
}
```

## 访问全站 404 排查

1. **站点根目录 / 运行目录**：根目录必须是含 `index.php` 的项目根；面板的“运行目录”留空或 `/`。指向 `frontend/`、`public/` 会导致所有页面 404。
2. **默认首页顺序**：`/` 必须由 `index.php` 输出（PHP 会注入站点信息）。Apache 已由 `.htaccess` 的 `DirectoryIndex index.php index.html` 处理；Nginx 必须配置 `index index.php index.html;`，否则 `/` 会直接返回未经注入的 `index.html`。
3. **Apache 重写**：启用 `mod_rewrite`，并允许 `.htaccess`（`AllowOverride All`）；否则 `/api/*`、无扩展名的前端路由会 404。
4. **Nginx 重写**：`location /` 必须有 `try_files $uri $uri/ /index.php$is_args$args;`。
5. **PHP 内置服务器**：必须使用 `php -S 127.0.0.1:8080 app/Dev/router.php` 启动，不能省略路由脚本。
6. 若只有安装页可访问：确认 `index.html` 与 `static/` 已上传，且没有把应用部署在子目录。
7. 前端已做兼容：当 `/api/*` 返回 404 或 HTML 时，会自动改用 `/index.php?_r=/api/...` 重试，`limit`、`cursor` 等查询参数会原样保留；仍建议配置标准伪静态，便于日志与缓存管理。

## 错误页

`404.html` 为根目录下的公开错误页，与安装向导、管理后台、文档页共用 `assets/ui.css`，配色、控件、圆角与动画完全一致。Apache 通过 `.htaccess` 的 `ErrorDocument 404 /404.html` 生效；Nginx 需配置 `error_page 404 /404.html;`；开发用的 `app/Dev/router.php` 会把缺失路径交给 `index.php` 处理。

## 文档

- [项目实现计划](docs/项目实现计划.md)
- [更新日志](docs/CHANGELOG.md)
- [开发规范](docs/开发规范.md)
- [README 副本](docs/README.md)

## 管理后台

访问 `/admin/`，使用管理员账号登录。默认管理员在安装向导中创建。

- 概览：用户、会话、消息、Token、附件统计。
- 用户：搜索、创建、角色调整、禁用/启用、重置密码、清除 API Key、删除用户及其数据、导出 CSV。
- 用户详情：查看全部账号字段（含明文 API Key、密码哈希）、会话 / 消息 / Token / 附件统计、最近会话与附件，并可调整角色、启用/禁用、重置密码、清除 Key 或删除用户。
- 会话：搜索、查看消息与原始 JSON、删除。
- 站点设置：站点名称、公告、是否开放注册、附件大小上限、单用户会话上限、DeepSeek API 地址。

## 常用命令

```bash
# PHP 语法检查
find app admin -name '*.php' -print0 | xargs -0 -n1 php -l

# 前端类型检查与构建
cd frontend && pnpm run type && pnpm run build
```

## 数据表

`users`（账号与明文 API Key）、`auth_tokens`（登录令牌）、`sessions`（完整官方 Session JSON）、`files` / `session_files`（附件原件与引用）、`staging_sessions` / `staging_files`（导入暂存）、`id_sequence`（雪花序列）、`login_attempts`（登录限流）、`captchas`（图形验证码，只存答案摘要）、`admin_logs`（审计）、`site_settings`（站点配置）。
