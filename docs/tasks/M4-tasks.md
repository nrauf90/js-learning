# M4 — Reports, landing & legal tasks

Depends on M3 complete.

## Landing & marketing site

- [x] **M4-T8** Landing page `index.html` — hero, about us, how it works, contact
- [x] **M4-T9** Hero mini tax widget — monthly/yearly income, total tax only (FY 2025–26 new regime)
- [x] **M4-T10** Move full calculator to `calculator.html`; link from landing + nav
- [x] **M4-T11** `privacy.html` + `terms.html` with footer links
- [x] **M4-T12** Shared nav: Home, Tax Calculator, Dashboard (logged in), Cash Flow, Log in/out
- [x] **M4-T13** E2E smoke: landing widget + full calculator page

## Reports API & UI

- [x] **M4-T1** `GET /api/reports/weekly?start=YYYY-MM-DD` — ISO week from start date
- [x] **M4-T2** `GET /api/reports/monthly?year=YYYY&month=MM`
- [x] **M4-T3** Response shape: `{ total_income, total_expense, net, by_category: [{ category, amount }] }`
- [x] **M4-T4** Frontend `reports.html` — week/month toggle, date picker
- [x] **M4-T5** Frontend `js/reports.js` — fetch and render summary cards
- [x] **M4-T6** Add Chart.js; doughnut/bar chart for category breakdown
- [x] **M4-T7** PHPUnit tests for report aggregation logic

---

## Completion log

### M4-T8 — done 2026-07-30

**Modified files**
- `index.html` — marketing landing (hero, about, how it works, contact)
- `css/styles.css` — landing, hero, footer, legal page styles
- `js/landing.js` — theme, nav, contact form

**QA notes**
1. Open `http://localhost:3000/` — hero, sections, footer links visible.
2. Scroll to About / How it works / Contact anchors.

### M4-T9 — done 2026-07-30

**Modified files**
- `index.html` — hero widget markup
- `js/landing.js` — `calculateTax` for FY 2025–26, monthly × 12

**QA notes**
1. Enter `100000` monthly → annual tax updates live.
2. Toggle Yearly with `1200000` → shows total tax only (no slab table).

### M4-T10 — done 2026-07-30

**Modified files**
- `calculator.html` — full FBR calculator (moved from old `index.html`)
- `js/app.js` — nav current `calculator`

**QA notes**
1. Open `/calculator.html` — full calculator with slabs, deductions, print.
2. `npm test` — unit tests unchanged.

### M4-T11 — done 2026-07-30

**Modified files**
- `privacy.html`, `terms.html`, `js/legal.js`
- Footer links on landing + calculator

**QA notes**
1. Open `/privacy.html` and `/terms.html` from landing footer.

### M4-T12 — done 2026-07-30

**Modified files**
- `js/nav.js` — Home + Tax Calculator links

**QA notes**
1. Guest: Home, Tax Calculator, Cash Flow, Log in.
2. Logged in: + Dashboard, Log out.

### M4-T13 — done 2026-07-30

**Modified files**
- `e2e/tests/m1-health.spec.js` — landing + calculator smoke tests

**QA notes**
1. `npm run qa:milestone -- M3` (includes updated M1 tests).

### M4-T1 — done 2026-07-30

**Modified files**
- `backend/app/Http/Controllers/Api/ReportController.php` — weekly endpoint
- `backend/app/Services/ReportAggregator.php` — aggregation logic
- `backend/routes/api.php` — route registration

**QA notes**
1. `GET /api/reports/weekly?start=2026-07-30` with Bearer token returns ISO week period + totals.

### M4-T2 — done 2026-07-30

**Modified files**
- `backend/app/Http/Controllers/Api/ReportController.php` — monthly endpoint

**QA notes**
1. `GET /api/reports/monthly?year=2026&month=7` returns calendar month totals.

### M4-T3 — done 2026-07-30

**Modified files**
- `backend/app/Services/ReportAggregator.php` — `{ total_income, total_expense, net, by_category }`

**QA notes**
1. `php artisan test --filter=ReportTest` — shape assertions pass.

### M4-T4 — done 2026-07-30

**Modified files**
- `reports.html` — weekly/monthly toggle, date + month pickers

**QA notes**
1. Log in, open `/reports.html`, switch Weekly ↔ Monthly.

### M4-T5 — done 2026-07-30

**Modified files**
- `js/reports.js` — fetch API, render summary cards + category list

**QA notes**
1. Add expenses on Cash Flow, reload Reports — totals update.

### M4-T6 — done 2026-07-30

**Modified files**
- `reports.html` — Chart.js CDN
- `js/reports.js` — doughnut chart for expense categories

**QA notes**
1. Reports page shows chart when expenses exist in period.

### M4-T7 — done 2026-07-30

**Modified files**
- `backend/tests/Feature/ReportTest.php` — 5 tests (weekly, monthly, isolation, auth)

**QA notes**
1. `cd backend && php artisan test --filter=ReportTest`

### M4 milestone QA — done 2026-07-30

**Modified files**
- `e2e/tests/m4-reports.spec.js` — guest redirect, API aggregation, UI chart
- `package.json` — `qa:m4` script

**QA notes**
1. `npm run qa:milestone -- M4` — 17 passed (M1–M4 regression).
