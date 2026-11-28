# Changelog

## 2026-11-28 (outline redesign, emoji fix, dead-code cleanup)

- Removed the local `/.pnpm-store` build cache (about 260 MB, never committed).
- Redesigned the right-side outline: 7 rounds at a time; bar spacing auto-scales with viewport height (28px normally, 20px minimum); the hover preview is now a card whose 30px right padding wraps the bars inside it, with the bars drawn above the card.
- Fixed emoji rendering: added `Apple Color Emoji` / `Segoe UI Emoji` / `Noto Color Emoji` to the UI font stack for Windows, macOS, Android and Linux.
- Removed 3 unused exports: `deleteApiKey` in `services/auth.ts`, `getThinkingSeconds` in `services/chat/reply.ts`, and `deleteFile` in `services/api/client.ts`.
- Rebuilt static assets; `index.html` hashes updated.

## 2026-11-28 (entry cache fix, 7-bar outline, project cleanup)

- Fixed whole-site styling loss (including the left sidebar) after deploys: entry HTML is always served with `no-store` so stale HTML can no longer reference deleted hashed assets. `HomePage::render()` now sends `Cache-Control: no-store, must-revalidate`; `.htaccess` disables caching for html/php; the Nginx rewrite block adds `add_header Cache-Control "no-store" always` to `location /`.
- Reworked the right-side outline: a fixed 7 bars spanning 70% of the screen height (pitch recalculated on resize); the hover preview panel now fades in and out and slides over the bar rail.
- Project cleanup: removed the `.trae-html-share-packages/` archive and the redundant `*.module.css.d.ts` declarations (covered by `env.d.ts`); expanded `.gitignore` for dependencies, build caches, temporary archives and OS files.
- Rebuilt static assets and `index.html`; hashes updated.

## 2026-11-28 (pricing settings and right-side outline)

- Removed the "Free" tag from the usage popover.
- Added token price management: admin "Site settings" now configures cache hit / cache miss / output prices (CNY per 1M tokens) and max context; the usage popover estimates from these values.
- Admin can one-click "auto-fetch max context", which reads the DeepSeek models endpoint and saves the value (requires at least one user with an API key).
- The context entry next to the composer is now a database icon matching the assistant action row.
- Right-side outline: wider bar spacing and at most 5 bars; the wheel scrolls the preview list directly (no longer changes the selection); removed the highlight and smooth-scroll jump animation.

## 2026-11-28 (whale favicon, token usage popovers, context viewer)

- Replaced the favicon with a cute lavender whale (cream belly, black eye with highlight, pink blush); regenerated `static/site/favicon.png` and a multi-size `favicon.ico`, referencing `?v=20261128`.
- Added a per-round token usage popover: a hollow database icon in the assistant action row lists cache hit / cache miss / output with price, tokens and cost, plus "As of this round, context is X% full".
- Added a context viewer: a clickable entry next to the composer caveat opens the chat's cumulative token breakdown (cache hit / miss / output and total cost) and current context usage.
- Usage math lives in `frontend/src/services/chat/usage.ts`, estimated at off-peak pricing of 0.02 / 1 / 4 CNY per 1M tokens with a 128K context window.
- Unified icon style: undo is now a large U-turn arrow, and share and other buttons use thin hollow strokes; added a database icon for the usage entry.
- Re-verified the admin entry: `index.php` and `app/Admin/index.php` use `require_once` with a one-shot `Bootstrap::boot()` guard, so `Cannot declare class App\Bootstrap` no longer appears.
- Kept the root slim: Nginx config, `user.ini`, and other samples live in `scripts/`; web-search site icons are cached under `uploads/cache/site-icons`.

## 2026-11-28 (admin entry crash fix, cached web-search site icons, root cleanup)

- Fixed `Cannot declare class App\Bootstrap`: `index.php` and `app/Admin/index.php` now use `require_once`, and `Bootstrap::boot()` is guarded so repeated bootstrapping from `/admin`, `/install`, and the main entry no longer fatals.
- Added `GET /api/site-icon?url=`: site icons from AI web-search results are resolved and downloaded server-side (relative paths resolve against the API base) and cached under `uploads/cache/site-icons` for 30 days. Missing icons return a placeholder instead of a `/api/files/{uuid}` 404.
- Removed `.user.ini` from the root; the sample now lives at `scripts/user.ini`. The Nginx config sets 128M / 900s via `fastcgi_param PHP_VALUE`; panel-hosted sites can mirror the values in their PHP settings.
- Verified the root stays slim: `database/`, `admin/`, `install/`, and `config/` are absent (admin is in `app/Admin`, install in `app/Install`, runtime config in `uploads/config`); deployment samples live in `scripts/` and are never referenced by PHP.

