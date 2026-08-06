# M34 — Keyboard shortcuts

**Phase:** 7 (P3 polish) · **Depends on:** M33

**Goal:** Power-user shortcuts for the most common cash-flow actions:
quick-add, date navigation, save.

## Scope

- Global key handler on the cashflow page (e.g. `n` quick-add focus,
  `[`/`]` shift date by a day, `Ctrl+Enter`/`Cmd+Enter` to submit).
- A small "?" help overlay listing available shortcuts.

## Tasks

See [M34 tasks](../tasks/M34-tasks.md).

## Exit criteria

- [ ] Documented shortcuts work on the cashflow page without conflicting with form inputs
- [ ] Help overlay lists all shortcuts
- [ ] E2E coverage for at least the quick-add and date-shift shortcuts
