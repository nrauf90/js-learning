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
| [tasks/M1-tasks.md](./tasks/M1-tasks.md) … M11 | Checkboxes + completion logs |
| [issues/](./issues/) | Offline QA bug reports (`BUG-xxx.md`) |
| [../backend/README.md](../backend/README.md) | API setup |
| [../e2e/README.md](../e2e/README.md) | Playwright QA harness |

## Current status

- **Branch:** `feature-2`
- **Milestone:** M12 — POS offline till (**done**)
- **Next task:** —
- **Last updated:** 2026-08-06
- **QA:** passed for M1–M11 (`npm run qa:milestone -- M11`, 63 tests; `cd backend && php artisan test`, 76 tests incl. POS)
- **Notes:** Logged-in pages use a sidebar app shell (`js/shell.js`); dashboard has Chart.js line/bar charts; profile page updates name/password; reports export to PDF (jsPDF); landing uses GSAP + Lenis scroll animations. M11 added an admin panel (`admin*.html`, `AdminController`, `is_admin` on users) — it was built outside this workflow and had several bugs (see `docs/issues/M11/`) that were reviewed and fixed: `is_admin` mass-assignment, the `/api/user` payload missing `is_admin` (sidebar link disappeared), wrong endpoint/field names on the Cash Entries admin page, a `gateway`/`provider` column mismatch on Payments, `e2e/playwright.config.js` never actually running the M11 suite, and stored-XSS/broken-onclick issues in the admin tables.

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

M1 Foundation → M2 Auth → M3 Cash flow → M4 Reports → M5 Tax nav → M6 Billing → M7 Gate → M8 Receipt stub → M9 Free trial → M10 Premium UI → M11 Admin panel

M4 complete. M5 complete. M6 complete. M7 complete. M8 complete. M9 complete. M10 complete. M11 complete. M12 complete (POS offline till, sync schema, line-level refunds, admin POS screens).
