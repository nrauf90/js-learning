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

Tax calculator stays **client-side and free** (no auth/subscription). Cash-flow + reports require subscription after M6/M7.

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
| [tasks/M1-tasks.md](./tasks/M1-tasks.md) … M8 | Checkboxes + completion logs |
| [../backend/README.md](../backend/README.md) | API setup |

## Current status

- **Branch:** `feature-2`
- **Milestone:** M2 — Authentication (**done**)
- **Next task:** **M3-T1** — Migration: `expense_categories`
- **Last updated:** 2026-07-30
- **Notes:** Email/password auth + Sanctum tokens live. Google OAuth needs `GOOGLE_CLIENT_ID` / `SECRET` in `.env`. Pages: `login.html`, `signup.html`.

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
```

XAMPP MySQL defaults: host `127.0.0.1`, port `3306`, user `root`, password empty. Create DB `cashflow_app`.

## After each task

Follow `.cursor/rules/task-completion.mdc`: mark checkbox, append completion log (modified files + QA notes), update this **Current status** section.

## Milestone order

M1 Foundation → M2 Auth → M3 Cash flow → M4 Reports → M5 Tax nav → M6 Billing → M7 Gate → M8 Receipt stub
