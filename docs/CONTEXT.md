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
| [tasks/M1-tasks.md](./tasks/M1-tasks.md) … M35 | Checkboxes + completion logs |
| [issues/](./issues/) | Offline QA bug reports (`BUG-xxx.md`) |
| [features/](./features/) | What each part of the app **does today**, and where it is half-built |
| [audits/](./audits/) | Point-in-time reviews of what is missing (POS/khata, 2026-08-11) |
| [frontend-design-guide.md](./frontend-design-guide.md) | Landing-page design brief — palette, motion contract, and the product claims that may **not** be made |
| [mobile-pos-design.md](./mobile-pos-design.md) | How the till should work on a phone, and what has to land before it can |
| [../backend/README.md](../backend/README.md) | API setup |
| [../e2e/README.md](../e2e/README.md) | Playwright QA harness |

## Current status

- **Branch:** `feature-2`
- **Milestone:** M35 — Kiryana pack (**done**, 2026-08-07). M13 done; M14–M34 scaffolded, M14 **in progress**
- **Next task:** M14-T1 (`ReportAggregator`: SQL-level `SUM`/`GROUP BY` aggregation instead of PHP loops)
- **Last updated:** 2026-08-07
- **QA:** passed for M1–M35 (`npm run qa:m35`, 80 E2E tests, both dev servers live; `npm test`, 70 unit tests; `cd backend && php artisan test`, 365 tests / 1,702 assertions; `npm run lint`, 32 modules OK; `cd backend && ./vendor/bin/pint --test` clean)
- **Notes:** Logged-in pages use a sidebar app shell (`js/shell.js`); dashboard has Chart.js line/bar charts; profile page updates name/password; reports export to PDF (jsPDF); landing uses GSAP + Lenis scroll animations. M11 added an admin panel (`admin*.html`, `AdminController`, `is_admin` on users) — it was built outside this workflow and had several bugs (see `docs/issues/M11/`) that were reviewed and fixed: `is_admin` mass-assignment, the `/api/user` payload missing `is_admin` (sidebar link disappeared), wrong endpoint/field names on the Cash Entries admin page, a `gateway`/`provider` column mismatch on Payments, `e2e/playwright.config.js` never actually running the M11 suite, and stored-XSS/broken-onclick issues in the admin tables. A follow-up fix closed a CSS bug (`BUG-007`) where admin edit/delete modals were always visible and uncloseable. M12 closed the security findings from the app-wide review: payment IPN no longer bypasses signature checks in sandbox mode, Google OAuth no longer puts the bearer token in the URL (one-time code exchange via `POST /api/auth/google/exchange`), live JazzCash/EasyPaisa checkouts no longer return merchant credentials in JSON (server-rendered signed redirect page instead), Sanctum tokens now expire (30 days) and password change revokes other sessions, `is_admin` is no longer mass-assignable, admin payment responses no longer leak the raw gateway `payload`, `PUT /user/password` and the Google OAuth routes are throttled, `billing.sandbox` requires an explicit `BILLING_SANDBOX=true` env flag, and entry `amount`/admin `per_page` now have max bounds. M13 fixed the dark-then-light flash on page load: `css/styles.css` now paints light theme by default (unattributed `:root`), with `:root[data-theme='dark']` as the explicit override; all 15 pages that duplicated `initTheme`/`applyTheme` now import a single shared `js/theme.js`, whose fallback logic defaults to light unless the OS explicitly prefers dark; a new `e2e/tests/m13-theme.spec.js` verifies the CSS-only first paint, toggle persistence, and that an explicit choice beats the OS preference.
- **M23 (2026-08-06) changed the product's direction.** Two things happened that supersede parts of the backlog below. (1) **Billing moved to Paddle.** JazzCash and EasyPaisa were deleted — both gateway classes, `GatewayResolver`, the `PaymentGateway` contract, `BillingCallbackController`, the IPN/return routes and the `gateway-redirect` Blade page. In their place: `PaddleClient`, `PaddleGateway`, `PaddleWebhookController`, a `webhook_events` dedup table, external ids on `subscriptions`/`payments`, `users.paddle_customer_id`, and a customer-portal + cancel endpoint. Subscription periods are now **set from provider webhooks**, not extended additively per payment. Pricing moved from PKR to USD. (2) **The tax calculator was removed** and a **point of sale** was added: `products`, `product_categories`, `sales`, `sale_items` and `stock_movements` tables, a transactional `SaleService` that row-locks products to prevent overselling, and `pos.html` / `products.html`. Each sale posted an income `cash_entry` under the `sales` category, so existing dashboard/reports/export code picked it up unchanged — **that wiring is gone**: `DayBookService` later replaced it with a per-day float/close pair, and reports were blind to sales until M35 restored them from `sale_items`.
- **M35 (2026-08-07) — the kiryana pack.** The POS from M23 assumed every product is a countable piece, bought at a known cost, paid for in full in cash, to the paisa. A Pakistani grocery shop breaks all four, so this milestone fixed all four. (1) **Weighed goods**: prices and stock are now stored per canonical **base** unit (`pc`/`g`/`ml`) with `price_unit` (kg, litre, dozen) as display only, and the till keypad works both directions — by weight ("ek pao daal" → 250 g → Rs 62.50) and by amount ("pachaas ka daal" → Rs 50 → 200 g). Money columns widened to `decimal(12,4)` because namak at Rs 40/kg is Rs 0.04/g and collapses to zero at 2 dp. New `App\Support\Unit` + `js/units.js`. (2) **Purchases / Stock In** with weighted-average cost, which is what finally populates `unit_cost`; stock still moves only through the audited `stock_movements` path. (3) **Profit & cash-position reports** — `ReportAggregator` previously had *zero* references to sales, so it could not see the shop's takings at all; `App\Support\SaleProfit` is now the single definition of margin shared by the sales list and reports, and revenue with no recorded cost is reported as *uncosted* rather than counted as profit. (4) **Shop expense categories** — the personal-finance seed (Car Wash, Petrol, Entertainment) retired via `is_active` rather than deleted, so existing `cash_entries` keep a name. (5) **Customer khata (udhaar)** — who-owes-me list with 30/60/90 aging, running statement, oldest-first payment allocation, credit limits; customer matching is conservative (exact phone wins, a bare name may only claim a page with no number yet). (6) **Wastage, expiry, pack size** — typed reasons valued in rupees, with `count_correction` deliberately *not* a wastage reason. (7) **Whole-rupee settlement** — paisa coins do not circulate, so the total rounds half-up with the remainder kept in `rounding_adjustment`, and frontend and backend round identically. Six bugs were found on the way, five of them from the move to float quantities: `remainingQuantity() === 0` is always false for floats so no sale could ever reach `refunded`; `ActivityLogger::stockAdjusted()` truncated 1500.5 g to 1500; a `"0.000" !== 0` guard filed a bogus stock movement for every new product; `scopeSelectable()` ignored `is_active` (invisible to unit tests — a fresh test DB has no legacy rows — and only caught by the live Playwright run); `is_active` was missing from `$fillable`; and a pre-existing CSS specificity bug left `products.html`'s "Cancel edit" button permanently visible. **A second wave (T8–T10) followed shop-owner testing**, and its root cause is the milestone's real lesson: `pos.html` exposed only 3 of the 7 supported payment methods and had no customer field and no paid-amount field at all, so the whole udhaar path — API, service, policy, 28 passing tests — was **unreachable from the till**; every sale fell through the settled-in-full branch and was written `paid`. A green backend suite says the API is right, not that anyone can reach it. (8) **Udhaar at the till**: all six settled-now methods with a reference field for the non-cash ones, and credit deliberately **not** in the dropdown — it gets its own `#pos-credit` "Save as Udhaar" button and dialog, because taking goods on the book is a different act from taking money and must not be a mis-click. A blank deposit is legitimate and lands as `pending`. The till never calls `POST /api/customers`; `SaleService::resolveCustomer` owns page resolution so the two paths cannot diverge. (9) **Khata partial payments** with `received_by_name` — deliberately distinct from `recorded_by`, because the login that types a payment in and the person who physically took the cash are routinely different people, and only the second answers "who took my money"; plus a payments array on the ledger and a "Payments received" card. (10) **Supplier invoice payments** — `purchase_payments` mirroring `sale_payments`, a "What I owe suppliers" card, and a full-width in-page delivery detail view (not a dialog: `.pos-modal-card` caps at 520px and the screen is read beside the supplier's paper bill). Paid totals on both sides are **re-derived from `SUM(amount)`, never incremented**, so they cannot drift from the history. Three more bugs: validation silently stripped `received_by_name` so the feature existed and did nothing; `.pos-modal-card`'s column flex shrank the customer hit list to zero height while it still counted as visible; and `.pos-day-figures` defeated `[hidden]` the same way bug #6 did. See `docs/milestones/M35-kiryana-pack.md`.
- **M14–M34** are the remainder of the app-wide improvement review backlog (note: the "keep the tax calculator and billing model as-is" assumption below no longer holds — see M23): performance/code-quality cleanup (in progress), UX/accessibility polish, then a long tail of new cash-flow features (search/filters, budgets, CSV import/export, recurring transactions, accounts/wallets, user categories, soft delete, receipts, report comparisons, notifications, goals, tags, bulk ops, backup export, multi-currency, audit trail, dashboard customization, PWA, keyboard shortcuts). See `docs/README.md` for the full list and `docs/milestones/M14-performance-code-quality.md` onward for each one's scope. Being built in that order, one milestone at a time.

