# Pakistan Income Tax Calculator & Cashflow

Daily **cash-flow tracker** plus a **free** Pakistan FBR income tax calculator — no account required for tax features.

| Feature | URL | Login required? |
|---------|-----|-----------------|
| Landing + quick tax widget | [`index.html`](index.html) / `/` | No |
| Full tax calculator (slabs, history, deductions) | [`calculator.html`](calculator.html) | **No — always free** |
| Dashboard, cash flow, reports | `dashboard.html`, `cashflow.html`, `reports.html` | Yes |

## Tax calculator (free)

The FBR salaried tax tool runs entirely in your browser. You do **not** need to sign up or subscribe to use it.

- Calculate annual and monthly tax, take-home pay, and effective rate
- **New Tax** (FY 2025-26 & 2026-27) vs **Old Tax** (FY 2020-21 through 2024-25)
- Year-by-year comparison, deductions, print/share links
- Quick estimate on the home page; full details on the calculator page

```bash
npm start   # http://localhost:3000 — open / or /calculator.html
npm test    # unit tests for tax engine
```

Cash-flow tracking and reports use the Laravel API (`backend/`) and require an account. Billing/subscription gating applies only to those features (see `docs/` milestones M6–M7).

## Quick start (full app)

```bash
# Frontend
npm start

# Backend (cash flow + reports)
cd backend
php artisan migrate
php artisan db:seed
php artisan serve
```

- Frontend: [http://localhost:3000](http://localhost:3000)
- API: [http://localhost:8000](http://localhost:8000)

## Scripts

| Command | Description |
|---------|-------------|
| `npm start` | Serve frontend on port 3000 |
| `npm test` | Run tax calculator unit tests |
| `npm run lint` | Syntax-check JavaScript files |
| `npm run dev:api` | Start Laravel API on port 8000 |
| `npm run qa:milestone -- M5` | Offline Playwright regression (M1–M5) |

## Tax years covered

| Regime | Fiscal years |
|--------|----------------|
| New Tax | FY 2025-26, FY 2026-27 |
| Old Tax | FY 2020-21 through 2024-25 |

## Documentation

Project milestones and agent handoff: [`docs/README.md`](docs/README.md) · [`docs/CONTEXT.md`](docs/CONTEXT.md)

## Disclaimer

Tax estimates are based on published FBR salaried individual slabs. Actual liability may differ. Consult a tax advisor for personalized advice.
