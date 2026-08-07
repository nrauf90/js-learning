# M26 — Savings goals

**Phase:** 6 (P2 features) · **Depends on:** M25

**Goal:** Let users set a savings goal (e.g. "Emergency fund", "Hajj",
"New phone") with a target amount/date and track progress.

## Scope

- `goals` table: `user_id`, `name`, `target_amount`, `target_date`,
  `current_amount` (or computed from linked income entries/contributions).
- `GoalController`: CRUD + contribute-to-goal endpoint.
- Frontend: goals UI + a dashboard progress widget.

## Tasks

See [M26 tasks](../tasks/M26-tasks.md).

## Exit criteria

- [ ] User can create a goal with a target amount/date
- [ ] User can log a contribution and see updated progress
- [ ] Dashboard shows active goal progress
- [ ] PHPUnit + E2E coverage
