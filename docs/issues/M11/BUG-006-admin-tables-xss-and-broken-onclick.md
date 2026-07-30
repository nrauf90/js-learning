# BUG-006 - Admin tables were vulnerable to stored XSS / broken by quotes in names

- **Milestone:** M11
- **Found during:** review of admin panel built externally
- **Date:** 2026-07-30
- **Status:** closed — added `escapeHtml()` everywhere user text is rendered; switched inline `onclick` handlers to `data-*` attributes + event delegation

## How to reproduce (pre-fix)

1. Register a user whose name contains HTML, e.g.
   `<img src=x onerror=alert(1)>`, or an apostrophe, e.g. `O'Brien`.
2. Open `/admin-entries.html`, `/admin-payments.html`,
   `/admin-subscriptions.html`, or `/admin-categories.html` as admin.

## Expected

Names/notes render as plain text; edit/delete buttons keep working
regardless of punctuation in the name.

## Actual (pre-fix)

- `js/admin-entries.js`, `js/admin-payments.js`, `js/admin-subscriptions.js`
  interpolated `user.name`, `category.name`, and free-text `note` values
  directly into `innerHTML` with no escaping — a stored-XSS vector in an
  authenticated admin surface (could run arbitrary JS in an admin's
  session, e.g. to steal the bearer token from `localStorage`).
- `js/admin-categories.js` and `js/admin-users.js` built `onclick="window.editX(id, '${name}')"`
  strings by directly interpolating the name into a single-quoted JS
  string literal. Any name containing an apostrophe (e.g. `Kids'
  Education`, `O'Brien`) broke the generated markup and made the
  Edit/Delete buttons throw a JS syntax error instead of working.

## Fix

- Added a local `escapeHtml()` helper (matching the existing pattern in
  `js/admin.js` / `js/admin-users.js`) to every admin list renderer.
- Replaced inline `onclick="...'${name}'..."` handlers with `data-id` /
  `data-name` / `data-kind` attributes plus a single delegated `click`
  listener per table, so no user-controlled string is ever embedded in
  a JS string literal.

## Links

- `docs/milestones/M11-admin-panel.md`
- `docs/tasks/M11-tasks.md`
- Issues index: `docs/issues/README.md`
