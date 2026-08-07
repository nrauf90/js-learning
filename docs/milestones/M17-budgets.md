# M17 — Budgets & spending limits

**Phase:** 5 (P1 features) · **Depends on:** M16

**Goal:** Let users set a monthly spending limit per category and see
progress/over-budget status.

## Scope

- `budgets` table: `user_id`, `category_id`, `month`, `limit_amount`.
- `BudgetController`: CRUD for the current user's budgets.
- Frontend: set/edit limits per category; progress bar + over-budget
  indicator on the dashboard and cashflow pages.

## Tasks

See [M17 tasks](../tasks/M17-tasks.md).

## Exit criteria

- [ ] A user can set a monthly limit per expense category
- [ ] Dashboard/cashflow show spend vs. limit with a clear over-budget state
- [ ] Budgets are scoped per-user (no cross-user leakage)
- [ ] PHPUnit + E2E coverage
