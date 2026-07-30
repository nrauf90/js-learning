# M11 — Admin panel tasks

Depends on M10 complete. The admin panel's HTML/JS/PHP was built outside
this workflow; this task covers the review + bugfix pass that brought it
to a green QA state.

- [x] **M11-T1** Review externally-built admin panel and fix bugs found

## Completion log

### M11-T1 — done 2026-07-30

**Modified files**
- `backend/app/Models/User.php` — added `is_admin` to `$fillable` (BUG-001)
- `backend/app/Http/Controllers/Api/AuthController.php` — `userPayload()` now includes `is_admin` (BUG-002)
- `backend/app/Http/Controllers/Api/AdminController.php` — `payments()` filters on `provider`, not `gateway` (BUG-004)
- `js/admin-entries.js` — correct endpoint `/api/admin/cash-entries`; `type`/`note` fields; added `escapeHtml` (BUG-003, BUG-006)
- `js/admin.js`, `js/admin-payments.js` — read `payment.provider` instead of `.gateway` (BUG-004); added `escapeHtml` in `admin-payments.js`
- `js/admin-subscriptions.js` — removed non-existent `sub.trial_ends_at`, show `starts_at` instead; added `escapeHtml`
- `js/admin-categories.js` — added `escapeHtml`; switched inline `onclick` to `data-*` attributes + delegated click handler (BUG-006)
- `js/admin-users.js` — delete button switched to `data-*` attributes + delegated click handler (BUG-006)
- `admin-subscriptions.html` — "Trial Ends" column header → "Starts At"
- `css/styles.css` — added missing `.admin-badge-info` class
- `e2e/playwright.config.js` — added `'M11'` to `MILESTONE_ORDER` (BUG-005)
- `e2e/tests/m11-admin-panel.spec.js` — removed `waitForLoadState('networkidle')` (hangs on external CDN requests) in favor of `page.addInitScript` + targeted element assertions; category-creation tests now clean up after themselves
- `backend/tests/Feature/AdminPanelTest.php` — new, 11 tests covering the fixes above
- `backend/tests/Feature/AuthTest.php` — added `test_login_and_user_payload_include_is_admin_flag`
- `package.json` — `lint` now also checks `admin-entries.js`, `admin-payments.js`, `admin-subscriptions.js`, `admin-categories.js`
- `docs/issues/M11/BUG-001..006-*.md` — bug write-ups
- `docs/milestones/M11-admin-panel.md`, `docs/tasks/M11-tasks.md` (this file) — new

**QA notes**
1. `cd backend && php artisan test` — 65 pass (was 53 before this pass; +11 new `AdminPanelTest` +1 new `AuthTest` regression case).
2. `npm run lint` — passes with all admin JS files included.
3. `npm run qa:milestone -- M11` — 63 Playwright tests pass (regression M1–M11).
4. Manual: seed `php artisan db:seed --class=AdminUserSeeder`, log in as `admin@cashflow.local` / `admin123`, confirm the "Admin Panel" sidebar link stays visible and every admin page (`admin.html`, `admin-users.html`, `admin-subscriptions.html`, `admin-entries.html`, `admin-payments.html`, `admin-categories.html`) loads real data.

### M11-T1 — follow-up fix 2026-07-30

After the review above shipped, the user reported the Edit/Delete popups
on `admin-users.html` / `admin-categories.html` were stuck open on page
load and couldn't be closed. Root cause + fix documented in
`docs/issues/M11/BUG-007-admin-modals-always-visible-and-uncloseable.md`.

**Modified files**
- `css/styles.css` — added `.admin-modal[hidden] { display: none; }` so the `hidden` attribute (toggled by `js/admin-users.js` / `js/admin-categories.js`) actually hides the modal instead of being overridden by `.admin-modal { display: flex }`.

**QA notes**
1. Open `admin-users.html` as an admin — table shows immediately, no modal visible.
2. Click Edit on a user → only the Edit modal shows → Cancel closes it.
3. Click Delete on a user → only the Delete modal shows → `×` closes it.
4. Repeat 1–3 on `admin-categories.html`.
5. `npm run qa:milestone -- M11` — 63/63 pass (also caught and fixed unrelated data drift: a "Utilities" expense category had been deleted from the shared dev DB during earlier manual testing, breaking the M3 cashflow UI tests' `option` count assertion — restored via `php artisan db:seed --class=ExpenseCategorySeeder`, which is idempotent).
