# M31 — Entry edit history / audit trail

**Phase:** 7 (P3 polish) · **Depends on:** M30

**Goal:** Track what changed on a cash entry over time, beyond the plain
`updated_at` timestamp.

## Scope

- `cash_entry_revisions` table capturing before/after field values on
  each update, with the acting user and timestamp.
- Recorded automatically on `CashEntryController::update`.
- Frontend: a small "history" view per entry.

## Tasks

See [M31 tasks](../tasks/M31-tasks.md).

## Exit criteria

- [ ] Every update to an entry creates a revision record
- [ ] User can view the change history for one of their entries
- [ ] PHPUnit coverage for revision creation
