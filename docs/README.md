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
