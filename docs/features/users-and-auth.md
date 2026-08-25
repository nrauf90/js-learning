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

The email match is only honoured when Google's **`email_verified` claim** says
the address is verified. Without that check, matching on email alone is account
takeover with no password: register a Google account claiming a shopkeeper's
address and the callback would graft that `google_id` onto their row and mint a
token for it. The same check gates *creating* an account, so an unverified
address cannot claim one the real owner would later need. Either way the callback
redirects to `login.html?error=google_email_unverified` and touches nothing.

The callback then does the important part: it **never puts the bearer token in the
URL**. A token in a redirect ends up in browser history, `Referer` headers and
server access logs. Instead it mints a random 40-character code, caches
`{ token, user_id }` against it for two minutes, and redirects to
`login.html?google_code=…`. `js/auth.js` immediately strips the code from the
address bar with `history.replaceState`, then `POST /api/auth/google/exchange`
swaps it for the real token. `Cache::pull` makes the code single-use.

A failure at the Socialite step redirects to `login.html?error=google_auth_failed`
rather than showing a stack trace.

### Forgotten passwords

`POST /api/password/forgot` takes an email and sends Laravel's standard reset
notification. The response is **the same whether or not the address belongs to an
account** — including when the broker's own 60-second per-address throttle fires,
because "you already asked for this recently" confirms the address exists just as
loudly as a plain success. Anything else turns the endpoint into an
account-enumeration oracle.

The link points at the frontend, not at Laravel: the API host serves JSON and has
no reset form on it. `AppServiceProvider::configurePasswordResetLinks()` rewrites
it to `{FRONTEND_URL}/reset-password.html#token=…&email=…`.

The token rides in the **fragment**, which is never sent to a server — so it stays
out of the frontend host's access logs, out of `Referer` headers, and out of any
proxy in between. It also survives static hosts that rewrite `/page.html` to
`/page`; that redirect drops the query string, which would strip the token off
every link the app ever mailed. `js/password-reset.js` reads the fragment, falls
back to a query string for links mailed under the older format, and clears it
from the address bar with `history.replaceState` either way.

`POST /api/password/reset` consumes the token and sets the password. It **revokes
every Sanctum token on the account** — a reset is what someone does when they
think the old password is loose, so leaving the till logged in on the device that
leaked it would defeat the exercise. A bad token, an expired one and a mismatched
address all produce one identical message, so the endpoint cannot be used to
probe which addresses have a live reset outstanding.

Both routes are throttled harder than login: 5/min in production (60 in
local/testing), because `/password/forgot` sends mail on behalf of an address the
caller does not have to own.

Screens: `forgot-password.html` (reachable from a link under the login button)
and `reset-password.html`. Opening the reset page without a token disables the
submit button and says so, rather than letting someone type a password and fail.

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
| Pages | `login.html`, `signup.html`, `profile.html`, `forgot-password.html`, `reset-password.html` |
| Controllers | `js/auth.js`, `js/profile.js`, `js/password-reset.js` |
| Redirect allowlist | `js/safe-redirect.js` |
| API client | `js/api.js` |
| Shell (user card, logout) | `js/shell.js`; public-page nav in `js/nav.js` |
| API | `backend/app/Http/Controllers/Api/AuthController.php`, `GoogleAuthController.php`, `PasswordResetController.php` |
| Reset link format | `backend/app/Providers/AppServiceProvider.php` |
| Model | `backend/app/Models/User.php` |
| Migrations | `0001_01_01_000000_create_users_table.php`, `2026_07_30_124107_add_google_fields_to_users_table.php`, `2026_07_30_230000_add_is_admin_to_users_table.php`, `2026_08_06_100000_add_paddle_customer_to_users_table.php`, `2026_08_07_100001_add_roles_to_users_table.php` |
| Tests | `backend/tests/Feature/AuthTest.php`, `GoogleAuthTest.php`, `ProfileTest.php`, `PasswordResetTest.php`; `tests/safe-redirect.test.js` |
| E2E | `e2e/tests/m2-auth.spec.js`, `e2e/tests/m36-password-reset.spec.js` |

## API endpoints

| Method | Path | Auth | What it does |
|---|---|---|---|
| POST | `/api/register` | no | Create an account → `{ user, token }` |
| POST | `/api/login` | no | → `{ user, token }` |
| POST | `/api/password/forgot` | no | Mail a reset link; the answer never says whether the address exists |
| POST | `/api/password/reset` | no | Consume the token, set the password, revoke every session |
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

- **Password reset needs working mail.** The flow is complete (see above), but
  `MAIL_MAILER` defaults to `log`, so on a fresh checkout the link goes to
  `storage/logs/laravel.log` rather than an inbox. A deployment has to configure a
  real mailer or the reset is a dead end.
- **The mobile app has no forgot-password screen.** `mobile/login.html` is the
  till's login; a locked-out cashier resets from the web app, or their shop admin
  resets them from the staff screen.
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
- The `next` parameter is filtered through `safeNext()` (`js/safe-redirect.js`)
  before it reaches `window.location.href`. It is an allowlist by shape — a bare
  `*.html` in this directory, optionally with a query — refusing schemes,
  protocol-relative `//host`, backslash variants and `..`. It used to be used
  unvalidated, which made `login.html?next=https://evil.example` an open redirect
  straight off the password field. See `tests/safe-redirect.test.js`.
