# Users and authentication

## What it is

Signing up, logging in — by email or with Google — staying logged in, changing
your name or password, and logging out. Everything else in the app hangs off the
bearer token this produces.

## How it works

### Tokens, not cookies

Laravel Sanctum **personal access tokens**, sent as
`Authorization: Bearer <token>`. Chosen over SPA cookie auth because the frontend
and the API run on different ports (3000 and 8000), and cross-origin cookies are a
much fussier arrangement than a header.

Tokens expire after **30 days** by default
(`SANCTUM_TOKEN_EXPIRATION_MINUTES`, `config/sanctum.php`).

The browser keeps the token in `localStorage` under `cashflow_auth_token`
(`js/api.js`), plus a cached user object under `cashflow_auth_user` and the time it
was fetched under `cashflow_auth_user_at`.

### Sign-up and login

`POST /api/register` takes name, email and a confirmed password validated against
`Password::defaults()`, and returns `{ user, token }`. `POST /api/login` checks
the credentials and returns the same shape; a wrong password and an unknown email
produce the identical message, and an account with no password (a Google-only
signup) cannot be logged into with one.

Both, plus the Google exchange and the password change, are **throttled**:
10 requests a minute in production, 120 in local/testing so the E2E harness can
run.

`POST /api/logout` revokes only the token used for that request, so logging out on
the phone does not log the till out.

### Google OAuth

`GET /api/auth/google/redirect` starts a stateless Socialite flow.
`GET /api/auth/google/callback` matches on `google_id` first, then on email, and
creates an account if neither matches (with a random password, so the account can
only be reached through Google until the owner sets one).

The callback then does the important part: it **never puts the bearer token in the
URL**. A token in a redirect ends up in browser history, `Referer` headers and
server access logs. Instead it mints a random 40-character code, caches
`{ token, user_id }` against it for two minutes, and redirects to
`login.html?google_code=…`. `js/auth.js` immediately strips the code from the
address bar with `history.replaceState`, then `POST /api/auth/google/exchange`
swaps it for the real token. `Cache::pull` makes the code single-use.

A failure at the Socialite step redirects to `login.html?error=google_auth_failed`
rather than showing a stack trace.

### Profile

`PUT /api/user/profile` updates the name only. `PUT /api/user/password` requires
the current password, applies `Password::defaults()` to the new one, and then
**revokes every other token** — changing your password ends every other session
and device, which is the point of changing it.

`GET /api/user` returns `toAuthArray()`: `id`, `name`, `email`, `avatar`,
`is_admin`, `role`, `shop_id`, `can_manage_products`. `google_id` is deliberately
omitted — it is an internal linking id with no frontend use. This is the same
shape every auth endpoint returns, so the frontend has one user object to cache.

`is_admin` being in that payload is load-bearing: the sidebar decides whether to
show the admin link from the cached user, and omitting it once made the link
disappear (`docs/issues/M11/BUG-002`).

### Redirects and session handling

Every logged-in page calls a local `requireAuth()` that redirects to
`login.html?next=<page>` when there is no token, and `js/auth.js` sends an
already-logged-in visitor straight on to `next` (default `dashboard.html`).

`js/api.js` handles two failures centrally:

- **401** — clears the token and bounces to `login.html?next=…`, unless the caller
  is already on an auth page or is calling `/api/login` or `/api/register`.
- **402** — renders an in-page "subscription lapsed" `alertdialog` (once per page
  load) naming the shop and the account to quote, with a Log out button. It used
  to redirect to `billing.html`; with self-serve billing closed that would be a
  dead end. See [billing-subscriptions.md](./billing-subscriptions.md).

The app shell caches the user for **five minutes** before re-fetching
`/api/user`. The shell mounts on every logged-in page, so an unconditional fetch
added a third request to each navigation purely to redraw a name that had not
changed — and the PHP dev server handles one request at a time, so it delayed the
data the page actually needed.

### CORS

