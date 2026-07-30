# Project context (agent handoff)

Read this file first in any new session. Then open the current milestone task file.

## What this product is

Daily **cash-flow / expense** app with a **free** Pakistan FBR tax calculator.

| Layer | Stack | Location |
|-------|--------|----------|
| Frontend | Vanilla HTML/CSS/JS | repo root (`index.html`, `css/`, `js/`) |
| Backend | Laravel 12 JSON API + Sanctum | `backend/` |
| DB | MySQL (XAMPP defaults) | `cashflow_app` |
| Billing (later) | JazzCash + EasyPaisa | PKR 500/mo, PKR 5400/yr (10% off) |

Tax calculator stays **client-side and free** (no auth/subscription). Cash-flow + reports: **7-day free trial** after signup, then paid subscription (M6/M7/M9).

## Repo layout

```
js-learning/
  index.html, css/, js/, tests/     # existing tax app + upcoming pages
  docs/                            # milestones, tasks, this context file
  backend/                         # Laravel API
  .cursor/rules/                   # agent rules (task completion logging)
```

## Docs map

| File | Purpose |
|------|---------|
| [README.md](./README.md) | Milestone index |
| [CONTEXT.md](./CONTEXT.md) | This handoff file |
| [milestones/](./milestones/) | Goals + exit criteria per milestone |
| [tasks/M1-tasks.md](./tasks/M1-tasks.md) … M10 | Checkboxes + completion logs |
| [issues/](./issues/) | Offline QA bug reports (`BUG-xxx.md`) |
| [../backend/README.md](../backend/README.md) | API setup |
| [../e2e/README.md](../e2e/README.md) | Playwright QA harness |

## Current status

- **Branch:** `feature-2`
- **Milestone:** M10 — Premium UI, profile & PDF reports (**done**)
- **Next task:** —
- **Last updated:** 2026-07-30
- **QA:** passed for M1–M10 (`npm run qa:milestone -- M10`, 45 tests)
- **Notes:** Logged-in pages use a sidebar app shell (`js/shell.js`); dashboard has Chart.js line/bar charts; profile page updates name/password; reports export to PDF (jsPDF); landing uses GSAP + Lenis scroll animations.

## Architecture decisions (locked)

- Keep vanilla frontend; Laravel is **API only** (no Blade UI rebuild).
- Auth: Sanctum bearer tokens (simpler cross-origin than SPA cookies for separate ports).
- Frontend `API_BASE_URL` default: `http://localhost:8000`
- Frontend serve: `npm start` → port 3000
- Backend serve: `cd backend && php artisan serve` → port 8000
- Payment return/IPN URLs hit Laravel; then redirect to frontend.

## Local commands

```bash
# Frontend
npm start
npm test
npm run lint

# Backend
cd backend
composer install
cp .env.example .env   # then set MySQL
php artisan key:generate
php artisan migrate
php artisan serve

# Offline milestone QA (no AI tokens) — regression through Mx
npm run qa:milestone -- M2
```

XAMPP MySQL defaults: host `127.0.0.1`, port `3306`, user `root`, password empty. Create DB `cashflow_app`.

## After each task

Follow `.cursor/rules/task-completion.mdc`: mark checkbox, append completion log (modified files + QA notes), update this **Current status** section.

## After each milestone

Follow `.cursor/rules/milestone-qa.mdc`: run `npm run qa:milestone -- Mx`, fix anything under `docs/issues/Mx/`, mark QA passed here when green.

## Milestone order

M1 Foundation → M2 Auth → M3 Cash flow → M4 Reports → M5 Tax nav → M6 Billing → M7 Gate → M8 Receipt stub → M9 Free trial → M10 Premium UI

M4 complete. M5 complete. M6 complete. M7 complete. M8 complete. M9 complete. M10 complete.
