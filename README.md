# DeepSeekChat（PHP + MySQL）

基于官方 [deepseek-ai/deepseek-demo](https://github.com/deepseek-ai/deepseek-demo) 前端实现的自建网页版。前端保持官方交互与视觉，后端使用 PHP + MySQL 提供账号、会话、附件存储，并代理 DeepSeek API，浏览器不接触 API Key。

## 功能

- 自建账号系统：注册、登录、退出、修改密码、修改用户名；用户、会话、消息 ID 全部使用雪花算法。
- 图形验证码：注册与登录在提交表单后弹出统一验证码弹窗，管理后台登录保留内嵌验证码；统一由 `Captcha` 服务生成 / 校验 / 销毁，一次性消费、5 分钟过期。
- 服务协议：`/terms` 独立页面（兼容 `/terms.html`），登录 / 注册时必须勾选「已阅读并同意服务协议」。
- 未登录也可以直接进入聊天页面；发送消息、上传附件或使用语音时提示登录，并在设置中打开登录 / 注册页，登录后草稿保留。
- API Key 由用户在设置中填写，**明文保存在服务器数据库**，由服务端代理聊天、附件、语音等全部 DeepSeek 请求；「数据管理」中 API Key 输入框下方提供「DeepSeek 开放平台」跳转入口，方便管理 Key、充值与查看用量。
- 与官方一致的聊天体验：流式回复、深度思考、智能搜索、分支切换、消息编辑/重试、附件上传、图片预览、语音输入与朗读。
- 会话与附件保存在服务器：支持多设备登录，历史会话列表按游标分页，支持重命名、删除、清空。
- 消息分段加载：打开会话先加载最近 10 条消息，向上滚动到顶部自动加载更早的 10 条，并保持当前阅读位置；发送或重新生成前自动补齐完整上下文。
- 支持官方格式聊天记录 ZIP 导入（导入即彻底覆盖服务器上的全部会话与附件），导入过程带进度条，并兼容不带索引的旧版归档；支持导出。
- 完整管理后台：用户管理、用户详情（全部字段含明文 API Key、密码哈希、会话与附件统计）、角色 / 状态权限调整、会话查看、站点设置、操作日志、CSV 导出。

## 目录结构

```text
.
├── index.php              # 入口：PHP 输出首页 + API 路由 + 安装/协议/404 分发
├── .user.ini              # PHP-FPM/CGI 上传与执行时间限制
├── index.html             # 前端构建产物（由 frontend 构建生成）
├── static/                # 前端构建产物
├── app/                   # 业务代码
│   ├── Controllers/       # API 控制器
│   ├── Services/          # 会话、附件、导入、验证码、首页渲染、后台数据
│   ├── Support/           # 配置、数据库、认证、雪花、请求响应
│   ├── Views/             # 服务端静态页面（404、服务协议）
│   ├── Install/           # 安装向导（由 index.php 分发到 /install）
│   └── Dev/               # PHP 内置服务器开发路由
├── admin/                 # 服务端渲染管理后台（/admin/）
├── config/                # 配置目录（config.php 由安装向导生成，不入库）
├── database/schema.sql    # 数据表结构
├── docs/                  # 项目实现计划
├── frontend/              # 官方前端源码（独立构建）
├── scripts/               # 官方 demo 参考仓库（不提交 Git）
└── storage/               # 运行期附件与日志（不入库）
```

## 环境要求

- PHP 8.1+（需要 `pdo_mysql`、`curl`、`mbstring`、`zip`；无需 `fileinfo`）
- MySQL 5.7+ / MariaDB 10.4+
- Node.js 20+、pnpm 9+（仅构建前端需要）

## 安装

1. 将网站根目录指向本项目根目录（包含 `index.php` 的那一层，不是 `frontend/`）。
   - 若面板提供“运行目录”选项，留空或填 `/`，**不要**设为 `/public`、`/frontend` 等子目录。
   - 前端产物与接口都使用 `/static`、`/api` 等根路径，因此必须部署在域名根目录；如需部署到子目录，需同步修改前端 `publicPath` 与接口前缀。
2. 确保 `config/` 与 `storage/` 可写。
3. 浏览器访问 `/install`，填写 MySQL 地址、库名、账号密码，创建管理员账号。
   - 安装程序会自动建库、建表、生成 `config/config.php` 与 `config/installed.lock`。
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
- 登录 / 注册必须勾选同意服务协议（`/terms.html`），服务端仍会校验账号、密码与验证码。
- 建议生产环境只启用 HTTPS，并限制 `storage/`、`config/`、`app/` 的直接访问。Apache 由根目录 `.htaccess` 自动处理；Nginx 参考：

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/deepseek;
    index index.php index.html;

    client_max_body_size 128m;
    error_page 404 /404;

    location ~ ^/(app|config|storage|database|docs|frontend|scripts)(/|$) { deny all; }
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
7. 前端已做兼容：当 `/api/*` 返回 404 或 HTML 时，会自动改用 `/index.php?_r=/api/...` 重试；仍建议配置标准伪静态，便于日志与缓存管理。

## 错误页

`app/Views/404.html` 为自包含页面，沿用官方浅色/深色配色与品牌蓝，由 `index.php` 路由 `/404` 输出。Apache 通过 `.htaccess` 的 `ErrorDocument 404 /index.php?_r=/404` 生效；Nginx 需配置 `error_page 404 /404;`；开发用的 `app/Dev/router.php` 会把缺失路径交给 `index.php` 处理。

## 管理后台

访问 `/admin/`，使用管理员账号登录。默认管理员在安装向导中创建。

- 概览：用户、会话、消息、Token、附件统计。
- 用户：搜索、创建、角色调整、禁用/启用、重置密码、清除 API Key、删除用户及其数据、导出 CSV。
- 用户详情：查看全部账号字段（含明文 API Key、密码哈希）、会话 / 消息 / Token / 附件统计、最近会话与附件，并可调整角色、启用/禁用、重置密码、清除 Key 或删除用户。
- 会话：搜索、查看消息与原始 JSON、删除。
- 站点设置：站点名称、公告、是否开放注册、附件大小上限、单用户会话上限、DeepSeek API 地址。
- 操作日志：查看并导出 CSV。

## 常用命令

```bash
# PHP 语法检查
find app admin -name '*.php' -print0 | xargs -0 -n1 php -l

# 前端类型检查与构建
cd frontend && pnpm run type && pnpm run build
```

## 数据表

`users`（账号与明文 API Key）、`auth_tokens`（登录令牌）、`sessions`（完整官方 Session JSON）、`files` / `session_files`（附件原件与引用）、`staging_sessions` / `staging_files`（导入暂存）、`id_sequence`（雪花序列）、`login_attempts`（登录限流）、`captchas`（图形验证码，只存答案摘要）、`admin_logs`（审计）、`site_settings`（站点配置）。
