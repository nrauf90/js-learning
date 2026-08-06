# M20 — Multiple accounts/wallets + transfers

**Phase:** 5 (P1 features) · **Depends on:** M19

**Goal:** Let a user track balances across multiple wallets (cash, bank,
card) and move money between them, instead of every entry being
wallet-agnostic.

## Scope

- `accounts` table: `user_id`, `name`, `type`, running balance.
- `account_id` (nullable, backward compatible) added to `cash_entries`.
- `AccountController`: CRUD + balance for a user's accounts.
- Transfer support: a dedicated transfer type (paired debit/credit entries
  or a `transfers` table) that moves balance between two accounts without
  double-counting in income/expense reports.
- Frontend: account selector on the entry form, an accounts management
  page, and balance widgets.

## Tasks

See [M20 tasks](../tasks/M20-tasks.md).

## Exit criteria

- [ ] User can create accounts and see a running balance per account
- [ ] Entries can be attributed to an account (optional, backward compatible)
- [ ] Transfers move balance between accounts without skewing income/expense reports
- [ ] PHPUnit + E2E coverage
