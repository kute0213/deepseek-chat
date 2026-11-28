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
- 消息分段加载：打开会话先加载最近 10 条消息，向上滚动到顶部自动加载更早的 10 条，并保持当前阅读位置；列表顶部会显示「正在加载更早的对话」，没有更早记录时自动隐藏。
- 右侧会话导航：桌面端在消息区右缘显示一列短横线，鼠标悬浮展开前 50 个字预览；短横数量按屏幕高度自适应，每次从精简大纲接口读取 20 条，滚轮平滑切换、点击跳转。大纲接口只返回提问 ID、序号与预览，不加载完整聊天记录。
- 会话分享：会话标题栏或会话菜单点击分享后直接进入选择模式，所有消息左侧出现圆形复选框，点击消息任意位置即可选中，用户名与 AI 回复成对勾选，聊天输入框隐藏；选择条左侧提供「全选 / 取消全选」，并固定在消息区底部不随内容滚动；勾选后生成只读快照链接（`/?share={token}`，同时兼容 `/share/{token}`）；公开分享页可查看所选对话，登录后可一键「继续对话」，把快照复制成自己的新会话继续使用；「设置 → 数据管理 → 我的分享链接」可进入二级面板查看与撤销，撤销后记录直接删除，管理后台保留分享管理。
- 对话搜索：侧边栏搜索按钮支持按标题与消息正文检索全部会话；弹窗顶部输入，输入关键词后才展开结果框，点击结果直接打开命中消息所在的 10 条窗口并定位高亮；会话尚未出现在侧边栏分页中时会自动补入列表，目标消息未加载时直接拉取它所在的消息集。
- 会话批量管理：侧边栏「最近」标题右侧的滑块图标进入多选模式，会话前出现圆形复选框，新建对话变为「选择对话 + 关闭」，底部设置替换为置顶与删除，支持全选 / 取消全选与批量置顶 / 删除。
- 会话置顶：会话菜单支持置顶（空心图钉图标）/ 取消置顶、分享与多选，置顶会话固定排在列表最前。
- 撤销某轮对话：用户消息下方与 AI 回答的操作区都提供大回旋 U 型撤销图标（与复制、重新生成等按钮并排），确认后删除该轮提问、回答及其后的所有消息（含其他分支），并立即同步服务端。
- Token 用量浮层：AI 回答操作区新增空心数据库图标，点击展示「本轮消耗额度」——按缓存命中（0.02 元 / 百万 tokens）、缓存未命中（1 元）、输出（4 元）三档列出单价、用量与金额，底部提示「截止到本轮，上下文已占用 X%」；无用量记录时不显示入口。
- 上下文查看：输入框下方「内容由 AI 生成」小字旁提供可点击的「上下文 X%」入口，浮层中展示整个会话的累计 Token 明细（缓存命中 / 未命中 / 输出与合计费用）以及当前上下文占用比例。
- 文档路由：`/docs` 读取 `docs/` 下的 Markdown 并自动渲染为 HTML，左侧为可折叠文档列表；入口统一由 `index.php` 分发，不再依赖目录索引；中英文文档采用 `名称.md` / `名称.en.md` 命名，按当前界面语言自动选择对应版本。
- 联网搜索来源图标：搜索结果里的站点图标统一经 `GET /api/site-icon?url=&page=` 由服务端解析、下载并缓存到 `uploads/cache/site-icons`（相对路径先按来源站点、再按 API 基址解析），30 天服务端缓存 + 浏览器长缓存；缺失时返回占位图，不再出现 `/api/files/{uuid}` 的 404。
- 站点图标：可爱的浅紫蓝鲸图标（奶油色肚皮、黑眼白高光、粉色腮红）位于 `static/site/favicon.png` / `favicon.ico`，`index.html` 显式声明；Nginx / Apache / PHP 入口三层都做一年 `immutable` 服务端缓存，`/favicon.ico` 缺失时自动回落到静态图标，AI 或浏览器再次访问不会产生 404。
- 设置中心：新增「用户协议」标签页（用户协议 / 隐私政策入口）；账号页将修改用户名、修改密码改为两个按钮并进入二级面板，退出登录下方新增注销账号；数据管理新增 Token 用量入口（仅保留「查看」入口，不再内嵌预览进度条）、「查看」进入用量二级面板，以及「我的分享链接 → 去管理」。
- Token 计量：只增不减。会话 Token 采用历史最大值累加，撤销消息、切换分支、删除会话、删除用户都不会让总量回退；已删除会话与已删除用户的用量会先归档再移除。
- 交互动画与官方保持一致：侧边栏展开 / 收起宽度过渡、弹窗淡入上浮、菜单缩放、会话项悬浮过渡、批量操作栏滑入、搜索结果与分享页逐条淡入、搜索跳转短暂高亮，并完整适配 `prefers-reduced-motion`。
- 支持官方格式聊天记录 ZIP 导入（导入即彻底覆盖服务器上的全部会话与附件），导入过程带进度条，并兼容不带索引的旧版归档；导出时由浏览器先向服务端拉取全部会话与消息，再在本地生成 ZIP，全程显示进度，不上传任何数据到第三方。
- 完整管理后台：用户管理、用户详情（全部字段含明文 API Key、密码哈希、会话与附件统计）、角色 / 状态权限调整、会话查看、分享管理、站点设置、CSV 导出；左侧固定标签页导航，切换页面与会话详情通过局部渲染完成，选中后不刷新整页；后台与文档页会跟随站点设置的语言与明暗主题。
- 管理后台、安装向导、404 页与文档页统一使用同一套官方原版组件样式 `static/site/ui.css`（胶囊按钮、40px 控件、24px 弹窗圆角、0.2s 缓动，自动适配浅色 / 深色模式），样式只有一份来源，不再各页各写一套。
- 站点名称集中维护：服务端页面通过语言文件 `app/lang/zh-CN.php` + `app/Support/Lang.php` 读取 **DSW / DeepSeekWebAPIChat**，前端默认名称与数据库默认值同步。

