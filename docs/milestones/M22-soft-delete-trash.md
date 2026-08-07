# M22 — Soft delete / trash + restore

**Phase:** 6 (P2 features) · **Depends on:** M21

**Goal:** Accidental deletes are recoverable — cash entries move to a
trash instead of being hard-deleted immediately.

## Scope

- `SoftDeletes` (`deleted_at`) on `cash_entries`.
- Trash list + restore endpoint; permanent-delete endpoint for the trash
  view; auto-purge after a retention window (e.g. 30 days) via scheduler.
- Frontend: trash view with restore action.

## Tasks

See [M22 tasks](../tasks/M22-tasks.md).

## Exit criteria

- [ ] Deleting an entry moves it to trash instead of removing it immediately
- [ ] User can view trash and restore an entry
- [ ] Trashed entries are excluded from reports/dashboard totals
- [ ] PHPUnit + E2E coverage
