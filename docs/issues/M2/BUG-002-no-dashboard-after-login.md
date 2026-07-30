# BUG-002 - no dashboard page after login

- **Milestone:** M2
- **Found during:** manual UX review (post-M3)
- **Date:** 2026-07-30
- **Status:** closed
- **Resolution:** Added `dashboard.html` + `js/dashboard.js`. Post-login redirect now goes to dashboard. Shared `js/nav.js` on all pages shows Tax / Dashboard / Cash Flow and Log out when authenticated.

## How to reproduce (before fix)

1. Start frontend + API (`npm start`, `npm run dev:api`)
2. Register or log in at `login.html`
3. Observe redirect to tax calculator (`index.html`) with no summary home
4. No `dashboard.html` existed; nav missing on most pages; login page still visible when already logged in

## Expected

After login, user lands on a **dashboard** showing:

- One-week expense report (daily breakdown)
- Total income for the week
- Total expenses for the week
- Remaining balance (income − expenses)

Shared menu bar on all pages: Tax, Dashboard (when logged in), Cash Flow, Log in / Log out.

Logged-in users visiting `login.html` or `signup.html` are redirected to the dashboard.

## Actual (before fix)

- No dashboard page
- Only `cashflow.html` had a partial nav; still showed “Log in” when token present
- Auth redirect defaulted to `index.html`
- Login/signup pages did not redirect authenticated users

## Links

- `docs/milestones/M2-authentication.md`
- `docs/tasks/M2-tasks.md`
- `docs/tasks/M4-tasks.md` — full weekly report API + charts (M4)
- `dashboard.html`, `js/dashboard.js`, `js/nav.js`
- Issues index: `docs/issues/README.md`
