# Changelog

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