## 目录结构

```text
.
├── index.php              # 唯一 PHP 入口：首页输出 + API 路由 + 安装/文档/后台/404 分发
├── index.html             # 前端构建产物（由 frontend 构建生成）
├── .htaccess              # Apache 重写与安全规则（唯一保留在根目录的运行期文件）
├── static/                # 前端构建产物（css / js / media）+ site/（站点图标与后台共享样式）
├── app/                   # 全部后端业务代码（按模块分类）
│   ├── Admin/             # 服务端渲染管理后台（/admin/，含 pages 模板）
│   ├── Controllers/       # API 控制器（统一 JSON 通信）
│   ├── Services/          # 会话、附件、导入、验证码、首页渲染、后台数据
│   ├── Support/           # 配置、数据库、认证、雪花、请求响应、语言文件读取
│   ├── Views/             # 服务端渲染的公共页面（404.html）
│   ├── Install/           # 安装向导逻辑与建表脚本
│   ├── lang/              # 站点文案（zh-CN.php / en-US.php）
│   └── Dev/               # PHP 内置服务器开发路由
├── docs/                  # 中英文 Markdown 文档 + index.php 兼容入口
├── scripts/               # 配置与部署杂物（nginx-vhost.conf、user.ini、cleanup-legacy.sh），禁止被 PHP 引用
├── frontend/              # 官方前端源码（独立构建）
└── uploads/               # 运行期数据（不入库）
    ├── config/            # config.php、installed.lock（安装生成）
    ├── logs/              # 应用日志
    ├── attachments/{用户ID}/{附件ID}
    └── staging/{用户ID}/  # 历史导入暂存
```

> 结构与通信约定：前端只有一个 React 应用，所有动态数据统一通过 `/api/*` 的 JSON 接口读写；后端只有一个 `index.php` 主入口，管理后台作为 `app/Admin` 模块挂在 `/admin/`，页面间切换使用局部 HTML 片段（`X-Admin-Partial: 1`）。`scripts/` 只放部署示例与杂物，**任何 PHP 代码都不得引用**，并在 Web 层禁止直接访问。

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
5. 导入历史或上传大附件需要更高的 PHP 限制：根目录已不放置 `.user.ini`，示例在 `scripts/user.ini`（复制为站点根目录的 `.user.ini` 即生效）；使用 `scripts/nginx-vhost.conf` 时已通过 `fastcgi_param PHP_VALUE` 设置 128M / 900s，面板站点请在「PHP 配置」中同步，Nginx 还需 `client_max_body_size 128m;`。
6. 从旧版本升级：先执行 `sh scripts/cleanup-legacy.sh`，删除根目录遗留的 `database/`、`admin/`、`install/`、`config/` 与 `.user.ini`；最新版本的对应功能都已收进 `app/`。
6. 已安装站点直接覆盖代码即可升级：首次数据库连接会自动补齐新增表与新增列（`app/Support/Schema.php`，版本标记写入 `uploads/config/schema.version`）。若数据库账号没有 `CREATE` / `ALTER` 权限，请先手动执行一次 `app/Install/schema.sql`，避免旧库缺列导致接口 500。
7. 首页由 `index.php` 读取构建产物 `index.html` 后注入站点名称与 `window.__SITE__` 再输出，因此默认首页需优先 `index.php`。

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
- 建议生产环境只启用 HTTPS，并限制 `uploads/`、`app/` 的直接访问。Apache 由根目录 `.htaccess` 自动处理；Nginx 可直接使用下方完整配置（按实际域名、项目路径、PHP-FPM socket 和证书路径修改即可）。

