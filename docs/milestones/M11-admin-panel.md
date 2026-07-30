# M11 — Admin panel

**Phase:** 3 · **Depends on:** M10

## Goal

An internal admin panel for managing users, subscriptions, cash entries,
payments, and expense categories. Built outside this agent workflow (in
a different editor) with the functionality mostly in place; this
milestone covers reviewing that work, fixing the bugs found, and
bringing it under the same QA/docs process as the rest of the app.

## Scope

- **Backend**: `is_admin` flag on `users`, `AdminOnly` middleware,
  `AdminController` (dashboard stats, users CRUD, subscriptions,
  cash-entries, payments, categories CRUD) under `/api/admin/*`.
- **Frontend**: `admin.html` (dashboard), `admin-users.html`,
  `admin-subscriptions.html`, `admin-entries.html`, `admin-payments.html`,
  `admin-categories.html` — all on the shared app shell (`js/shell.js`),
  with an "Administration" section shown only to `is_admin` users.
- **QA**: `e2e/tests/m11-admin-panel.spec.js`, `backend/tests/Feature/AdminPanelTest.php`.

## Bugs found & fixed during review

See `docs/issues/M11/` for full write-ups:

- **BUG-001** `is_admin` wasn't in `User::$fillable` — promoting/seeding
  an admin silently no-op'd.
- **BUG-002** `AuthController::userPayload()` omitted `is_admin` —
  the "Admin Panel" sidebar link disappeared moments after page load.
- **BUG-003** `js/admin-entries.js` called a non-existent
  `/api/admin/entries` endpoint and read `kind`/`description` instead of
  the real `type`/`note` fields.
- **BUG-004** Payments admin views (backend filter + `admin.js` +
  `admin-payments.js`) referenced a `gateway` column that doesn't exist;
  the real column is `provider`.
- **BUG-005** `e2e/playwright.config.js` still had `M10` as the last
  milestone, so `npm run qa:milestone -- M11` silently only ran the M1
  suite — the new admin-panel tests never actually executed.
- **BUG-006** Several admin tables rendered user-controlled text
  (names, notes) into `innerHTML` without escaping (stored XSS), and
  two used inline `onclick="...'${name}'..."` handlers that broke for
  any name containing an apostrophe.
- **BUG-007** `.admin-modal { display: flex }` in `css/styles.css` had
  no `[hidden]` override, so the Edit/Delete modals on
  `admin-users.html` and `admin-categories.html` were always visible
  (stacked on top of each other) and appeared impossible to close —
  the close buttons worked, the CSS just never actually hid the modal.

## Exit criteria

- [x] `is_admin` correctly settable via the seeder and the admin "Edit user" UI
- [x] Admin sidebar link stays visible for admin accounts after page load
- [x] Every admin page's frontend calls a real endpoint with matching field names
- [x] Edit/Delete modals on `admin-users.html` and `admin-categories.html` are hidden by default and close via Cancel/×/overlay
- [x] `backend/tests/Feature/AdminPanelTest.php` passes (11 tests)
- [x] `npm run qa:milestone -- M11` green (regression M1–M11, 63 tests)