## Architecture decisions (locked)

- Keep vanilla frontend; Laravel is **API only** (no Blade UI rebuild).
- Auth: Sanctum bearer tokens (simpler cross-origin than SPA cookies for separate ports).
- Frontend `API_BASE_URL` default: `http://localhost:8000`
- Frontend serve: `npm start` → port 3000
- Backend serve: `cd backend && php artisan serve` → port 8000
- Paddle webhooks hit `POST /api/billing/paddle/webhook` and are the **only** thing that moves subscription state; the browser's post-checkout redirect back to `billing.html?status=…` is cosmetic.
- ~~POS sales write an income `cash_entry` (category `sales`) so the dashboard, reports and exports need no POS-specific wiring.~~ **No longer true.** `DayBookService` replaced the per-sale `cash_entry` with a per-day float/close pair, so nothing in `cash_entries` represents an individual sale any more. That is precisely why the reports could not see the shop's takings until M35: `ReportAggregator` read `cash_entries` only. Sales figures now come from `sale_items` via `App\Support\SaleProfit`, which is the single shared definition of revenue and margin for both the sales list and `GET /api/reports/profit`.
- Stock changes only through the audited path — `SaleService` (sales/refunds), `PurchaseService` (stock in) or the adjust endpoint; every change writes a `stock_movements` row with the running balance.
- Product prices and stock are stored per canonical **base** unit (`pc`/`g`/`ml`, see `App\Support\Unit` and `js/units.js`). `price_unit` (kg, litre, dozen) is display and data-entry only. Never store a per-kilo price — cheap-per-gram goods lose paisa on every line.

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

M1 Foundation → M2 Auth → M3 Cash flow → M4 Reports → M5 Tax nav → M6 Billing → M7 Gate → M8 Receipt stub → M9 Free trial → M10 Premium UI → M11 Admin panel → M12 Security → M13 Theme fix → M14 Perf/code-quality → M15 UX polish → M16 Search/filters → M17 Budgets → M18 CSV → M19 Recurring → M20 Accounts → M21 User categories → M22 Soft delete → M23 Receipts → M24 Report comparisons → M25 Notifications → M26 Goals → M27 Tags → M28 Bulk ops → M29 Backup export → M30 Multi-currency → M31 Audit trail → M32 Dashboard customization → M33 PWA → M34 Keyboard shortcuts → M35 Kiryana pack

M1–M13 complete. **M35 (kiryana pack) complete** — built ahead of the backlog because the
shop-facing gaps it closes blocked real use of the till. M14 in progress. M15–M34 not started
(see `docs/README.md` milestone table for links).
