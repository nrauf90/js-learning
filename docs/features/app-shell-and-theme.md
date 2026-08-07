# App shell, navigation and theme

## What it is

The chrome every logged-in page shares: the sidebar with the app's links, the
mobile drawer, the user card and log-out button, and the light/dark toggle. It is
one module rather than markup repeated on eleven pages.

## How it works

### The sidebar

`initShell({ current })` renders `#app-sidebar` on every logged-in page. Links are
declared once in `js/shell.js`:

| key | page | label |
|---|---|---|
| `pos` | `pos.html` | Sell |
| `sales` | `sales.html` | Sales |
| `products` | `products.html` | Products |
| `purchases` | `purchases.html` | Stock In |
| `customers` | `customers.html` | Khata |
| `activity` | `activity.html` | Activity |
| `dashboard` | `dashboard.html` | Dashboard |
| `cashflow` | `cashflow.html` | Cash Flow |
| `reports` | `reports.html` | Reports |
| `profile` | `profile.html` | Profile |

Stock In and Khata sit next to the catalogue deliberately: both are things the
owner does *between* serving customers, not while serving one.

**Billing is deliberately absent.** Accounts are opened and renewed by the
platform admin, and `billing.html` bounces a shop straight back — a link that only
ever returns you to where you came from reads as a broken app. `ICONS.billing` is
kept for the admin navigation. See
[billing-subscriptions.md](./billing-subscriptions.md).

Two links are conditional:

- **Shop & Staff** (`shop.html`) appears only for `role === 'shop_admin' && !is_admin`.
  Staff have nothing to manage, and a platform admin manages shops from the admin
  panel instead.
- **Admin Panel** appears only for `is_admin`. On an `admin*` page the sidebar
  switches entirely to the admin section plus a "Back to App" link.

Each link carries an inline SVG icon from an `ICONS` map keyed by the same link
key, and the active link gets `aria-current="page"`.

### The page heading icon

`renderHeadingIcon(current)` repeats the sidebar glyph beside the page's own `<h1>`
by injecting a `<span class="topbar-icon" aria-hidden="true">` into
`.topbar-heading h1`. It is injected from the shell rather than written into each
page's markup — fifteen hand-kept copies is exactly how the pages drifted apart
before — and it is re-entrant: `initShell()` runs a second time when the refreshed
user turns out to be an admin, and that must not stack a second glyph on the
heading, so any existing `.topbar-icon` is removed first.

A page with no matching icon key, or no `.topbar-heading h1`, simply gets nothing.

### The user card, and why it is cached

The card shows initials, name and email from `localStorage.cashflow_auth_user`.
The shell renders the cached user **immediately** and only re-fetches `/api/user`
when the cache is older than five minutes
(`cashflow_auth_user_at`, `USER_REFRESH_MS`).

The shell mounts on every logged-in page, so an unconditional `/api/user` added a
third request to each navigation purely to redraw a name that had not changed —
and the PHP dev server handles one request at a time, so it delayed the data the
page actually needed.

If the refreshed user's `is_admin` differs from the cached one, the sidebar
re-renders itself, which is what makes a newly granted admin see the link without
a hard reload.

Log out revokes the current token via `POST /api/logout`, clears both cache keys
and the token, and goes to `login.html`. Network errors on logout are ignored —
the local session is cleared either way.

### The mobile drawer

`#sidebar-toggle` in the topbar flips `body.sidebar-open` and keeps
`aria-expanded` in step. The overlay, Escape, and clicking any sidebar link all
close it.

### Public-page navigation

`js/nav.js` is a separate, simpler thing for the pages with no sidebar —
`index.html`, `login.html`, `signup.html`, `privacy.html`, `terms.html`. It builds
a horizontal nav, hides the auth-only entries when there is no token, injects a
hamburger toggle if one is missing, and turns the last link into either "Log in"
(carrying a `next` back to the current page) or "Log out".

Its `PAGES` map still lists the pre-POS set — home, pos, products, dashboard,
reports, cashflow, profile — so it does not know about Sales, Stock In, Khata,
Activity or Shop. Billing was removed from it at the same time as from the
sidebar, so the legacy top nav cannot offer a door the sidebar has already closed.

### Theme

`js/theme.js` is one shared module, extracted from the fifteen pages that each had
their own copy.

**Light is the true default.** `css/styles.css` paints the light palette on the
unattributed `:root` and treats `:root[data-theme='dark']` as the override, so
the first paint is light before any JavaScript runs — no dark-then-light flash on
load. `preferredTheme()` then applies the same rule: an explicit stored choice
always wins, otherwise dark is used **only** if the OS explicitly prefers it.

The choice is persisted under `tax-calculator-theme` — a leftover key name from
when the app was a tax calculator, kept because renaming it would silently reset
every existing user's preference.

`initTheme(onToggle)` takes an optional callback fired after a *user* toggle (not
on load). The dashboard and reports pages pass one, because their Chart.js
instances read their text and grid colours from CSS custom properties and would
otherwise stay in the old palette until the next data load.

The toggle button keeps `aria-pressed` and its `aria-label` in step
("Switch to dark theme" / "Switch to light theme").

## Screens / files

| Layer | File |
|---|---|
| Sidebar shell | `js/shell.js` |
| Public nav | `js/nav.js` |
| Theme | `js/theme.js` |
| Landing animations | `js/motion.js` |
| Styles | `css/styles.css` |

Pages using the shell: `pos.html`, `sales.html`, `products.html`,
`purchases.html`, `customers.html`, `activity.html`, `dashboard.html`,
`cashflow.html`, `reports.html`, `billing.html`, `profile.html`, `shop.html` and
all seven `admin*.html`.

Note `pos.html` is the one exception — it does **not** call `initShell()`. The till
is a full-screen working surface, so it carries no sidebar, and `js/pos.js` tops
up the cached user itself (`ensureShopName()`) because nothing else on that page
would.

## API endpoints

| Method | Path | Used by |
|---|---|---|
| GET | `/api/user` | shell user card, refreshed at most every 5 minutes |
| POST | `/api/logout` | shell and public-nav log-out |

## Permissions & gating

- The shell renders from the **cached** user, so its conditional links are a
  convenience, not a boundary. Every admin, shop-admin and catalogue permission is
  enforced server-side; a hand-typed URL gets a 403 from the API.
- Each page runs its own `requireAuth()` before doing anything else.

## Edge cases & known limits

- **`js/nav.js` is out of date.** It offers only the pre-POS pages, so a visitor on
  the landing page who is logged in sees a navigation that omits half the app.
- **Nothing in either navigation reaches `billing.html`.** A platform admin has to
  type the URL; the admin sidebar has no Billing entry either, despite the comment
  in `shell.js` saying admins reach it from there.
- **The cached user can be five minutes stale.** A permission granted by a shop
  admin does not change the cashier's sidebar until the cache expires or they log
  out and back in.
- **The theme key is `tax-calculator-theme`**, which is confusing to anyone reading
  `localStorage` for the first time.
- **The till has no sidebar and no way back** except the browser's back button or
  a typed URL.
- **No keyboard shortcuts** anywhere in the shell (backlog milestone M34), and no
  focus trap in the mobile drawer.
- The sidebar brand mark is a hard-coded 🇵🇰 emoji; there is no per-shop branding
  in the app chrome.
- **Page heading text lives in each page's own markup** (`.topbar-heading h1`);
  only the icon in front of it comes from the shell.
