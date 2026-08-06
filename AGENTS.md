# AGENTS.md

## Start here

1. Read [`docs/CONTEXT.md`](docs/CONTEXT.md) — current status and next task.
2. Open the matching `docs/tasks/M*-tasks.md` and do the next unchecked task.
3. After each task, follow `.cursor/rules/task-completion.mdc` (completion log + update CONTEXT).
4. After each **milestone**, run `npm run qa:milestone -- Mx` (offline, no AI tokens). Fix `docs/issues/`.

## Product overview

Daily cash-flow expense tracker + **free** Pakistan FBR tax calculator.

**Architecture:**
- **Frontend**: vanilla HTML/CSS/JS (repo root) — port 3000
- **Backend**: Laravel 12 JSON API in `backend/` (MySQL + Sanctum) — port 8000
- **Database**: MySQL (`cashflow_app`) via XAMPP
- **Testing**: Node built-in test runner (unit) + Playwright (E2E)
- **Docs**: [`docs/README.md`](docs/README.md) milestones M1–M11 (all complete)

**Key principle:** Tax calculator is **client-side and always free**. Cash-flow tracking requires auth + subscription (7-day free trial).

## Project structure

```
js-learning/
├── index.html              # Landing page (hero, about, quick tax widget, GSAP animations)
├── calculator.html         # Full FBR tax calculator (free, no auth)
├── login.html, signup.html # Auth pages (email/password + Google OAuth)
├── dashboard.html          # Cash-flow dashboard (Chart.js visualizations)
├── cashflow.html           # Expense entry and listing
├── reports.html            # Reports with PDF export (jsPDF)
├── billing.html            # Subscription management (JazzCash/EasyPaisa)
├── profile.html            # User profile (name/password update)
├── privacy.html, terms.html # Legal pages
├── css/
│   └── styles.css          # All styles (responsive, dark theme support)
├── js/
│   ├── tax-slabs.js        # FBR slab data (New Tax FY25-27, Old Tax FY20-25)
│   ├── tax-calculator.js   # Tax calculation engine (exported pure functions)
│   ├── app.js              # Tax calculator UI logic
│   ├── deductions.js       # Deductions modal logic
│   ├── share-export.js     # Share link + print logic
│   ├── number-format.js    # Currency/number formatting utilities
│   ├── api.js              # Laravel API client (fetch wrapper, bearer tokens)
│   ├── auth.js             # Login/signup/logout logic
│   ├── nav.js              # Navigation bar (auth state, active page)
│   ├── shell.js            # Sidebar app shell for logged-in pages
│   ├── dashboard.js        # Dashboard Chart.js logic
│   ├── cashflow.js         # Cash-flow CRUD
│   ├── reports.js          # Reports + PDF export
│   ├── billing.js          # Subscription checkout + status
│   ├── profile.js          # Profile update logic
│   ├── landing.js          # Landing page animations (GSAP + Lenis)
│   ├── legal.js            # Legal page logic
│   └── motion.js           # Shared animation utilities
├── tests/
│   ├── tax-calculator.test.js   # Unit tests (tax engine)
│   ├── deductions.test.js
│   ├── number-format.test.js
│   └── share-export.test.js
├── e2e/
│   ├── playwright.config.js
│   ├── tests/               # Playwright E2E tests (M1–M11 milestones)
│   ├── helpers/             # Test helpers
│   └── reporters/           # Custom reporters
├── scripts/
│   ├── qa-milestone.mjs     # Offline QA harness runner
│   └── write-bug.mjs        # Bug report generator
├── docs/
│   ├── CONTEXT.md           # Agent handoff (current status, stack, workflow)
│   ├── README.md            # Milestone index
│   ├── milestones/          # Goals + exit criteria per milestone
│   ├── tasks/               # Task checklists with completion logs
│   └── issues/              # QA bug reports (BUG-xxx.md)
├── backend/                 # Laravel 12 API (see backend/README.md)
│   ├── app/
│   │   ├── Http/Controllers/  # API controllers (Auth, Billing, etc.)
│   │   ├── Models/            # User, Subscription, Payment models
│   │   ├── Services/          # BillingService, JazzCashService, EasyPaisaService
│   │   └── Policies/          # Authorization policies
│   ├── config/
│   │   └── billing.php        # Billing config (trial days, pricing)
│   ├── database/migrations/   # DB schema
│   ├── routes/api.php         # API routes
│   ├── tests/                 # Laravel PHPUnit tests
│   └── README.md              # Backend setup + API docs
├── .cursor/rules/
│   ├── task-completion.mdc    # Task completion logging rule
│   └── milestone-qa.mdc       # Milestone QA gate rule
├── package.json               # Node scripts (start, test, qa:milestone)
└── README.md                  # Public readme (features, quickstart)
```

## Frontend

### Runtime & commands

```bash
npm install           # Install dependencies (Playwright)
npm start             # Serve on http://localhost:3000
npm test              # Run unit tests (Node test runner)
npm run lint          # Syntax-check all JS files
npm run qa:milestone -- M11  # Run E2E tests through milestone M11
```

### Pages

