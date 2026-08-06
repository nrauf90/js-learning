# Project Documentation

Daily cash-flow expense tracker with a **free** Pakistan tax calculator.

- **Frontend**: vanilla HTML/CSS/JS (existing app, extended)
- **Backend**: Laravel JSON API in `backend/` (MySQL)
- **Billing**: JazzCash + EasyPaisa (PKR 500/month, PKR 5,400/year)

## Milestones

| # | Milestone | Phase | Status |
|---|-----------|-------|--------|
| M1 | [Foundation — Laravel API shell](./milestones/M1-foundation.md) | 1 | Done |
| M2 | [Authentication — email + Google](./milestones/M2-authentication.md) | 1 | Done |
| M3 | [Daily cash flow](./milestones/M3-cashflow.md) | 1 | Done |
| M4 | [Reports, landing & legal](./milestones/M4-reports.md) | 1 | Done |
| M5 | [Free tax calculator integration](./milestones/M5-tax-calculator.md) | 1 | Done |
| M6 | [Subscriptions — JazzCash & EasyPaisa](./milestones/M6-billing.md) | 2 | Done |
| M7 | [Subscription gating](./milestones/M7-subscription-gate.md) | 2 | Done |
| M8 | [Receipt addon scaffold](./milestones/M8-receipt-addon.md) | 3 | Done |
| M9 | [7-day free trial](./milestones/M9-free-trial.md) | 2 | Done |
| M10 | [Premium UI, profile & PDF reports](./milestones/M10-premium-ui.md) | 3 | Done |
| M11 | [Admin panel](./milestones/M11-admin-panel.md) | 3 | Done |
| M12 | [Security hardening](./milestones/M12-security-hardening.md) | 4 | Done |
| M13 | [Theme default & FOUC fix](./milestones/M13-theme-default-fix.md) | 4 | Done |
| M14 | [Performance & code-quality pass](./milestones/M14-performance-code-quality.md) | 4 | In progress |
| M15 | [UX & accessibility polish](./milestones/M15-ux-accessibility-polish.md) | 4 | Not started |
| M16 | [Cash-flow search, filters & date-range](./milestones/M16-cashflow-search-filters.md) | 5 | Not started |
| M17 | [Budgets & spending limits](./milestones/M17-budgets.md) | 5 | Not started |
| M18 | [CSV export & import](./milestones/M18-csv-export-import.md) | 5 | Not started |
| M19 | [Recurring / scheduled transactions](./milestones/M19-recurring-transactions.md) | 5 | Not started |
| M20 | [Multiple accounts/wallets + transfers](./milestones/M20-accounts-wallets.md) | 5 | Not started |
| M21 | [User-managed categories](./milestones/M21-user-categories.md) | 6 | Not started |
| M22 | [Soft delete / trash + restore](./milestones/M22-soft-delete-trash.md) | 6 | Not started |
| M23 | [Finish the receipt-upload addon](./milestones/M23-receipt-upload.md) | 6 | Not started |
| M24 | [Month-over-month report comparisons](./milestones/M24-report-comparisons.md) | 6 | Not started |
| M25 | [Notifications & reminders](./milestones/M25-notifications-reminders.md) | 6 | Not started |
| M26 | [Savings goals](./milestones/M26-savings-goals.md) | 6 | Not started |
| M27 | [Tags / labels](./milestones/M27-tags-labels.md) | 7 | Not started |
| M28 | [Bulk edit/delete](./milestones/M28-bulk-operations.md) | 7 | Not started |
| M29 | [Full account backup export](./milestones/M29-full-backup-export.md) | 7 | Not started |
| M30 | [Multi-currency support](./milestones/M30-multi-currency.md) | 7 | Not started |
| M31 | [Entry edit history / audit trail](./milestones/M31-audit-trail.md) | 7 | Not started |
| M32 | [Dashboard customization](./milestones/M32-dashboard-customization.md) | 7 | Not started |
| M33 | [Offline / PWA support](./milestones/M33-offline-pwa.md) | 7 | Not started |
| M34 | [Keyboard shortcuts](./milestones/M34-keyboard-shortcuts.md) | 7 | Not started |

## Agent handoff

- **[`CONTEXT.md`](./CONTEXT.md)** — read first in any new session (status + next task)
- **`.cursor/rules/task-completion.mdc`** — after each task: checkbox, modified files, QA notes
- **`.cursor/rules/milestone-qa.mdc`** — after each milestone: `npm run qa:milestone -- Mx`
- **[`issues/`](./issues/)** — offline Playwright bug reports

## Offline QA

```bash
npm run qa:milestone -- M2   # regression M1+M2; bugs → docs/issues/
```

See [`e2e/README.md`](../e2e/README.md).

## Task index

All tasks live under [`docs/tasks/`](./tasks/). Work them in order within each milestone.

## Local dev (once M1 is done)

```bash
# Frontend (port 3000)
npm start

# Backend (port 8000)
cd backend && php artisan serve
```

Set `API_BASE_URL=http://localhost:8000` in the frontend config (`js/api.js`).
