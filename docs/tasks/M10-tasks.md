# M10 — Premium UI, profile & PDF reports tasks

Depends on M9 complete.

- [x] **M10-T1** Backend — `PUT /api/user/profile` + `PUT /api/user/password` + PHPUnit
- [x] **M10-T2** App shell — `js/shell.js` sidebar + glassmorphism design system in CSS
- [x] **M10-T3** Dashboard — line/bar charts (Chart.js), stat cards, shell layout
- [x] **M10-T4** Convert cashflow/reports/billing pages to the app shell
- [x] **M10-T5** Profile page — `profile.html` + `js/profile.js`
- [x] **M10-T6** Reports — PDF download via jsPDF
- [x] **M10-T7** Landing — GSAP + ScrollTrigger + Lenis (`js/motion.js`)
- [x] **M10-T8** E2E spec + harness/lint updates + docs

## Completion log

### M10-T1 — done 2026-07-30

**Modified files**
- `backend/app/Http/Controllers/Api/AuthController.php` — `updateProfile()` (name), `updatePassword()` (current password check + hash)
- `backend/routes/api.php` — `PUT /api/user/profile`, `PUT /api/user/password` under `auth:sanctum`
- `backend/tests/Feature/ProfileTest.php` — 4 tests (name update, wrong current password → 422, password update + re-login, guest → 401)

**QA notes**
1. `cd backend && php artisan test --filter=ProfileTest` — 4 pass.
2. `curl -X PUT http://localhost:8000/api/user/password -H "Authorization: Bearer <token>" -d '{"current_password":"...","password":"...","password_confirmation":"..."}'`

### M10-T2 — done 2026-07-30

**Modified files**
- `js/shell.js` — new sidebar shell: nav with SVG icons, user card (cached + refreshed from `/api/user`), logout, mobile drawer + overlay + Escape
- `css/styles.css` — appended premium block: glass tokens, aurora `body::before`, glass `.calculator-card`/`.auth-card`, gradient buttons with shine sweep, hover lifts, full `.app-*`/`.sidebar-*` layout, entrance `rise-in` animation, ≤960px drawer styles, print rules

**QA notes**
1. Open `dashboard.html` logged in — sidebar with active link, user initials avatar, log out works.
2. Resize < 960px — hamburger opens slide-in drawer; overlay/Escape closes it.

### M10-T3 — done 2026-07-30

**Modified files**
- `dashboard.html` — app-shell layout; 4th stat card `#week-count`; `#trend-chart` (line) + `#net-chart` (bar); Chart.js CDN
- `js/dashboard.js` — `renderCharts()` (income vs expense line, daily net bar with pos/neg colors), theme-aware colors re-rendered on toggle, entries-count stat

**QA notes**
1. Log in as subscribed user with entries → dashboard shows both charts and correct totals.
2. Toggle theme — chart colors update.

### M10-T4 — done 2026-07-30

**Modified files**
- `cashflow.html`, `reports.html`, `billing.html` — top header replaced with sidebar + topbar app shell (headings/IDs preserved for E2E)
- `js/cashflow.js`, `js/reports.js`, `js/billing.js` — `initNav` → `initShell`
- `js/nav.js` — Profile link added to the public-page nav for logged-in users

**QA notes**
1. All three pages show the sidebar; guest visits still redirect to login.

### M10-T5 — done 2026-07-30

**Modified files**
- `profile.html` — Account details card (name, read-only email) + Change password card
- `js/profile.js` — loads `/api/user`, saves name via `PUT /api/user/profile`, password via `PUT /api/user/password`, validation-error surfacing, sidebar user card refresh

**QA notes**
1. `http://localhost:3000/profile.html` — change password, log out, log in with the new one.

### M10-T6 — done 2026-07-30

**Modified files**
- `reports.html` — `#download-pdf` button in toolbar; jsPDF 2.5.2 CDN
- `js/reports.js` — `downloadPdf()`: title/period, stat boxes, doughnut chart images via `canvas.toDataURL`, category tables with page breaks; saves `cashflow-<mode>-report-<start>.pdf`

**QA notes**
1. Open Reports as subscribed user, click **Download PDF** — file downloads with totals + charts.

### M10-T7 — done 2026-07-30

**Modified files**
- `js/motion.js` — CDN loader for GSAP 3.12 + ScrollTrigger + Lenis 1.1; hero entrance timeline, `[data-reveal]`/`[data-reveal-stagger]` scroll reveals, hero parallax, Lenis smooth scroll + anchor handling; no-ops offline or with `prefers-reduced-motion`
- `js/landing.js` — calls `initMotion()`
- `index.html` — `data-reveal` attributes on About / How it works / Contact sections

**QA notes**
1. Open landing page online — hero animates in, sections reveal on scroll, wheel scroll is smoothed.
2. Block CDN (offline) — page still fully visible and usable.

### M10-T8 — done 2026-07-30

**Modified files**
- `e2e/tests/m10-premium-ui.spec.js` — 5 tests (password API + re-login, wrong current password 422, profile UI password change, dashboard sidebar + charts, real PDF download)
- `e2e/playwright.config.js`, `scripts/qa-milestone.mjs` — M10 in milestone order
- `package.json` — `qa:m10` script; lint covers `shell.js`, `profile.js`, `motion.js`
- `backend/README.md` — profile endpoints documented
- `docs/*` — this file, milestone doc, CONTEXT, README

**QA notes**
1. `npm run lint && npm test` — pass.
2. `cd backend && php artisan test` — 53 pass.
3. `npm run qa:milestone -- M10` — 45 E2E tests pass (M1–M10 regression).
