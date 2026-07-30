# BUG-001 - `is_admin` was not mass-assignable

- **Milestone:** M11
- **Found during:** review of admin panel built externally, before regression
- **Date:** 2026-07-30
- **Status:** closed — added `is_admin` to `User::$fillable` (`backend/app/Models/User.php`)

## How to reproduce (pre-fix)

1. `cd backend && php artisan tinker`
2. `$u = App\Models\User::factory()->create();`
3. `$u->update(['is_admin' => true]); $u->refresh(); $u->is_admin;`

## Expected

`$u->is_admin` is `true` after the update.

## Actual (pre-fix)

`$u->is_admin` stayed `false`. Laravel silently drops attributes that
aren't in `$fillable` during mass assignment (`create()`/`update()`), so:

- `AdminUserSeeder` created the default admin account without actually
  setting `is_admin`.
- `AdminController::userUpdate()` — the "Admin privileges" checkbox in
  `admin-users.html` — returned `200 OK` but never persisted the change.

No exception was thrown in either case, which is why it looked like the
feature "worked" (no errors in the network tab) while quietly not doing
anything.

## Evidence

- Regression test: `backend/tests/Feature/AdminPanelTest.php::test_admin_can_promote_another_user_to_admin`

## Links

- `docs/milestones/M11-admin-panel.md`
- `docs/tasks/M11-tasks.md`
- Issues index: `docs/issues/README.md`
