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
- Message paging: the latest 10 messages load first; scrolling up loads earlier pages while keeping your position.
- Right-side outline: desktop shows 7 short bars (7 rounds at a time) at the right edge of the message area, 12px from the edge; spacing auto-scales with viewport height (32px normally, 22px minimum). Collapsed and expanded states are the same panel with the same scroll position — collapsing fades out the card background and text while keeping the bars — so every bar aligns one-to-one with its preview row and the highlight/position never resets after collapsing. Hovering fades the card and previews in; leaving fades them out again. Each request loads 20 items from a lightweight outline API; the wheel scrolls the preview list directly (no longer changes the selection), and clicking jumps to the message without a highlight animation; the jump loads the window around the target question and always includes the assistant reply that follows it. The outline API returns only question IDs, indexes and previews, never full messages.
- Scroll to bottom: whenever the view leaves the bottom (scrolling back through history or jumping to an earlier message), a circular button appears above the composer on the right; clicking it jumps to the bottom and resumes auto-follow, then the button fades away.
- Branding: the sidebar header shows the project icon (`static/site/favicon.png`) to the left of the site name, truncated together with the name.
- Sharing: click Share to enter selection mode; every message gets a circular checkbox, user/assistant turns are selected as a pair, and the composer is hidden. Select all / clear all sits left of the selected count, and the action bar stays pinned to the bottom. Share links use `/?share={token}` (also compatible with `/share/{token}`). Revoke links under Settings → Data → My share links.
- Search: search titles and message bodies; results jump to the matching message window with a short highlight. Sessions missing from the loaded sidebar page are added automatically, and the message window is fetched on demand.
- Batch management: the slider icon next to "Recent" enters multi-select with batch pinning and deletion.
- Pin sessions: pinned chats stay at the top.
- Undo a round: a large U-turn undo icon sits below user messages and next to the assistant action buttons.
- Token usage popover: a hollow database icon in the assistant action row opens "This round", listing cache hit, cache miss and output with price, tokens and cost (prices come from the admin site settings), plus "As of this round, context is X% full". Hidden when no usage is recorded.
- Context viewer: a database icon next to the composer caveat opens a popover with the whole chat's cumulative token breakdown (cache hit / miss / output and total cost) and current context usage.
- Docs: `/docs` renders Markdown files with a collapsible list; Chinese and English versions use the `.md` / `.en.md` naming convention.
- Web-search site icons: icons in AI search results go through `GET /api/site-icon?url=&page=`, which resolves relative paths against the source site first, then the API base, downloads them server-side into `uploads/cache/site-icons`, and caches them for 30 days. Missing icons return a placeholder instead of a `/api/files/{uuid}` 404.
- Favicon: a cute lavender whale (cream belly, black eye with highlight, pink blush) lives at `static/site/favicon.png` / `favicon.ico`. `index.html` declares it, and Nginx / Apache / the PHP entry all send a one-year immutable cache; missing `/favicon.ico` falls back to the static icon.
- Settings: terms and privacy entries, account panels, token usage (entry only), and share management.
- Token accounting: monotonic. Token totals only grow; undoing messages, switching branches, deleting chats or deleting users never reduces them (usage is archived before removal).
- Full admin console: users, user details, roles, status, sessions, shares, site settings, and CSV export. A fixed sidebar holds the tabs; navigation and detail views switch without a full reload.
- The admin console, installer, 404 page, and docs share the same `static/site/ui.css` design tokens and follow the site language and light/dark preference.
- Site name is centralized in `app/lang/zh-CN.php` and `app/lang/en-US.php`.

## Directory layout

```text
.
├── index.php              # Single entry: SPA shell + API routes + install/docs/admin/404 dispatch
├── index.html             # Front-end build output
├── static/                # Front-end build output (css / js / media) + site/ (icons and shared admin styles)
├── .htaccess              # Apache rewrite and security rules
├── .gitignore             # Runtime data, deps and temporary archives
├── LICENSE                # MIT license
├── app/                   # All backend modules
│   ├── Admin/             # Server-rendered admin console (/admin/)
│   ├── Controllers/       # JSON API controllers
│   ├── Services/          # Sessions, files, import, captcha, rendering, admin data
│   ├── Support/           # Config, database, auth, snowflake, request/response, i18n
│   ├── Views/             # Server-rendered public pages (404.html)
│   ├── Install/           # Installer logic and schema
│   ├── lang/              # Site strings
│   └── Dev/               # PHP built-in server router
├── docs/                  # Markdown docs + compatibility index.php
├── scripts/               # Config/deploy samples (nginx-vhost.conf, user.ini, cleanup-legacy.sh), never referenced by PHP
├── frontend/              # Official front-end source
└── uploads/               # Runtime data (config, logs, cache/site-icons, attachments, staging)
```

> Architecture: one React app, one PHP entry (`index.php`), and one communication style — JSON over `/api/*`. The admin console lives in `app/Admin` and switches pages via partial HTML (`X-Admin-Partial: 1`) without full reloads. `scripts/` only holds deployment samples and is blocked from direct web access; no PHP code may include it.

## Requirements

- PHP 8.1+ with `pdo_mysql`, `curl`, `mbstring`, `zip` (no `fileinfo` needed)
- MySQL 5.7+ / MariaDB 10.4+
- Node.js 20+ and pnpm 9+ (only to build the front end)

## Installation

1. Point the site root at this project root (the directory containing `index.php`).
2. Make `uploads/` writable.
3. Visit `/install` and provide the database settings, then create the administrator account.
4. Visit `/` to chat; administrators sign in at `/admin/`.
5. For large imports or attachments, raise PHP limits: a sample lives at `scripts/user.ini` (copy it to the site root as `.user.ini`), or set the same values in your PHP configuration. Nginx also needs `client_max_body_size 128m;`; `scripts/nginx-vhost.conf` already injects the PHP values.
6. Upgrading an existing install only requires replacing the code: the first database connection adds any missing tables or columns and converts non-`utf8mb4` tables to `utf8mb4` (`app/Support/Schema.php`, tracked in `uploads/config/schema.version`). If the database account lacks `CREATE` / `ALTER`, run `app/Install/schema.sql` manually once.
7. To remove leftovers from older layouts, run `sh scripts/cleanup-legacy.sh`; it deletes root `database/`, `admin/`, `install/`, `config/`, and `.user.ini` (all moved into `app/` or `scripts/` in the latest version).

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
- Settings: site name, announcement, registration, upload size, session limit, DeepSeek API base URL, token prices (cache hit / cache miss / output, CNY per 1M tokens) and max context; a one-click "auto-fetch max context" reads the models endpoint (requires at least one user with an API key).

## Common commands

```bash
# PHP syntax check
find app admin -name '*.php' -print0 | xargs -0 -n1 php -l

# Front-end type check and build
cd frontend && pnpm run type && pnpm run build
```

## Data tables

`users`, `auth_tokens`, `sessions`, `usage_archives` (deleted-session usage totals), `files`, `session_files`, `staging_sessions`, `staging_files`, `id_sequence`, `login_attempts`, `captchas`, and `site_settings`.
