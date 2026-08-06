# Project context (agent handoff)

Read this file first in any new session. Then open the current milestone task file.

## What this product is

**Point of sale + daily cash flow** for small shops. Sell at the till, keep stock accurate, and have every sale post into the same ledger the dashboard and reports read.

| Layer | Stack | Location |
|-------|--------|----------|
| Frontend | Vanilla HTML/CSS/JS | repo root (`index.html`, `css/`, `js/`) |
| Backend | Laravel 12 JSON API + Sanctum | `backend/` |
| DB | MySQL (XAMPP defaults) | `cashflow_app` |
| Billing | Paddle (merchant of record) | USD — see `config/billing.php` |

**Two currencies, deliberately.** Shop money — product prices, sales, cash entries — is **PKR**. Subscription money is **USD**, because Paddle cannot charge PKR. `js/pos.js` / `js/products.js` format PKR; `js/billing.js` and the admin payment views format the subscription currency.

POS + cash flow + reports: **7-day free trial** after signup, then a paid subscription (M6/M7/M9). The free FBR tax calculator was **removed** in M23.

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
| [tasks/M1-tasks.md](./tasks/M1-tasks.md) … M34 | Checkboxes + completion logs |
| [issues/](./issues/) | Offline QA bug reports (`BUG-xxx.md`) |
| [../backend/README.md](../backend/README.md) | API setup |
| [../e2e/README.md](../e2e/README.md) | Playwright QA harness |

## Current status

- **Branch:** `feature-2`
- **Milestone:** M13 — Theme default & FOUC fix (**done**); M14–M34 scaffolded, M14 **in progress**
- **Next task:** M14-T1 (`ReportAggregator`: SQL-level `SUM`/`GROUP BY` aggregation instead of PHP loops)
- **Last updated:** 2026-07-31
- **QA:** passed for M1–M13 (`npm run qa:milestone -- M13`, 66 E2E tests; `npm test`, 96 unit tests; `cd backend && php artisan test`, 75 tests)
- **Notes:** Logged-in pages use a sidebar app shell (`js/shell.js`); dashboard has Chart.js line/bar charts; profile page updates name/password; reports export to PDF (jsPDF); landing uses GSAP + Lenis scroll animations. M11 added an admin panel (`admin*.html`, `AdminController`, `is_admin` on users) — it was built outside this workflow and had several bugs (see `docs/issues/M11/`) that were reviewed and fixed: `is_admin` mass-assignment, the `/api/user` payload missing `is_admin` (sidebar link disappeared), wrong endpoint/field names on the Cash Entries admin page, a `gateway`/`provider` column mismatch on Payments, `e2e/playwright.config.js` never actually running the M11 suite, and stored-XSS/broken-onclick issues in the admin tables. A follow-up fix closed a CSS bug (`BUG-007`) where admin edit/delete modals were always visible and uncloseable. M12 closed the security findings from the app-wide review: payment IPN no longer bypasses signature checks in sandbox mode, Google OAuth no longer puts the bearer token in the URL (one-time code exchange via `POST /api/auth/google/exchange`), live JazzCash/EasyPaisa checkouts no longer return merchant credentials in JSON (server-rendered signed redirect page instead), Sanctum tokens now expire (30 days) and password change revokes other sessions, `is_admin` is no longer mass-assignable, admin payment responses no longer leak the raw gateway `payload`, `PUT /user/password` and the Google OAuth routes are throttled, `billing.sandbox` requires an explicit `BILLING_SANDBOX=true` env flag, and entry `amount`/admin `per_page` now have max bounds. M13 fixed the dark-then-light flash on page load: `css/styles.css` now paints light theme by default (unattributed `:root`), with `:root[data-theme='dark']` as the explicit override; all 15 pages that duplicated `initTheme`/`applyTheme` now import a single shared `js/theme.js`, whose fallback logic defaults to light unless the OS explicitly prefers dark; a new `e2e/tests/m13-theme.spec.js` verifies the CSS-only first paint, toggle persistence, and that an explicit choice beats the OS preference.
- **M23 (2026-08-06) changed the product's direction.** Two things happened that supersede parts of the backlog below. (1) **Billing moved to Paddle.** JazzCash and EasyPaisa were deleted — both gateway classes, `GatewayResolver`, the `PaymentGateway` contract, `BillingCallbackController`, the IPN/return routes and the `gateway-redirect` Blade page. In their place: `PaddleClient`, `PaddleGateway`, `PaddleWebhookController`, a `webhook_events` dedup table, external ids on `subscriptions`/`payments`, `users.paddle_customer_id`, and a customer-portal + cancel endpoint. Subscription periods are now **set from provider webhooks**, not extended additively per payment. Pricing moved from PKR to USD. (2) **The tax calculator was removed** and a **point of sale** was added: `products`, `product_categories`, `sales`, `sale_items` and `stock_movements` tables, a transactional `SaleService` that row-locks products to prevent overselling, and `pos.html` / `products.html`. Each sale posts an income `cash_entry` under the `sales` category, so existing dashboard/reports/export code picked it up unchanged.
- **M14–M34** are the remainder of the app-wide improvement review backlog (note: the "keep the tax calculator and billing model as-is" assumption below no longer holds — see M23): performance/code-quality cleanup (in progress), UX/accessibility polish, then a long tail of new cash-flow features (search/filters, budgets, CSV import/export, recurring transactions, accounts/wallets, user categories, soft delete, receipts, report comparisons, notifications, goals, tags, bulk ops, backup export, multi-currency, audit trail, dashboard customization, PWA, keyboard shortcuts). See `docs/README.md` for the full list and `docs/milestones/M14-performance-code-quality.md` onward for each one's scope. Being built in that order, one milestone at a time.

## Architecture decisions (locked)

- Keep vanilla frontend; Laravel is **API only** (no Blade UI rebuild).
- Auth: Sanctum bearer tokens (simpler cross-origin than SPA cookies for separate ports).
- Frontend `API_BASE_URL` default: `http://localhost:8000`
- Frontend serve: `npm start` → port 3000
- Backend serve: `cd backend && php artisan serve` → port 8000
- Paddle webhooks hit `POST /api/billing/paddle/webhook` and are the **only** thing that moves subscription state; the browser's post-checkout redirect back to `billing.html?status=…` is cosmetic.
- POS sales write an income `cash_entry` (category `sales`) so the dashboard, reports and exports need no POS-specific wiring.
- Stock changes only through `SaleService`; every change writes a `stock_movements` row with the running balance.

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

M1 Foundation → M2 Auth → M3 Cash flow → M4 Reports → M5 Tax nav → M6 Billing → M7 Gate → M8 Receipt stub → M9 Free trial → M10 Premium UI → M11 Admin panel → M12 Security → M13 Theme fix → M14 Perf/code-quality → M15 UX polish → M16 Search/filters → M17 Budgets → M18 CSV → M19 Recurring → M20 Accounts → M21 User categories → M22 Soft delete → M23 Receipts → M24 Report comparisons → M25 Notifications → M26 Goals → M27 Tags → M28 Bulk ops → M29 Backup export → M30 Multi-currency → M31 Audit trail → M32 Dashboard customization → M33 PWA → M34 Keyboard shortcuts

M1–M13 complete. M14 in progress. M15–M34 not started (see `docs/README.md` milestone table for links).
