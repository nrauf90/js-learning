# Admin panel

## What it is

The platform operator's back office — a separate set of pages behind
`is_admin`, for whoever runs the service rather than whoever runs a shop. It
lists every account, onboards new shops, shows subscriptions and payments across
the install, and maintains the shared income/expense category list.

It is **not** a shop-facing feature. A shop owner manages their own shop and staff
on `shop.html`; see [shop-and-staff.md](./shop-and-staff.md).

## How it works

### Who gets in

`AdminOnly` middleware checks `$request->user()->is_admin` and returns 403
otherwise. `is_admin` is the authority for a platform operator; `users.role` is
just the column default on those rows, which is why `shopAdmins()` filters
`role = 'shop_admin' AND is_admin = false` rather than trusting the role alone.

The sidebar shows an "Admin Panel" link only when the cached user has
`is_admin: true`, and switches to a separate admin nav section once you are on an
`admin*` page (`js/shell.js`). Each admin page also handles a 403 by showing
"Access denied" and bouncing to `dashboard.html` after two seconds.

A seeded operator account exists for a fresh install:
`admin@cashflow.local` / `admin123` (`AdminUserSeeder`, which sets `is_admin`
through `forceFill` because it is not mass-assignable). It prints a warning to
change the password.

### The pages

| Page | What it does |
|---|---|
| `admin.html` | Totals — users, active subscriptions, this month's revenue, cash entries — plus the five newest users and ten newest payments |
| `admin-users.html` | Every account, searchable, with entry/subscription/payment counts; edit name, email and admin flag; delete |
| `admin-shops.html` | Onboard a shop owner (login + shop in one call) and list existing ones with a staff count |
| `admin-subscriptions.html` | Grant or extend a shop's subscription (email + plan + optional end date), then every subscription, paginated and filterable |
| `admin-entries.html` | Every cash entry across the install, paginated, filterable by type, user and date range |
| `admin-payments.html` | Every payment, filterable by status and provider |
| `admin-categories.html` | Create, rename and delete the shared income/expense categories |

### Onboarding a shop

`POST /api/admin/shop-admins` creates the owner's login and the shop it runs in
**one transaction**. The two halves are useless apart — an owner with no shop
cannot add staff, a shop with no owner has nobody to run it.

Only name, email and password are mass-assigned; `is_admin` stays out of reach
entirely, so this endpoint creates shop owners and never another platform
operator, however the request is shaped. `assignRole(ROLE_SHOP_ADMIN, $shop->id)`
sets the role and link through the explicit setter.

Password rules come from `Password::defaults()`, so an account created here logs
in through the same endpoint as one that signed itself up.

The list endpoint returns staff counts as **one grouped count for the whole page**
rather than a count query per row — this list is the platform's customer list and
only grows.

Notably, this pair of routes lives inside the `subscribed` group but explicitly
opts out with `->withoutMiddleware('subscribed')`: whether the operator's own
trial is still running has nothing to do with signing up a customer.

### Guard rails on user edits

- An admin cannot remove their own admin flag (422).
- An admin cannot delete themselves (422).
- `is_admin` is set explicitly in `userUpdate()` — the one place it is allowed to
  change — because it is deliberately not mass-assignable.
- Deleting a user cascades in a transaction: cash entries, add-ons, subscriptions,
  payments, tokens, then the row.

### Granting a shop its subscription

Self-serve checkout is closed — shops are onboarded **and renewed** by hand — so
`POST /api/admin/subscriptions` is how a shop gets access. It takes `user_id` or
`email`, a plan, and an optional `ends_at`, and either extends the existing
subscription (from whatever is left on it, so an early renewal never loses days)
or creates one with `provider: 'manual'` and no `external_id`. The four Paddle
endpoints — checkout, portal, cancel and the sandbox completion — moved under
`admin` at the same time. See
[billing-subscriptions.md](./billing-subscriptions.md).

### Categories

These are the shared `expense_categories` rows the whole install uses. Deleting
one is refused with a count if any cash entry references it — the intended route
is retirement via `is_active`, which the seeder does. See
[cash-flow.md](./cash-flow.md).

### History

M11 built this panel outside the normal workflow and it shipped with several
bugs, all since fixed and recorded in `docs/issues/M11/`: `is_admin` was
mass-assignable, `/api/user` omitted `is_admin` so the sidebar link vanished, the
cash-entries page called the wrong endpoint and field names, the payments page
read a `gateway` column that is actually `provider`, `e2e/playwright.config.js`
never ran the M11 suite, the admin tables had stored-XSS and broken `onclick`
handlers, and a CSS specificity bug left the edit/delete modals permanently
visible and uncloseable. M12 additionally stopped the payments endpoint leaking
the raw gateway `payload` and put a hard cap on `per_page`.

## Screens / files

