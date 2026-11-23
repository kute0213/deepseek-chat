# DeepSeek 网页版（PHP + MySQL）

基于官方 [deepseek-ai/deepseek-demo](https://github.com/deepseek-ai/deepseek-demo) 前端实现的自建网页版。前端保持官方交互与视觉，后端使用 PHP + MySQL 提供账号、会话、附件存储，并代理 DeepSeek API，浏览器不接触 API Key。

## 功能

- 自建账号系统：注册、登录、退出、修改密码；用户、会话、消息 ID 全部使用雪花算法。
- API Key 由用户在设置中填写，**明文保存在服务器数据库**，由服务端代理聊天、附件、语音等全部 DeepSeek 请求。
- 与官方一致的聊天体验：流式回复、深度思考、智能搜索、分支切换、消息编辑/重试、附件上传、图片预览、语音输入与朗读。
- 会话与附件保存在服务器：支持多设备登录，历史会话列表、重命名、删除、清空。
- 支持官方格式聊天记录 ZIP 导入（导入即彻底覆盖服务器上的全部会话与附件），以及导出。
- 完整管理后台：用户管理、会话查看、站点设置、操作日志、CSV 导出。

## 目录结构

```text
.
├── index.php              # API 路由入口
├── install.php            # 安装向导（配置数据库、创建管理员）
├── router.php             # PHP 内置服务器路由（开发用）
├── index.html             # 前端构建产物（由 frontend 构建生成）
├── static/                # 前端构建产物
├── app/                   # 业务代码
│   ├── Controllers/       # API 控制器
│   ├── Services/          # 会话、附件、导入、DeepSeek 代理、后台数据
│   └── Support/           # 配置、数据库、认证、雪花、请求响应
├── admin/                 # 服务端渲染管理后台（/admin/）
├── config/                # 配置（config.php 由安装向导生成，不入库）
├── database/schema.sql    # 数据表结构
├── deploy/                # Nginx / Apache 部署示例
├── docs/                  # 项目实现计划
├── frontend/              # 官方前端源码（独立构建）
├── scripts/               # 官方 demo 参考仓库（不提交 Git）
└── storage/               # 运行期附件与日志（不入库）
```

## 环境要求

- PHP 8.1+（需要 `pdo_mysql`、`curl`、`mbstring`、`fileinfo`）
- MySQL 5.7+ / MariaDB 10.4+
- Node.js 20+、pnpm 9+（仅构建前端需要）

## 安装

1. 将站点根目录指向本项目根目录（不是 `frontend/`）。
2. 确保 `config/` 与 `storage/` 可写。
3. 浏览器访问 `/install.php`，填写 MySQL 地址、库名、账号密码，创建管理员账号。
   - 安装程序会自动建库、建表、生成 `config/config.php` 与 `config/installed.lock`。
   - 若数据库已存在同名库，请确认使用空库或备份后继续。
4. 安装完成后访问 `/`，使用管理员账号登录。

## 前端构建

```bash
cd frontend
pnpm install
pnpm build
```

构建产物直接输出到项目根目录的 `index.html` 与 `static/`，与 PHP 接口同源部署，无需额外配置跨域。

开发调试（可选）：

```bash
# 终端 1：后端
php -S 127.0.0.1:8080 router.php

# 终端 2：前端热更新，/api 与 /admin 自动代理到 8080
cd frontend && pnpm dev
```

## API Key 与安全说明

- 用户填写的 API Key 会先调用 DeepSeek `/models` 校验，通过后**明文存入 `users.api_key`**，前端仅拿到 `sk-****后 4 位` 的掩码。
- 所有 DeepSeek 请求都由服务端 cURL 转发，Key 不会下发到浏览器。
- 登录态使用随机 32 字节 Token（Cookie `ds_token`，HttpOnly + SameSite=Lax），数据库只保存 SHA-256 摘要。
- 所有写接口校验 `Origin` 与 `X-CSRF-Token`；登录失败有频率限制。
- 建议生产环境只启用 HTTPS，并限制 `storage/`、`config/`、`app/` 的直接访问（`.htaccess` 已内置规则，Nginx 见 `deploy/nginx.conf.example`）。

## 管理后台

访问 `/admin/`，使用管理员账号登录。默认管理员在安装向导中创建。

- 概览：用户、会话、消息、Token、附件统计。
- 用户：搜索、创建、角色调整、禁用/启用、重置密码、清除 API Key、删除用户及其数据、导出 CSV。
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

`users`（账号与明文 API Key）、`auth_tokens`（登录令牌）、`sessions`（完整官方 Session JSON）、`files` / `session_files`（附件原件与引用）、`staging_sessions` / `staging_files`（导入暂存）、`id_sequence`（雪花序列）、`login_attempts`（登录限流）、`admin_logs`（审计）、`site_settings`（站点配置）。
