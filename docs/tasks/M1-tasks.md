# M1 — Foundation tasks

Work top to bottom. Mark done as you complete each task.

- [x] **M1-T1** Create Laravel project in `backend/` via Composer
- [x] **M1-T2** Configure `.env` for MySQL (document XAMPP defaults in `.env.example`)
- [x] **M1-T3** Install and configure Laravel Sanctum for API auth
- [x] **M1-T4** Configure CORS for `http://localhost:3000` (and production frontend URL placeholder)
- [x] **M1-T5** Add `GET /api/health` route returning `{ "status": "ok", "app": "..." }`
- [x] **M1-T6** Create `js/api.js` — base URL, credentials, JSON helpers, error handling
- [x] **M1-T7** Add `backend/README.md` with setup and serve instructions
- [x] **M1-T8** Update root `package.json` scripts (optional `dev:api` helper)
- [x] **M1-T9** Verify health check callable from browser console on frontend origin

## Notes

- PHP 8.2 + Composer available via XAMPP on this machine.
- MySQL: use XAMPP MySQL (`127.0.0.1:3306`, user `root`, empty password) unless `.env` differs.
- Copy `backend/.env.example` → `backend/.env`, run `php artisan key:generate`, create DB `cashflow_app`, then `php artisan migrate`.

## Completion log

### M1-T1 — done 2026-07-25

**Modified files**
- `backend/` — Laravel 12.12.2 project scaffolded via Composer

**QA notes**
1. `cd backend && php artisan --version` should print Laravel 12.x.

### M1-T2 — done 2026-07-30

**Modified files**
- `backend/.env.example` — MySQL XAMPP defaults (`cashflow_app`, root/empty), `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `SANCTUM_STATEFUL_DOMAINS`

**QA notes**
1. Open `backend/.env.example` and confirm `DB_CONNECTION=mysql`, `DB_DATABASE=cashflow_app`.
2. Locally: copy to `.env`, create MySQL DB, run `php artisan migrate` (agent did not rewrite your local `.env`).

### M1-T3 — done 2026-07-30

**Modified files**
- `backend/app/Models/User.php` — added `HasApiTokens`
- `backend/bootstrap/app.php` — registered `api` routes + Sanctum stateful middleware
- `backend/config/sanctum.php` — published earlier
- Sanctum package `laravel/sanctum` v4.3.3

**QA notes**
1. `php artisan route:list --path=api` lists API routes.
2. User model uses `Laravel\Sanctum\HasApiTokens`.

### M1-T4 — done 2026-07-30

**Modified files**
- `backend/config/cors.php` — origins from `CORS_ALLOWED_ORIGINS`, `supports_credentials=true`

**QA notes**
1. `curl -D - http://127.0.0.1:8000/api/health -H "Origin: http://localhost:3000"` should include `Access-Control-Allow-Origin: http://localhost:3000` and `Access-Control-Allow-Credentials: true`.

### M1-T5 — done 2026-07-30

**Modified files**
- `backend/routes/api.php` — `GET /api/health` JSON handler

**QA notes**
1. With `php artisan serve --port=8000`, open `http://127.0.0.1:8000/api/health`.
2. Expect JSON: `{ "status": "ok", "app": "..." }`.

### M1-T6 — done 2026-07-30

**Modified files**
- `js/api.js` — `API_BASE_URL`, token helpers, `apiGet/Post/Put/Delete`, `checkHealth`

**QA notes**
1. `node --check js/api.js`
2. In browser console on `http://localhost:3000` after importing: `import { checkHealth } from './js/api.js'` then `await checkHealth()`.

### M1-T7 — done 2026-07-25

**Modified files**
- `backend/README.md` — setup, env table, serve instructions

**QA notes**
1. Read `backend/README.md` and follow setup steps on a clean machine.

### M1-T8 — done 2026-07-30

**Modified files**
- `package.json` — `dev:api` → `php backend/artisan serve --port=8000`; lint includes `js/api.js`
- `.gitignore` — ignore `backend/.env`, `vendor/`, sqlite files

**QA notes**
1. From repo root: `npm run lint` passes.
2. `npm run dev:api` starts Laravel on port 8000.

### M1-T9 — done 2026-07-30

**Modified files**
- (verification only; no new source files)

**QA notes**
1. Start API: `npm run dev:api` (or `cd backend && php artisan serve --port=8000`).
2. `curl http://127.0.0.1:8000/api/health` → `status: ok`.
3. Confirmed CORS allows `Origin: http://localhost:3000`.
4. Optional browser: serve frontend `npm start`, then:
   ```js
   fetch('http://localhost:8000/api/health', { credentials: 'include' })
     .then(r => r.json()).then(console.log)
   ```
