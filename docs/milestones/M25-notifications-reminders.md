# M25 — Notifications & reminders

**Phase:** 6 (P2 features) · **Depends on:** M24

**Goal:** Improve retention/habit-formation with a daily log reminder,
budget-exceeded alerts, and recurring-transaction-due alerts.

## Scope

- Notification preferences (per user: which alerts are enabled).
- Laravel notification classes for: daily reminder, budget exceeded
  (depends on M17), recurring due (depends on M19) — delivered via email
  to start (in-app banner optional).
- Scheduled command(s) to dispatch the daily/periodic checks.
- Frontend: notification preferences UI (e.g. on the profile page).

## Tasks

See [M25 tasks](../tasks/M25-tasks.md).

## Exit criteria

- [ ] User can opt in/out of each reminder type
- [ ] Budget-exceeded alert fires when a category budget is crossed
- [ ] Daily reminder and recurring-due alert are dispatched on schedule
- [ ] PHPUnit coverage for the notification triggers