### Nginx 完整生产配置

```nginx
# /etc/nginx/conf.d/deepseek.conf
# 使用前按实际环境替换：
#   example.com            域名
#   /var/www/deepseek      项目根目录（包含 index.php 的那一层）
#   /run/php/php8.2-fpm.sock  PHP-FPM socket
#   /etc/letsencrypt/...   SSL 证书路径
# 修改后执行：nginx -t && systemctl reload nginx

# ---------- HTTP：全部跳转 HTTPS ----------
server {
    listen 80;
    listen [::]:80;
    server_name example.com www.example.com;
    return 301 https://$host$request_uri;
}

# ---------- HTTPS：主站 ----------
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;                      # 旧版 Nginx 改为：listen 443 ssl http2;
    server_name example.com www.example.com;

    root /var/www/deepseek;
    index index.php index.html;

    # ---------- SSL ----------
    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers off;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 1d;

    # ---------- 上传、请求体与超时（导入历史 / 大附件 / 长回复） ----------
    client_max_body_size   128m;
    client_body_timeout    900s;
    send_timeout           900s;
    fastcgi_read_timeout   900s;
    fastcgi_send_timeout   900s;

    # ---------- 安全响应头 ----------
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header Permissions-Policy "geolocation=(), microphone=(self)" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # ---------- 内部目录与隐藏文件禁止访问 ----------
    location ~ ^/(app|uploads|frontend|scripts)(/|$) {
        deny all;
    }
    location ~ /\.(?!well-known) {
        deny all;
    }

    # ---------- 统一入口：安装 / 文档 / 管理后台 / 分享 / 旧协议 / 404 ----------
    location = /install        { rewrite ^ /index.php last; }
    location = /install.php    { rewrite ^ /index.php last; }
    location = /terms          { rewrite ^ /index.php last; }
    location = /terms.html     { rewrite ^ /index.php last; }
    location = /404.html       { rewrite ^ /index.php?_r=/404 last; }
    location = /docs           { rewrite ^ /index.php last; }
    location ^~ /docs/         { rewrite ^ /index.php last; }
    location = /admin          { rewrite ^ /index.php last; }
    location ^~ /admin/        { rewrite ^ /index.php last; }
    location = /share          { rewrite ^ /index.php last; }
    location ^~ /share/        { rewrite ^ /index.php last; }

    # ---------- 静态资源长缓存（构建产物带 hash，可放心 immutable） ----------
    location ^~ /static/ {
        expires 30d;
        add_header Cache-Control "public, max-age=2592000, immutable";
        try_files $uri =404;
    }
    # 网站图标一年缓存，避免 AI / 浏览器反复请求出现 /favicon.ico 404
    location = /favicon.ico {
        expires 365d;
        add_header Cache-Control "public, max-age=31536000, immutable";
        try_files /static/site/favicon.ico =404;
    }
    location = /static/site/favicon.ico {
        expires 365d;
        add_header Cache-Control "public, max-age=31536000, immutable";
        try_files $uri =404;
    }
    location = /static/site/favicon.png {
        expires 365d;
        add_header Cache-Control "public, max-age=31536000, immutable";
        try_files $uri =404;
    }
    location ^~ /static/site/ {
        expires 30d;
        add_header Cache-Control "public, max-age=2592000";
        try_files $uri =404;
    }
    location = /static/site/ui.css {
        expires 7d;
        add_header Cache-Control "public, max-age=604800";
        try_files $uri =404;
    }
    location = /index.html {
        add_header Cache-Control "no-store";
    }

    # ---------- PHP：仅执行真实存在的 PHP 文件 ----------
    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param SCRIPT_NAME     $fastcgi_script_name;
        # 根目录不再放 .user.ini，由 Nginx 直接设置 PHP 上传与超时限制
        fastcgi_param PHP_VALUE "upload_max_filesize=128M\npost_max_size=128M\nmax_execution_time=900\nmax_input_time=900\nmemory_limit=256M";
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_buffering off;          # 流式回复必须关闭缓冲
        fastcgi_request_buffering off;  # 大文件上传边收边转发
    }

    # ---------- 其余请求交给唯一入口（SPA / API） ----------
    location / {
        try_files $uri /index.php$is_args$args;
    }

    # ---------- 错误页 ----------
    error_page 404 /index.php?_r=/404;

    access_log /var/log/nginx/deepseek.access.log;
    error_log  /var/log/nginx/deepseek.error.log;
}
```