`config/cors.php` allows `http://localhost:3000` and `http://127.0.0.1:3000` by
default (`CORS_ALLOWED_ORIGINS`, comma-separated), supports credentials, and sets
`max_age` to 24 hours. Every call carries an `Authorization` header, which makes
it a preflighted request; at `max_age: 0` the browser may not cache the OPTIONS
result, so each GET would cost two round trips through a full framework boot.

`js/api.js` defaults to `http://127.0.0.1:8000` rather than `localhost` because on
Windows `localhost` resolves to `::1` first and the dev server only listens on
IPv4 — every request paid roughly 200 ms for the refused IPv6 connect, repeated on
each call because the dev server sends `Connection: close`.

## Screens / files

| Layer | File |
|---|---|
| Pages | `login.html`, `signup.html`, `profile.html` |
| Controllers | `js/auth.js`, `js/profile.js` |
| API client | `js/api.js` |
| Shell (user card, logout) | `js/shell.js`; public-page nav in `js/nav.js` |
| API | `backend/app/Http/Controllers/Api/AuthController.php`, `GoogleAuthController.php` |
| Model | `backend/app/Models/User.php` |
| Migrations | `0001_01_01_000000_create_users_table.php`, `2026_07_30_124107_add_google_fields_to_users_table.php`, `2026_07_30_230000_add_is_admin_to_users_table.php`, `2026_08_06_100000_add_paddle_customer_to_users_table.php`, `2026_08_07_100001_add_roles_to_users_table.php` |
| Tests | `backend/tests/Feature/AuthTest.php`, `GoogleAuthTest.php`, `ProfileTest.php` |
| E2E | `e2e/tests/m2-auth.spec.js` |

## API endpoints

| Method | Path | Auth | What it does |
|---|---|---|---|
| POST | `/api/register` | no | Create an account → `{ user, token }` |
| POST | `/api/login` | no | → `{ user, token }` |
| POST | `/api/auth/google/exchange` | no | Swap a one-time code for a token |
| GET | `/api/auth/google/redirect` | no | Start the Google flow |
| GET | `/api/auth/google/callback` | no | Google returns here; redirects to the frontend with a code |
| GET | `/api/user` | bearer | The current user |
| PUT | `/api/user/profile` | bearer | Update the name |
| PUT | `/api/user/password` | bearer | Change password, revoke other sessions |
| POST | `/api/logout` | bearer | Revoke the current token |
| GET | `/api/health` | no | `{ status: 'ok', app }` — connectivity check |

## Permissions & gating

- None of these sit behind the subscription gate — you must be able to log in and
  reach the billing page with an expired trial.
- `is_admin`, `role`, `shop_id`, `can_manage_products` and `paddle_customer_id` are
  all out of `$fillable`. `is_admin` is set only in `AdminController::userUpdate()`
  and the admin seeder; `paddle_customer_id` only by the billing layer (pointing a
  user row at someone else's Paddle customer would hand them that customer's
  portal, invoices and payment methods).
- Password rules come from `Password::defaults()` everywhere — registration, staff
  creation, admin shop-owner onboarding and the password change all share them.

## Edge cases & known limits

- **No password reset.** `password_reset_tokens` exists (Laravel's default table)
  but there is no forgot-password endpoint or screen. A locked-out shop owner has
  no self-service route back in; a locked-out staff member can be reset by their
  shop admin.
- **No email verification.** `email_verified_at` exists and is only set by the
  admin seeder.
- **Email cannot be changed** by the account holder — `PUT /api/user/profile`
  takes the name only. A shop admin can change a staff member's email; a platform
  admin can change anyone's.
- **The token is in `localStorage`**, which is readable by any script on the
  origin. The app's XSS defences are the `escapeHtml`/`escapeAttr` helpers used on
  every rendered value, not a storage boundary.
- **A Google account created by the callback has a random password**, so
  `PUT /api/user/password` can never be satisfied for it — there is no way to add
  an email/password login to a Google-only account.
- **Sign-up does not create a shop.** A self-registered account is a `shop_admin`
  with `shop_id` null until they save shop details; several staff endpoints 409
  until they do.
- The `next` parameter is taken from the query string and used directly as
  `window.location.href`. It is only ever produced by the app's own redirects, but
  it is not validated.
