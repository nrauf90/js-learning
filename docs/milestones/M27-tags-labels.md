# M27 — Tags / labels beyond category

**Phase:** 7 (P3 polish) · **Depends on:** M26

**Goal:** Let users attach free-form tags to entries (e.g. "work trip",
"Eid") for filtering that a single category can't express.

## Scope

- `tags` + `cash_entry_tag` pivot table, scoped per-user.
- API to create tags and attach/detach them on an entry.
- Frontend: tag input on the entry form; filter entries by tag (builds on
  M16's filter UI).

## Tasks

See [M27 tasks](../tasks/M27-tasks.md).

## Exit criteria

- [ ] User can tag an entry with one or more free-form labels
- [ ] Entries list can be filtered by tag
- [ ] Tags are scoped per-user
- [ ] PHPUnit + E2E coverage
