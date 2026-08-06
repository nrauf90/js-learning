# M28 — Bulk edit/delete

**Phase:** 7 (P3 polish) · **Depends on:** M27

**Goal:** Let users select multiple entries at once and re-categorize or
delete them together, instead of one-at-a-time.

## Scope

- Backend bulk-update and bulk-delete endpoints, ownership-checked per
  entry ID in the batch.
- Frontend: multi-select checkboxes on the M16 history list + a bulk
  action toolbar.

## Tasks

See [M28 tasks](../tasks/M28-tasks.md).

## Exit criteria

- [ ] User can select multiple entries and delete them in one action
- [ ] User can bulk re-assign category on selected entries
- [ ] A user can never bulk-affect another user's entries
- [ ] PHPUnit + E2E coverage