| Page | Path | Auth | Subscription | Purpose |
|------|------|------|-------------|---------|
| Landing | `index.html` | No | No | Hero, about, contact |
| Login | `login.html` | No | No | Email/password + Google OAuth |
| Signup | `signup.html` | No | No | Create account |
| Sell (till) | `pos.html` | Yes | Yes (trial OK) | Scan/search, ticket, payment, receipt |
| Products | `products.html` | Yes | Yes (trial OK) | Catalog, pricing, stock, categories |
| Dashboard | `dashboard.html` | Yes | Yes (trial OK) | Cash-flow overview + Chart.js charts |
| Cash flow | `cashflow.html` | Yes | Yes (trial OK) | Add/list entries (POS sales land here) |
| Reports | `reports.html` | Yes | Yes (trial OK) | View/export PDF reports |
| Billing | `billing.html` | Yes | No | Manage subscription (Paddle) |
| Profile | `profile.html` | Yes | No | Update name/password |
| Legal | `privacy.html`, `terms.html` | No | No | Legal pages |

### JavaScript modules

| File | Exports | Purpose |
|------|---------|---------|
| `tax-slabs.js` | `TAX_SLABS` | FBR slab data (New/Old tax regimes) |
| `tax-calculator.js` | `calculateTax`, `calculateAllYears`, etc. | Pure tax calculation functions |
| `app.js` | (none, DOM) | Tax calculator UI (sliders, table updates) |
| `deductions.js` | (none, DOM) | Deductions modal logic |
| `share-export.js` | (none, DOM) | Share link + print logic |
| `number-format.js` | `formatCurrency`, `formatNumber`, etc. | Formatting utilities |
| `api.js` | `API_BASE_URL`, `apiFetch`, `apiGet`, etc. | Laravel API client |
| `auth.js` | (none, DOM) | Login/signup/logout forms |
| `nav.js` | `initNav`, `updateAuthState` | Navigation bar logic |
| `shell.js` | (none, DOM) | Sidebar app shell (logged-in pages) |
| `dashboard.js` | (none, DOM) | Dashboard Chart.js charts |
| `cashflow.js` | (none, DOM) | Cash-flow CRUD forms |
| `reports.js` | (none, DOM) | Reports + jsPDF export |
| `billing.js` | (none, DOM) | Subscription checkout + status |
| `profile.js` | (none, DOM) | Profile update forms |
| `landing.js` | (none, DOM) | GSAP + Lenis scroll animations |
| `legal.js` | (none, DOM) | Legal page logic |
| `motion.js` | Animation utilities | Shared GSAP helpers |

## Backend

See [`backend/README.md`](backend/README.md) for full API documentation.