## 2026-11-27 (right-side outline, lightweight API, root cleanup)

- Added a desktop right-side outline: short bars along the message area edge, hover shows 50-character previews, bar count adapts to screen height, the wheel moves the selection smoothly, and clicking jumps to the message.
- Added `GET /api/sessions/{id}/outline?limit=20&offset=&around=` returning only user-message IDs, global indexes, and 50-character previews, at most 20 per request.
- Message pages now load 10 messages at a time (previously 20).
- Fixed search / outline jumps when the target message was not loaded: the containing message window is fetched on demand; sessions missing from the loaded sidebar page are added automatically.
- Fixed the desktop share action bar scrolling with content; it now sticks to the bottom of the message area.
- Sidebar header now shows search then collapse, matching the official spacing.
- Added an original blended favicon: DSH-GO rose pixel blocks as the tail, a DeepSeek whale as the body, and a chat bubble for the creative twist. Output at `static/site/favicon.png` / `favicon.ico`, declared in `index.html`, with one-year immutable caching on Nginx / Apache / the PHP entry and a `/favicon.ico` fallback.
- Merged `assets/` into `static/site/`; the shared admin stylesheet `ui.css` and the site icons now live under the build-managed static directory with long cache headers.
- `GET /api/files/{id}` now sends `Cache-Control: private, max-age=31536000, immutable`; file IDs are one-off UUIDs, so browsers and AI clients no longer re-fetch them repeatedly.
- Root cleanup: removed the `admin/` and `install/` compatibility entries (handled by `index.php`), moved deployment samples to `scripts/` (never referenced by PHP, blocked from direct access), and removed `deploy/`.

## 2026-11-26 (navigation polish, sharing, undo, admin console)

### Right-side navigator

- Wider spacing between the short bars.
- Hover expands the preview on desktop; seven items per page; the active item is smoothly centered when opened.
- Still available on mobile.

### Sidebar

- Mobile drawer now stays mounted and slides with a mask and shadow; the leftover shadow after closing is fixed.
- Selecting a session no longer closes the drawer.
- Search button moved to the left and the collapse toggle to the right.
- Icons: thick-head pin for pinning, slider icon for multi-select, curved arrow for sharing.

### Sharing

- Clicking Share enters selection mode: every message gets a circular checkbox, the composer is hidden, and user/assistant turns are selected as a pair.

### Undo

- Undo uses an arrow icon and is available below user messages and in the assistant's more menu.

### Settings

- Token usage keeps only the entry; totals now include deleted sessions.

### Docs and admin

- Fixed the `/docs` 403 by serving the route through `docs/index.php` instead of exposing the directory.
- Removed the old `terms.html`; `/terms` and `/terms.html` redirect to the User Agreement in `/docs`.
- Admin sidebar can collapse and remembers its state; page and detail switches render without a full reload.
- Admin and docs follow the site language and light/dark preference; docs ship Chinese/English versions named `name.md` / `name.en.md`.

## 2026-11-26 (share selection and pinning)

- Sharing requires selecting messages first; snapshots include the chosen prompts and their replies.
- Share dialog is globally mounted and shared by the chat header, mobile header, and session menu.
- Links use `/?share={token}` and remain compatible with `/share/{token}`.
- Public share pages are read-only; signed-in users can continue the conversation as a new session.

## 2026-11-26 (search and positioning)

- Search returns a window around the matching message, so opening a result jumps straight to it with a short highlight.

## 2026-11-26 (pinning and batch actions)

- Pin/unpin from the session menu; pinned chats stay first.
- Batch mode supports batch pin, unpin, and delete, and a session can be added from its menu.

## 2026-11-26 (navigation, settings, docs)

- Right-side dots became short bars with a wider active bar; 20 items per page with paging buttons; mobile support.
- Touch expands the preview first; desktop clicks jump directly without smooth scrolling.
- Sidebar collapse animates its width.
- Top bar keeps only collapse and search buttons.
- Batch entry moved next to "Recent" with a slider icon; round checkboxes replace the avatar.
- Settings adds a Terms tab, account sub-panels, account deletion, token usage, and share management.
- Docs moved to `/docs` with a collapsible Markdown list.
- Admin console uses the same design system; operation logs removed.
