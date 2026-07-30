# Offline QA harness

Token-free Playwright regression for milestones M1–M8.

## Commands

```bash
npm run qa:milestone -- M2          # run M1 + M2
npm run qa:milestone -- M1          # run M1 only
set QA_VERBOSE=1&& npm run qa:milestone -- M2   # Windows: show server logs
```

## Layout

- `scripts/qa-milestone.mjs` — starts servers if needed, runs Playwright
- `scripts/write-bug.mjs` — creates `docs/issues/<M>/BUG-xxx.md`
- `e2e/reporters/bug-reporter.mjs` — Playwright reporter on failure
- `e2e/tests/m1-*.spec.js`, `m2-*.spec.js`, … — per-milestone specs

## Prerequisites

- `npm install` (includes `@playwright/test`)
- `npx playwright install chromium` (once)
- Backend `.env` + migrated DB
- PHP available on PATH

## Adding tests for a new milestone

1. Add `e2e/tests/m3-cashflow.spec.js` (name prefix `m3-`).
2. Specs are picked automatically when you run `npm run qa:milestone -- M3`.