> Nginx 环境无需 `.htaccess`；根目录不再放 `.user.ini`，上传与超时限制由上方 `fastcgi_param PHP_VALUE` 与 `client_max_body_size` 覆盖（其它环境可复制 `scripts/user.ini` 到根目录）。若使用子目录部署，需要同步修改前端 `publicPath` 与接口前缀。

### Nginx 虚拟主机版（宝塔 / aaPanel / cPanel 等面板）

无法编辑完整 vhost、只能在面板里填写「伪静态」时，直接使用 [scripts/nginx-vhost.conf](scripts/nginx-vhost.conf)：

- **一、伪静态规则**：整段粘贴到面板「伪静态 / 重写」输入框，已包含路由回退、内部目录防护、静态缓存与上传超时；
- **二、完整 server 块**：适用于可自行编辑站点配置的环境，SSL 由面板接管时用 HTTP 版即可；
- 站点根目录必须是包含 `index.php` 的项目根，PHP 版本 8.1+，首次访问 `/install` 初始化；
- 保存后重启 / 重载 Nginx 生效；若面板提示某行不支持，删掉「上传体积与超时」那几行，改在面板的 PHP 配置中设置 128M 与 900s。

## 访问全站 404 排查

1. **站点根目录 / 运行目录**：根目录必须是含 `index.php` 的项目根；面板的“运行目录”留空或 `/`。指向 `frontend/`、`public/` 会导致所有页面 404。
2. **默认首页顺序**：`/` 必须由 `index.php` 输出（PHP 会注入站点信息）。Apache 已由 `.htaccess` 的 `DirectoryIndex index.php index.html` 处理；Nginx 必须配置 `index index.php index.html;`，否则 `/` 会直接返回未经注入的 `index.html`。
3. **Apache 重写**：启用 `mod_rewrite`，并允许 `.htaccess`（`AllowOverride All`）；否则 `/api/*`、无扩展名的前端路由会 404。
4. **Nginx 重写**：`location /` 必须有 `try_files $uri $uri/ /index.php$is_args$args;`。
5. **PHP 内置服务器**：必须使用 `php -S 127.0.0.1:8080 app/Dev/router.php` 启动，不能省略路由脚本。
6. 若只有安装页可访问：确认 `index.html` 与 `static/` 已上传，且没有把应用部署在子目录。
7. 前端已做兼容：当 `/api/*` 返回 404 或 HTML 时，会自动改用 `/index.php?_r=/api/...` 重试，`limit`、`cursor` 等查询参数会原样保留；仍建议配置标准伪静态，便于日志与缓存管理。

## 403 排查

- `/docs` 或 `/admin` 返回 403：通常是目录没有默认首页，同时服务器未启用 URL 重写。管理后台与安装向导已合并进 `app/Admin`、`app/Install`，由唯一入口 `index.php` 分发；`docs/index.php` 仅用于文档目录的无重写兜底。
- Apache：确认虚拟主机 `AllowOverride All` 且已启用 `mod_rewrite`；修改后重启 Apache。`/docs`、`/admin`、`/install` 均由 `.htaccess` 转发到 `index.php`。
- Nginx：直接使用上方完整配置，`/docs`、`/admin`、`/share` 等前缀都已显式交给 `index.php`，不依赖目录索引。
- 仍然 403 时检查目录权限：Web 用户需要可读 `docs/`、`app/`，`uploads/` 需要可写。

## 错误页

公开错误页位于 `app/Views/404.html`，由 `index.php` 统一输出，与安装向导、管理后台、文档页共用 `static/site/ui.css`，配色、控件、圆角与动画完全一致。Apache 通过 `.htaccess` 的 `ErrorDocument 404 /index.php?_r=/404` 生效；Nginx 使用上文配置中的 `error_page 404 /index.php?_r=/404;`；开发用的 `app/Dev/router.php` 会把缺失路径交给 `index.php` 处理。

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
find app -name '*.php' -print0 | xargs -0 -n1 php -l

# 前端类型检查与构建
cd frontend && pnpm run type && pnpm run build
```

## 数据表

`users`（账号与明文 API Key）、`auth_tokens`（登录令牌）、`sessions`（完整官方 Session JSON）、`usage_archives`（已删除会话的 Token / 消息 / 会话数归档，保证总用量不因删除而减少）、`files` / `session_files`（附件原件与引用）、`staging_sessions` / `staging_files`（导入暂存）、`id_sequence`（雪花序列）、`login_attempts`（登录限流）、`captchas`（图形验证码，只存答案摘要）、`admin_logs`（审计）、`site_settings`（站点配置）。