| Layer | File |
|---|---|
| Pages | `admin.html`, `admin-users.html`, `admin-shops.html`, `admin-subscriptions.html`, `admin-entries.html`, `admin-payments.html`, `admin-categories.html` |
| Controllers | `js/admin.js`, `js/admin-users.js`, `js/admin-shops.js`, `js/admin-subscriptions.js`, `js/admin-entries.js`, `js/admin-payments.js`, `js/admin-categories.js` |
| API | `backend/app/Http/Controllers/Api/AdminController.php` |
| Middleware | `backend/app/Http/Middleware/AdminOnly.php` |
| Seeder | `backend/database/seeders/AdminUserSeeder.php` |
| Setup notes | `ADMIN_SETUP.md` (repo root) |
| Tests | `backend/tests/Feature/AdminPanelTest.php` |
| E2E | `e2e/tests/m11-admin-panel.spec.js` |

## API endpoints

All under `/api/admin`, all requiring `auth:sanctum` + `AdminOnly`.

| Method | Path | What it does |
|---|---|---|
| GET | `/dashboard` | Stats + recent users + recent payments |
| GET | `/users` | Paginated, `search`, `admin_only=true` |
| GET | `/users/{user}` | One user with subscriptions, payments and entry stats |
| PUT | `/users/{user}` | Name, email, `is_admin` |
| DELETE | `/users/{user}` | Cascading delete |
| GET | `/subscriptions` | Paginated, `status`, `plan` |
| POST | `/subscriptions` | Grant or extend a shop's subscription — `user_id` or `email`, `plan`, optional `ends_at` |
| PUT | `/subscriptions/{subscription}` | `status`, `ends_at` |
| GET | `/cash-entries` | Paginated, `type`, `user_id`, `date_from`, `date_to` |
| DELETE | `/cash-entries/{cashEntry}` | Delete any entry |
| GET | `/payments` | Paginated, `status`, `gateway` (matched against `provider`) |
| GET | `/categories` | With `cash_entries_count` |
| POST | `/categories` | `name`, `kind` |
| PUT | `/categories/{category}` | `name`, `kind` — re-slugs on rename |
| DELETE | `/categories/{category}` | Refused (422) while entries reference it |
| GET | `/shop-admins` | Paginated shop owners with their shop and staff count |
| POST | `/shop-admins` | Create owner + shop in one transaction |

`per_page` is clamped to `[1, 100]` on every paginated endpoint, so a huge value
cannot be used for a DoS-y page load.

Subscription status must be one of `active`, `trialing`, `past_due`, `paused`,
`canceled`, `expired` — the values Paddle actually reports. The old list had
British "cancelled", which no webhook ever writes, so an admin edit could not
round-trip a real status.

## Permissions & gating

- `auth:sanctum` + `AdminOnly` on every route.
- The `/shop-admins` pair sits inside the `subscribed` group but opts out of it.
  Every other admin route is registered outside that group entirely.
- `ShopAdminOnly` deliberately turns platform admins **away** — they have no shop
  of their own, and letting them through would mean silently operating on someone
  else's staff list.
- All values rendered into admin tables go through an `escapeHtml()` helper and
  actions use `data-*` attributes with delegated listeners rather than inline
  `onclick` string interpolation. The one remaining exception is the pagination in
  `js/admin-shops.js`, which builds `onclick="window.changePage(N)"` — safe
  because only numeric page ids are interpolated, but inconsistent with the rest.

## Edge cases & known limits

- **The admin panel is not shop-aware.** `admin-entries.html` lists cash entries
  across the whole install with no shop grouping; `/admin/dashboard` counts every
  user and every entry. There is no per-shop drill-down beyond the shop list.
- **No POS visibility.** Sales, products, purchases, khata and day books have no
  admin views at all.
- **`/admin/dashboard` revenue is a raw `SUM(amount)`** of completed payments this
  month with no currency grouping — it assumes every payment is in
  `config('billing.currency')`, which is not guaranteed for historical rows.
- **Filters use `$request->has(...)`**, so an empty query parameter still applies
  the filter (`?status=` filters on an empty status).
- **Editing or granting a subscription writes local state directly.** For a
  hand-granted (`manual`) row that is the point; for a Paddle-backed one the next
  `subscription.*` webhook will overwrite it.
- **The grant form takes an email only** (`#grant-form` on
  `admin-subscriptions.html`), not a `user_id`, so the operator has to know which
  address owns the shop.
- **`admin-payments.html` renders only the first page** — it calls the endpoint
  with no `page` parameter and has no pagination controls, so anything past the
  first 20 payments is invisible. Users, shops, cash entries and subscriptions all
  have pagination.
- **The seeded admin password is `admin123`** and nothing forces a change.
- Deleting a user does **not** clean up their products, sales, purchases,
  customers, shop or activity rows — those cascade at the database level via their
  own foreign keys, but the transaction in `userDestroy()` only names five
  relations, so it is the FK constraints doing the work.
