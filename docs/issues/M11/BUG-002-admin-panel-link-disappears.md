# BUG-002 - "Admin Panel" sidebar link disappeared after page load

- **Milestone:** M11
- **Found during:** e2e regression (`admin sidebar shows Admin Panel link`)
- **Date:** 2026-07-30
- **Status:** closed — `AuthController::userPayload()` now includes `is_admin` (`backend/app/Http/Controllers/Api/AuthController.php`)

## How to reproduce (pre-fix)

1. Log in as `admin@cashflow.local` / `admin123` on `/login.html`.
2. Land on `dashboard.html`. The "Admin Panel" link briefly exists, then
   disappears from the sidebar within a second or two.

## Expected

The "Admin Panel" link stays visible in the sidebar for admin accounts.

## Actual (pre-fix)

`/api/login`, `/api/register`, and `/api/user` never returned `is_admin`
in the `user` object. `js/shell.js` renders the sidebar synchronously
from the cached `localStorage` user (which briefly has `is_admin: true`
right after login), but then does a background `apiGet('/api/user')` to
refresh the cached user and re-renders the sidebar if the admin flag
changed. Since the fetched payload was missing `is_admin` (`undefined`),
it always looked "changed" and the re-render used `isAdmin = false`,
wiping out the admin section.

## Evidence

- Playwright: `e2e/tests/m11-admin-panel.spec.js` → "admin sidebar shows Admin Panel link"
- Regression test: `backend/tests/Feature/AuthTest.php::test_login_and_user_payload_include_is_admin_flag`

## Links

- `docs/milestones/M11-admin-panel.md`
- `docs/tasks/M11-tasks.md`
- Issues index: `docs/issues/README.md`
