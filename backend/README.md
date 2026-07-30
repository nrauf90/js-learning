# Cash Flow API (Laravel)

JSON API backend for the daily cash-flow app. The vanilla frontend in the repo root calls these endpoints.

## Requirements

- PHP 8.2+
- Composer
- MySQL (XAMPP default: `127.0.0.1:3306`, user `root`, empty password)

## Setup

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
```

Create the MySQL database:

```sql
CREATE DATABASE cashflow_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Run migrations:

```bash
php artisan migrate
```

## Environment

Key variables in `.env`:

| Variable | Example | Purpose |
|----------|---------|---------|
| `APP_URL` | `http://localhost:8000` | Laravel base URL |
| `DB_CONNECTION` | `mysql` | Database driver |
| `DB_DATABASE` | `cashflow_app` | Database name |
| `FRONTEND_URL` | `http://localhost:3000` | SPA origin (OAuth redirects) |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:3000` | Allowed CORS origins |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:3000,127.0.0.1:3000` | Optional (API uses bearer tokens) |
| `GOOGLE_CLIENT_ID` | (from Google Cloud) | Google OAuth client ID |
| `GOOGLE_CLIENT_SECRET` | (from Google Cloud) | Google OAuth secret |
| `GOOGLE_REDIRECT_URI` | `http://localhost:8000/api/auth/google/callback` | Must match Google Console |

## Auth API

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| POST | `/api/register` | No | Returns `{ user, token }` |
| POST | `/api/login` | No | Returns `{ user, token }` |
| POST | `/api/logout` | Bearer | Revokes current token |
| GET | `/api/user` | Bearer | Current user |
| GET | `/api/auth/google/redirect` | No | Starts Google OAuth |
| GET | `/api/auth/google/callback` | No | Redirects to frontend with `?token=` |

```bash
cd backend && php artisan test --filter=AuthTest
```

## Run

```bash
php artisan serve
```

API base: `http://localhost:8000`

Health check: `GET /api/health` → `{ "status": "ok", "app": "..." }`

From the repo root:

```bash
npm run dev:api
```

## Frontend

Serve the static frontend on port 3000 (`npm start`) and ensure `js/api.js` points at `http://localhost:8000`.

- Login: `http://localhost:3000/login.html`
- Signup: `http://localhost:3000/signup.html`