### Setup

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve   # http://localhost:8000
```

**Database:** Create MySQL database `cashflow_app` (XAMPP defaults: host `127.0.0.1`, user `root`, empty password).

### Key API endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| POST | `/api/register` | No | Create account → `{ user, token }` |
| POST | `/api/login` | No | Login → `{ user, token }` |
| POST | `/api/logout` | Bearer | Revoke token |
| GET | `/api/user` | Bearer | Get current user |
| PUT | `/api/user/profile` | Bearer | Update name |
| PUT | `/api/user/password` | Bearer | Change password |
| GET | `/api/auth/google/redirect` | No | Start Google OAuth |
| GET | `/api/auth/google/callback` | No | OAuth callback → frontend redirect with token |
| GET | `/api/billing/plans` | No | Get plan prices |
| GET | `/api/billing/subscription` | Bearer | Current subscription + trial status |
| POST | `/api/billing/checkout` | Bearer | Create payment → gateway form |
| POST | `/api/billing/sandbox/complete/{payment}` | Bearer | Sandbox payment completion |
| POST | `/api/billing/jazzcash/ipn` | No | JazzCash IPN callback |
| POST | `/api/billing/easypaisa/ipn` | No | EasyPaisa IPN callback |

## Documentation

| File | Purpose |
|------|---------|
| [`docs/CONTEXT.md`](docs/CONTEXT.md) | **Agent handoff** — read this first every session |
| [`docs/README.md`](docs/README.md) | Milestone index (M1–M11) |
| [`docs/milestones/*.md`](docs/milestones/) | Milestone goals + exit criteria |
| [`docs/tasks/*.md`](docs/tasks/) | Task checklists with completion logs |
| [`docs/issues/`](docs/issues/) | QA bug reports from `npm run qa:milestone` |
| [`.cursor/rules/task-completion.mdc`](.cursor/rules/task-completion.mdc) | Task logging rule (always apply) |
| [`.cursor/rules/milestone-qa.mdc`](.cursor/rules/milestone-qa.mdc) | Milestone QA gate rule |

## Workflow guidelines

### Before starting work

1. **Always read [`docs/CONTEXT.md`](docs/CONTEXT.md) first** — current status, next task, stack decisions.
2. Check the current milestone's `docs/tasks/M*-tasks.md` for next unchecked task.
3. Understand the scope — don't duplicate existing features.

### During work

- **Don't create new files unless necessary** — edit existing code.
- **Never generate documentation unless explicitly requested** (no extra .md files).
- Follow existing patterns (vanilla JS, no frameworks).
- Keep tax calculator logic **client-side and free** (no auth/API).
- Cash-flow features **require auth + active subscription** (or free trial).

### After completing a task

Follow `.cursor/rules/task-completion.mdc`:

1. Mark checkbox `[x]` in `docs/tasks/M*-tasks.md`.
2. Append completion log with modified files + QA notes.
3. Update `docs/CONTEXT.md` → **Current status** (next task, notes).

### After completing a milestone

Follow `.cursor/rules/milestone-qa.mdc`:

1. Run `npm run qa:milestone -- Mx` (offline Playwright tests).
2. If failures: create `docs/issues/Mx/BUG-xxx.md`, fix, re-run.
3. When green: update `docs/CONTEXT.md` → **QA: passed for M1–Mx**.

## Testing

### Unit tests

```bash
npm test   # Node test runner (tests/*.test.js)
```

Tax calculation logic, formatting, deductions, share/export.

### E2E tests

```bash
npm run qa:milestone -- M5   # Run tests through milestone M5
```

Playwright tests verify:
- Tax calculator UI (no auth)
- Auth flows (email/password + Google OAuth stub)
- Cash-flow CRUD (with subscription gating)
- Billing checkout (sandbox mode)
- Reports PDF export
- Profile updates

### Backend tests

```bash
cd backend
php artisan test
```

Laravel PHPUnit tests for API endpoints.

## Key architecture decisions (locked)

1. **Keep vanilla frontend** — no framework refactors, Laravel is API only (no Blade UI). The M12 Blade exception (`resources/views/billing/gateway-redirect.blade.php`) is **gone**: Paddle hosts its own checkout, so there is nothing to server-render and no merchant credentials to keep out of JSON.
2. **Auth: Sanctum bearer tokens** — simpler cross-origin than cookies for separate ports.
3. **POS + cash flow require a subscription** — 7-day free trial, then the Paddle plans below. (Superseded M23: the free tax calculator was removed from the product.)
4. **Product money is PKR; subscription money is USD.** Shop prices, sales and cash entries are PKR — that is the seller's own currency. The subscription is billed by Paddle in USD because Paddle does not support PKR. Never format the two with the same helper.
5. **Frontend `API_BASE_URL` default:** `http://localhost:8000`
6. **Billing provider: Paddle** (merchant of record). Replaced JazzCash + EasyPaisa in M23. Webhooks are the only thing allowed to move subscription state; the post-checkout browser redirect is cosmetic.
7. **Offline QA harness** — milestone gates use Playwright, not AI agents.
8. **Stock only moves through an audited path** — sales, refunds, or the adjust endpoint. `PUT /api/products/{id}` deliberately ignores `stock_quantity`, so every change leaves a `stock_movements` row.

## Current status

**Milestones M1–M13 complete.** M14 in progress; M15–M34 scaffolded (app-wide improvement review backlog) but not started. See [`docs/CONTEXT.md`](docs/CONTEXT.md) for detailed status.

- **Branch:** `feature-2` (base: `main`)
- **QA:** Passed M1–M13 (66 Playwright tests; 96 frontend unit tests; 75 backend PHPUnit tests)
- **Next:** M14 — performance & code-quality pass (see `docs/tasks/M14-tasks.md`)
- **Note:** M11 (admin panel) was built outside this agent workflow and reviewed/fixed in a follow-up pass — see `docs/issues/M11/` for the bugs found (mass-assignment, missing `is_admin` in API payloads, wrong endpoints/field names, stored XSS).
- **Note:** M12 closed the security findings from a full app-wide review (payment IPN signature bypass, Google OAuth token-in-URL, JazzCash password exposure, no token expiration, `is_admin` mass-assignment, admin payload leak, missing throttling, `billing.sandbox` default, missing input bounds) — see `docs/milestones/M12-security-hardening.md`.
- **Note:** M13 fixed the dark-then-light flash on load — `css/styles.css` now defaults to light theme, and all pages share one `js/theme.js` module instead of 15 duplicated copies — see `docs/milestones/M13-theme-default-fix.md`.

## Troubleshooting

### Frontend not loading

- Check `npm start` is running on port 3000.
- Check `js/api.js` → `API_BASE_URL` matches backend.

### API errors

- Check `cd backend && php artisan serve` is running on port 8000.
- Check `.env` database credentials match XAMPP MySQL.
- Check `php artisan migrate` has run.

### Auth not working

- Check bearer token in `localStorage.getItem('auth_token')`.
- Check `Authorization: Bearer {token}` header in network tab.
- Check Sanctum config in `backend/config/sanctum.php`.

### Subscription gating issues

- Check user has active subscription or is within 7-day trial.
- Check `GET /api/billing/subscription` returns `has_access: true`.
- Sandbox mode: use `POST /api/billing/sandbox/complete/{paymentId}` to complete payments.

### QA tests failing

- Check both frontend (port 3000) and backend (port 8000) are running.
- Check database is seeded (`php artisan migrate:fresh --seed`).
- Check `e2e/playwright.config.js` → `baseURL` matches frontend URL.
- Run `npm run qa:milestone -- M1` to test just M1 (incremental debugging).
