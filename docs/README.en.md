# DSW (DeepSeekWebAPIChat, PHP + MySQL)

A self-hosted web client based on the official [deepseek-ai/deepseek-demo](https://github.com/deepseek-ai/deepseek-demo) front end. The official look and interaction are preserved, while PHP + MySQL provide accounts, sessions, attachments, and a server-side DeepSeek API proxy so the browser never sees your API key.

## Features

- Account system: registration, sign-in, sign-out, password and username changes; snowflake IDs for users, sessions, and messages.
- Captcha: registration and sign-in show a unified captcha dialog; the admin login keeps an inline captcha. Answers are hashed, single-use, and expire after 5 minutes.
- Terms of Service: served by the `/docs` page (legacy `/terms` and `/terms.html` redirect to the User Agreement).
- Guest access: you can open the chat without signing in; sending messages or uploading files prompts you to sign in from Settings, and drafts are kept.
- API key: entered in Settings and stored **in plain text on the server** (or encrypted if configured); all DeepSeek requests are proxied server-side.
- Official-like chat experience: streaming replies, deep thinking, web search, branch switching, editing, retries, file uploads, image previews, speech input, and read-aloud.
- Server-side history: chat lists are cursor-paginated, with rename, delete, and clear.
- Message paging: the latest 20 messages load first; scrolling up loads earlier pages while keeping your position.
- Right-side navigator: about seven shorter, thicker, rounder bars are visible when collapsed, with wider spacing. Hover to preview on desktop with mouse-wheel scrolling; tap to expand on mobile. Prompts are fetched once from `/api/sessions/{id}/outline`, so there is no pagination; the current item is auto-centered.
- Sharing: click Share to enter selection mode; every message gets a circular checkbox, user/assistant turns are selected as a pair, and the composer is hidden. Select all / clear all sits left of the selected count. Share links use `/?share={token}` (also compatible with `/share/{token}`). Revoke links under Settings → Data → My share links.
- Search: search titles and message bodies; results jump to the matching message window with a short highlight.
- Batch management: the slider icon next to "Recent" enters multi-select with batch pinning and deletion.
- Pin sessions: pinned chats stay at the top.
- Undo a round: the hollow undo icon sits below user messages and next to the assistant action buttons (no more menu).
- Docs: `/docs` renders Markdown files with a collapsible list; Chinese and English versions use the `.md` / `.en.md` naming convention.
- Settings: terms and privacy entries, account panels, token usage (entry only; totals include deleted sessions), and share management.
- Full admin console: users, user details, roles, status, sessions, shares, site settings, and CSV export. The sidebar collapses and remembers its state; navigation and detail views switch without a full reload.
- The admin console, installer, 404 page, and docs share the same `assets/ui.css` design tokens and follow the site language and light/dark preference.
- Site name is centralized in `app/lang/zh-CN.php` and `app/lang/en-US.php`.

## Directory layout

```text
.
├── index.php              # Single entry: SPA shell + API routes + install/docs/admin/404 dispatch
├── index.html             # Front-end build output
├── static/                # Front-end build output (css / js / media)
├── .htaccess              # Apache rewrite and security rules
├── assets/                # Shared server-rendered styles (ui.css)
├── app/                   # All backend modules
│   ├── Admin/             # Server-rendered admin console (/admin/)
│   ├── Controllers/       # JSON API controllers
│   ├── Services/          # Sessions, files, import, captcha, rendering, admin data
│   ├── Support/           # Config, database, auth, snowflake, request/response, i18n
│   ├── Views/             # Server-rendered public pages (404.html)
│   ├── Install/           # Installer logic and schema
│   ├── lang/              # Site strings
│   └── Dev/               # PHP built-in server router
├── docs/                  # Markdown docs (rendered by /docs)
├── frontend/              # Official front-end source
└── uploads/               # Runtime data (config, logs, attachments, staging)
```

> Architecture: one React app, one PHP entry (`index.php`), and one communication style — JSON over `/api/*`. The admin console lives in `app/Admin` and switches pages via partial HTML (`X-Admin-Partial: 1`) without full reloads.

## Requirements

- PHP 8.1+ with `pdo_mysql`, `curl`, `mbstring`, `zip` (no `fileinfo` needed)
- MySQL 5.7+ / MariaDB 10.4+
- Node.js 20+ and pnpm 9+ (only to build the front end)

## Installation

1. Point the site root at this project root (the directory containing `index.php`).
2. Make `uploads/` writable.
3. Visit `/install` and provide the database settings, then create the administrator account.
4. Visit `/` to chat; administrators sign in at `/admin/`.
5. For large imports or attachments, raise PHP limits: `.user.ini` (128M / 900s) for FPM/CGI, `.htaccess` for `mod_php`; for Nginx add `client_max_body_size 128m;`.

## Build the front end

```bash
cd frontend
pnpm install
pnpm build
```

The build writes `index.html` and `static/` to the project root.

Development:

```bash
# Terminal 1: backend with dev router
php -S 127.0.0.1:8080 app/Dev/router.php

# Terminal 2: front-end hot reload
cd frontend && pnpm dev
```

## Security notes

- The API key is verified against DeepSeek `/models` and stored server-side; the front end only receives a masked value.
- All DeepSeek requests are proxied with cURL; the key never reaches the browser.
- Sessions use a random 32-byte token cookie (`ds_token`, HttpOnly + SameSite=Lax); only a SHA-256 digest is stored.
- Write APIs validate `Origin` and `X-CSRF-Token`; failed logins are rate-limited.
- Sign-in, registration, and admin login all require a captcha.
- Sign-in and registration require accepting the Terms of Service.
- Use HTTPS in production and deny direct access to `uploads/`, `app/`, `frontend/`, and `scripts/` (Apache handles this in `.htaccess`).

## Docs

- [Implementation plan](项目实现计划.md)
- [Changelog](CHANGELOG.md)
- [Development guidelines](开发规范.md)

## Admin console

Visit `/admin/` and sign in with an administrator account.

- Overview: users, sessions, messages, tokens, attachments, and shares.
- Users: search, create, change roles, enable/disable, reset passwords, clear API keys, delete users, and export CSV.
- Sessions: search, inspect messages and raw JSON, delete.
- Shares: search, revoke/restore, delete.
- Settings: site name, announcement, registration, upload size, session limit, and DeepSeek API base URL.

## Common commands

```bash
# PHP syntax check
find app admin -name '*.php' -print0 | xargs -0 -n1 php -l

# Front-end type check and build
cd frontend && pnpm run type && pnpm run build
```

## Data tables

`users`, `auth_tokens`, `sessions`, `usage_archives` (deleted-session usage totals), `files`, `session_files`, `staging_sessions`, `staging_files`, `id_sequence`, `login_attempts`, `captchas`, and `site_settings`.
