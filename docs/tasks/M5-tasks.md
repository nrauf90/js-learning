# M5 — Free tax calculator tasks

Most nav/landing work moved to M4 (T8–T12). Remaining verification only.

- [x] **M5-T1** Shared nav in `js/nav.js` (delivered in M3/M4)
- [x] **M5-T2** Nav on all pages including landing + calculator
- [x] **M5-T3** Ensure tax calculator scripts unchanged; run `npm test`
- [x] **M5-T4** Tax Calculator link → `calculator.html`; home → `index.html`
- [x] **M5-T5** Document in README that tax tool is free, no account required

---

## Completion log

### M5-T3 — done 2026-07-30

**Modified files**
- (verification only) — `js/tax-calculator.js`, `js/app.js` unchanged

**QA notes**
1. `npm test` — 96 passed.
2. `npm run lint` — all JS files OK.

### M5-T5 — done 2026-07-30

**Modified files**
- `README.md` — free tax calculator section, URL table, no-login note

**QA notes**
1. README states calculator + landing widget require no account.

### M5 milestone QA — done 2026-07-30

**Modified files**
- `e2e/tests/m5-tax.spec.js` — guest calculator access, nav, landing widget, tax computation
- `package.json` — `qa:m5` script

**QA notes**
1. `npm run qa:milestone -- M5` — 23 passed (M1–M5 regression).
