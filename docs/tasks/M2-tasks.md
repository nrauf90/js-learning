# M2 — Authentication tasks

Depends on M1 complete.

- [x] **M2-T1** Migration: add `google_id`, `avatar` to `users`
- [x] **M2-T2** `POST /api/register` — name, email, password validation
- [x] **M2-T3** `POST /api/login` — returns user + Sanctum token (or cookie session)
- [x] **M2-T4** `POST /api/logout` — revoke token / invalidate session
- [x] **M2-T5** `GET /api/user` — current authenticated user
- [x] **M2-T6** Install Laravel Socialite; `GET /api/auth/google/redirect`
- [x] **M2-T7** Google callback route — create/link user, issue auth, redirect to frontend
- [x] **M2-T8** Frontend `login.html` + `signup.html` styled like existing app
- [x] **M2-T9** Frontend `js/auth.js` — wire forms and Google button to API
- [x] **M2-T10** Store auth token in memory/localStorage; attach to `api.js` requests
- [x] **M2-T11** PHPUnit feature tests for register/login/logout

## Completion log

### M2-T1 — done 2026-07-30

**Modified files**
- `backend/database/migrations/2026_07_30_124107_add_google_fields_to_users_table.php` — `google_id` (unique nullable), `avatar`
- `backend/app/Models/User.php` — fillable includes `google_id`, `avatar`

**QA notes**
1. `cd backend && php artisan migrate`
2. Confirm columns on `users` table: `google_id`, `avatar`.

### M2-T2 — done 2026-07-30

**Modified files**
- `backend/app/Http/Controllers/Api/AuthController.php` — `register`
- `backend/routes/api.php` — `POST /api/register` (throttled)

**QA notes**
1. `POST http://localhost:8000/api/register` with JSON `{ "name", "email", "password", "password_confirmation" }` → 201 + `token`.

### M2-T3 — done 2026-07-30

**Modified files**
- `AuthController::login` — Sanctum bearer token response
- `routes/api.php` — `POST /api/login`

**QA notes**
1. Register a user, then `POST /api/login` with email/password → `{ user, token }`.
2. Bad password → 422.

### M2-T4 — done 2026-07-30

**Modified files**
- `AuthController::logout` — deletes current personal access token
- `routes/api.php` — `POST /api/logout` under `auth:sanctum`
- `backend/bootstrap/app.php` — removed stateful Sanctum middleware (bearer-only)

**QA notes**
1. Call logout with `Authorization: Bearer <token>` → `{ "message": "Logged out" }`.
2. Reuse same token on `/api/user` → 401.

### M2-T5 — done 2026-07-30

**Modified files**
- `AuthController::user` — returns current user payload
- `routes/api.php` — `GET /api/user`

**QA notes**
1. With valid token: `GET /api/user` → user JSON.
2. Without token → 401.

### M2-T6 — done 2026-07-30

**Modified files**
- Composer: `laravel/socialite`
- `backend/config/services.php` — `google` + `frontend` config
- `backend/.env.example` — `GOOGLE_CLIENT_*` keys
- `GoogleAuthController::redirect`
- `GET /api/auth/google/redirect`

**QA notes**
1. Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` in `.env`.
2. Google Cloud redirect URI: `http://localhost:8000/api/auth/google/callback`.
3. Visit `/api/auth/google/redirect` → Google consent (needs real credentials).

### M2-T7 — done 2026-07-30

**Modified files**
- `GoogleAuthController::callback` — create/link user, issue token, redirect to `FRONTEND_URL/login.html?token=...`

**QA notes**
1. After Google consent, browser lands on `login.html?token=...` and auth.js stores token.
2. On failure: `login.html?error=google_auth_failed`.

### M2-T8 — done 2026-07-30

**Modified files**
- `login.html`, `signup.html`
- `css/styles.css` — auth form styles

**QA notes**
1. `npm start` → open `http://localhost:3000/login.html` and `signup.html`.
2. Theme toggle works; styles match tax calculator.

### M2-T9 — done 2026-07-30

**Modified files**
- `js/auth.js` — login/signup forms, Google button URL, Google token handoff

**QA notes**
1. Sign up via form → redirected to `index.html` with token in localStorage (`cashflow_auth_token`).
2. Log in with same credentials works.
3. Google button points at `{API_BASE_URL}/api/auth/google/redirect`.

### M2-T10 — done 2026-07-30

**Modified files**
- `js/api.js` — already stores/sends bearer token; added 401 redirect to `login.html`
- `js/auth.js` — `saveSession` / `logout` helpers

**QA notes**
1. After login, DevTools → Application → Local Storage → `cashflow_auth_token` present.
2. Authenticated `apiGet('/api/user')` from console succeeds.

### M2-T11 — done 2026-07-30

**Modified files**
- `backend/tests/Feature/AuthTest.php`

**QA notes**
1. `cd backend && php artisan test --filter=AuthTest` → 6 passed.
