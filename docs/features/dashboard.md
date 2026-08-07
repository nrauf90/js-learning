# Dashboard

## What it is

The landing screen after login: a seven-day cash-flow summary with two Chart.js
charts and a day-by-day breakdown. It is also where the trial countdown and the
"your trial has ended" message are surfaced.

It is the oldest screen in the app and, unlike the rest, it has **not** been
reworked for the POS: it reads the manual cash ledger, not the till.

## How it works

`js/dashboard.js` boots in this order:

1. `initTheme()` with a callback that re-renders the charts on a theme toggle —
   their colours are read from CSS custom properties, so a toggle would otherwise
   leave them in the old palette.
2. `initShell({ current: 'dashboard' })` — the sidebar.
3. `requireAuth()` — redirect to `login.html?next=dashboard.html` without a token.
4. `GET /api/billing/subscription`. If there is neither an active subscription nor
   a live trial, the page stops here. There is **no buy button** — shops are
   activated by the platform admin — so the only useful thing this screen can say
   is who to ask and what to quote, and it prints the shop name and email from the
   response's `account` block: *"Your 7-day free trial has ended. Please contact
   the administrator to have it renewed. Quote: Al-Madina Kiryana · owner@…"*.
   A live trial shows a "N days remaining" banner that says the same thing.
5. `GET /api/cash-entries` — the paginated list, first page.

Rendering: `renderWeek()` totals income, expense and net for the window, fills the
four stat tiles, lists all seven days (including empty ones, muted), and hands the
per-day map to `renderCharts()` — a filled line chart of income vs expenses and a
bar chart of the daily net, coloured green above zero and red below.

### The week is Monday–Sunday

`weekRangeISO()` anchors on the current Monday (`(getDay() + 6) % 7`, so Sunday
belongs to the week that began six days earlier rather than starting a new one)
and runs to the following Sunday — the same definition the weekly report and the
sales stats use. The dashboard and the weekly report get read side by side, and
two different definitions of "this week" showing two different totals is how an
owner learns to distrust both.

Dates are built at **noon** rather than midnight so a daylight-saving shift cannot
roll the date back a day, and `inWeek()` compares `YYYY-MM-DD` strings
lexicographically, which needs no date parsing at all.

## Screens / files

| Layer | File |
|---|---|
| Page | `dashboard.html` |
| Controller | `js/dashboard.js` |
| Shell | `js/shell.js` |
| Theme | `js/theme.js` |
| API | `CashEntryController::index()`, `BillingController::subscription()` |

Chart.js loads from a CDN in `dashboard.html`; `renderCharts()` returns early if
`Chart` is undefined, so a blocked CDN costs the charts and nothing else.

## API endpoints

| Method | Path | What it does |
|---|---|---|
| GET | `/api/billing/subscription` | Subscription + trial status, and the add-on states |
| GET | `/api/cash-entries` | First page of manual entries (50 by default) |

## Permissions & gating

- `auth:sanctum`.
- `/api/cash-entries` is behind the `subscribed` gate; `/api/billing/subscription`
  is not, which is what lets the page render its own "subscribe" message rather
  than failing blank.
- Cash entries are scoped to `$user->id`, so a staff account sees **its own**
  manual entries and not the shop's. See [cash-flow.md](./cash-flow.md).

## Edge cases & known limits

- **It reads the cash ledger, not the till.** Sales no longer post a
  `cash_entries` row, so the "income" the dashboard shows is the day book's
  closing count, not the day's takings. The sales and reports screens are the ones
  wired to the POS.
- **The window is fixed at the current Monday–Sunday week** and cannot be changed;
  there is no customisation (backlog milestone M32). The card is still headed
  "Last 7 days", which is not quite what it shows.
- **It reads only the first page** of `/api/cash-entries` (50 entries) and filters
  the week out of it client-side. A busy week with more entries than that is
  under-counted, and `data.totals` — which the API now returns and which is summed
  over every matching row — is ignored.
- The page has no link to the till, and the sidebar is the only navigation.
