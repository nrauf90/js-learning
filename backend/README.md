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

## Billing API (Paddle)

Paddle is the merchant of record: it hosts checkout, charges the card, and handles sales
tax/VAT. **Webhooks are the only thing that moves subscription state** — the browser's
post-checkout redirect is cosmetic and carries nothing we trust.

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/api/billing/plans` | No | Plans + display prices |
| GET | `/api/billing/subscription` | Bearer | Current subscription, trial, addon flags |
| POST | `/api/billing/checkout` | Bearer | `{ plan }` → `redirect_url` to Paddle's hosted checkout |
| POST | `/api/billing/portal` | Bearer | Single-use hosted customer-portal link (never cache it) |
| POST | `/api/billing/cancel` | Bearer | Cancels at period end via Paddle |
| POST | `/api/billing/sandbox/complete/{payment}` | Bearer | Local test mode only (`BILLING_SANDBOX=true`) |
| POST | `/api/billing/paddle/webhook` | Signature | `Paddle-Signature` HMAC over the raw body |
| POST | `/api/receipts/upload` | Bearer + subscription | Stub — returns **501** “Coming soon” (M8) |

```bash
cd backend && php artisan test --filter=BillingTest
```

### Paddle dashboard setup

Checkout will not work until all of these are done:

1. Create the products/prices and copy their IDs into `PADDLE_PRICE_*`.
2. Set a **default payment link** (Checkout → Checkout settings). Without one, Paddle returns a
   transaction with no `checkout.url` and checkout fails with a 503.
3. Set the default success URL to `{FRONTEND_URL}/billing.html?status=success`.
4. Add a notification destination pointing at `POST {APP_URL}/api/billing/paddle/webhook`,
   subscribed to `transaction.completed`, `transaction.payment_failed`, `subscription.*` and
   `adjustment.created`. Copy its `pdl_ntfset_…` secret into `PADDLE_WEBHOOK_SECRET`.
5. Give the API key the `customer_portal_session.write` permission, or portal links come back
   empty.

### Billing environment variables

See `.env.example` for the full annotated list. The ones that matter most:

| Variable | Purpose |
|----------|---------|
| `BILLING_SANDBOX` | `true` skips Paddle entirely for local dev and the E2E harness. Must be set explicitly — it does **not** default on for `APP_ENV=local`, and must never be on for a publicly reachable instance |
| `BILLING_TRIAL_DAYS` | Free trial length for new users (default **7**) |
| `BILLING_CURRENCY` | Subscription currency (**USD** — Paddle does not support PKR) |
| `PADDLE_ENV` | `sandbox` or `production`; picks the API host. Sandbox price IDs are invalid in production |
| `PADDLE_API_KEY` | Server-side API key |
| `PADDLE_WEBHOOK_SECRET` | Notification-setting secret. Verification **fails closed** without it |
| `PADDLE_PRICE_MONTHLY` / `_YEARLY` / `_RECEIPT_ADDON` | Price IDs (`pri_…`) |
| `FRONTEND_URL` | Frontend origin — used for the post-checkout return |

Display prices live in `config/billing.php`; Paddle is the source of truth for what is actually
charged, so the two must be kept in step by hand.

## Point of sale API

All routes require `auth:sanctum` **and** an active subscription/trial.

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/products` | `search`, `category_id`, `active`, `low_stock`, paginated |
| POST | `/api/products` | `stock_quantity` here is opening stock and logs an `initial` movement |
| GET | `/api/products/lookup?code=` | Exact barcode/SKU match for the scanner; 404 if unknown |
| GET/PUT/DELETE | `/api/products/{id}` | PUT **ignores** `stock_quantity` on purpose |
| POST | `/api/products/{id}/stock` | `{ quantity_delta, type: restock\|adjustment, note? }` |
| GET/POST/PUT/DELETE | `/api/product-categories` | Per-seller categories |
| GET | `/api/sales` | `date`, `status`, paginated |
| POST | `/api/sales` | `{ items:[{product_id, quantity}], discount_amount?, payment_method?, amount_tendered?, note? }` |
| GET | `/api/sales/today` | Counter summary for drawer reconciliation |
| GET | `/api/sales/{id}` | Full sale with line items |
| POST | `/api/sales/{id}/refund` | Restores stock, removes the income entry |

Notes:

- **Stock only moves through `SaleService`** (sale, refund, adjust). Every change writes a
  `stock_movements` row carrying the running balance, so a drifted count can be explained.
- Sales run in a transaction with the products `lockForUpdate()` — two cashiers selling the last
  unit at once is the normal POS race, and without the lock both writes succeed.
- Every sale posts an income `cash_entry` under the `sales` category, which is how the dashboard,
  reports and exports pick up POS revenue with no extra wiring. Seed it with
  `php artisan db:seed --class=ExpenseCategorySeeder`.
- Sale line items snapshot the product name and prices, so receipts survive a rename or delete.

```bash
cd backend && php artisan test --filter=PosTest
```

Receipt images will use the private `receipts` disk (`storage/app/receipts`) when upload is implemented.

## Frontend

Serve the static frontend on port 3000 (`npm start`) and ensure `js/api.js` points at `http://localhost:8000`.

- Login: `http://localhost:3000/login.html`
- Signup: `http://localhost:3000/signup.html`