# AGENTS.md

## Start here

1. Read [`docs/CONTEXT.md`](docs/CONTEXT.md) — current status and next task.
2. Open the matching `docs/tasks/M*-tasks.md` and do the next unchecked task.
3. After each task, follow `.cursor/rules/task-completion.mdc` (completion log + update CONTEXT).
4. After each **milestone**, run `npm run qa:milestone -- Mx` (offline, no AI tokens). Fix `docs/issues/`.

## Product

Daily cash-flow expense tracker + **free** Pakistan FBR tax calculator.

- **Frontend**: vanilla HTML/CSS/JS (repo root)
- **Backend**: Laravel 12 JSON API in `backend/` (MySQL + Sanctum)
- **Docs**: [`docs/README.md`](docs/README.md) milestones M1–M8

## Frontend (tax calculator)

- **Runtime**: Node.js + npm
- **Lint**: `npm run lint` — `node --check` on JS files
- **Test**: `npm test` — Node built-in test runner
- **Run**: `npm start` — serve on port 3000

### Structure

- `index.html` — tax calculator UI
- `css/styles.css` — styling
- `js/tax-slabs.js` — FBR slab data
- `js/tax-calculator.js` — calculation engine
- `js/app.js` — tax UI logic
- `js/api.js` — Laravel API client (added in M1)
- `tests/` — unit tests

## Backend

See [`backend/README.md`](backend/README.md).

```bash
cd backend
php artisan serve   # http://localhost:8000
```
