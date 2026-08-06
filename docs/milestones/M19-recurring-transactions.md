# M19 — Recurring / scheduled transactions

**Phase:** 5 (P1 features) · **Depends on:** M18

**Goal:** Let users define recurring entries (salary, rent, subscriptions)
that auto-post on schedule instead of requiring manual daily entry.

## Scope

- `recurring_entries` table: template fields (type, category, amount,
  note) + `frequency` (daily/weekly/monthly) + `next_run_at`.
- `RecurringEntryController`: CRUD for a user's recurring rules.
- Scheduled artisan command (Laravel scheduler) that generates real
  `cash_entries` rows from due rules and advances `next_run_at`.
- Frontend: manage recurring rules (create/pause/edit/delete), with a
  preview of the next run date.

## Tasks

See [M19 tasks](../tasks/M19-tasks.md).

## Exit criteria

- [ ] User can create a recurring rule and see it listed with its next run date
- [ ] The scheduler generates a real cash entry on the due date without manual action
- [ ] Pausing/deleting a rule stops future generation
- [ ] PHPUnit (incl. the generator command) + E2E coverage
