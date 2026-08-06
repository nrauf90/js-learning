# M21 — User-managed categories

**Phase:** 6 (P2 features) · **Depends on:** M20

**Goal:** Let users create their own expense/income categories instead of
being limited to the fixed, admin-managed seeded list.

## Scope

- Add nullable `user_id` to `expense_categories`; a category is visible to
  a user if it's a system category (`is_system=true`) or their own.
- User-facing category endpoints: create/update/delete for categories the
  user owns (system categories stay admin-only, as today).
- Frontend: category management UI reachable from the cashflow page.

## Tasks

See [M21 tasks](../tasks/M21-tasks.md).

## Exit criteria

- [ ] A user can add/edit/delete their own categories
- [ ] System categories remain admin-only and visible to everyone
- [ ] A user never sees or can modify another user's custom categories
- [ ] PHPUnit + E2E coverage
