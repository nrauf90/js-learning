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
| PUT | `/api/user/profile` | Bearer | Update name — `{ name }` |
| PUT | `/api/user/password` | Bearer | Change password — `{ current_password, password, password_confirmation }` |
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

## Billing API (JazzCash & EasyPaisa)

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/api/billing/plans` | No | Plan prices (PKR 500/mo, 5400/yr) |
| GET | `/api/billing/subscription` | Bearer | Current subscription + addon flags |
| POST | `/api/billing/checkout` | Bearer | `{ plan, provider }` → payment + gateway form |
| POST | `/api/billing/sandbox/complete/{payment}` | Bearer | Local sandbox only (`BILLING_SANDBOX=true`) |
| POST | `/api/receipts/upload` | Bearer + subscription | Stub — returns **501** “Coming soon” (M8) |
| POST | `/api/billing/jazzcash/ipn` | No | JazzCash IPN callback |
| GET/POST | `/api/billing/jazzcash/return` | No | Redirects to frontend billing page |
| POST | `/api/billing/easypaisa/ipn` | No | EasyPaisa IPN callback |
| GET/POST | `/api/billing/easypaisa/return` | No | Redirects to frontend billing page |

```bash
cd backend && php artisan test --filter=BillingTest
```

### Billing environment variables

Add to `.env` (sandbox works without keys when `BILLING_SANDBOX=true`):

| Variable | Purpose |
|----------|---------|
| `BILLING_SANDBOX` | `true` for local sandbox checkout (default in local) |
| `BILLING_TRIAL_DAYS` | Free trial length for new users (default **7**) |
| `FRONTEND_URL` | Frontend origin — return redirects to `{FRONTEND_URL}/billing.html` |
| `JAZZCASH_MERCHANT_ID` | JazzCash merchant ID |
| `JAZZCASH_PASSWORD` | JazzCash password |
| `JAZZCASH_INTEGRITY_SALT` | JazzCash HMAC salt |
| `JAZZCASH_RETURN_URL` | Optional override (default `{APP_URL}/api/billing/jazzcash/return`) |
| `JAZZCASH_IPN_URL` | Optional override (default `{APP_URL}/api/billing/jazzcash/ipn`) |
| `JAZZCASH_CHECKOUT_URL` | JazzCash form POST URL (sandbox URL by default) |
| `EASYPAISA_STORE_ID` | EasyPaisa store ID |
| `EASYPAISA_HASH_KEY` | EasyPaisa hash key |
| `EASYPAISA_RETURN_URL` | Optional override |
| `EASYPAISA_IPN_URL` | Optional override |
| `EASYPAISA_CHECKOUT_URL` | EasyPaisa initiate URL |

Pricing is configured in `config/billing.php` (monthly **500**, yearly **5400**, receipt add-on **500**).

Receipt images will use the private `receipts` disk (`storage/app/receipts`) when upload is implemented.

## Frontend

Serve the static frontend on port 3000 (`npm start`) and ensure `js/api.js` points at `http://localhost:8000`.

- Login: `http://localhost:3000/login.html`
- Signup: `http://localhost:3000/signup.html`