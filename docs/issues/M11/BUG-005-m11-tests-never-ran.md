# BUG-005 - M11 e2e suite never actually ran under `qa:milestone`

- **Milestone:** M11
- **Found during:** review of admin panel built externally
- **Date:** 2026-07-30
- **Status:** closed — added `'M11'` to `MILESTONE_ORDER` in `e2e/playwright.config.js`

## How to reproduce (pre-fix)

1. `npm run qa:milestone -- M11`

## Expected

Runs `m1-*.spec.js` through `m11-*.spec.js`, including the new
`e2e/tests/m11-admin-panel.spec.js`.

## Actual (pre-fix)

`scripts/qa-milestone.mjs` was correctly updated to include `'M11'`, but
`e2e/playwright.config.js` has its **own** hardcoded
`MILESTONE_ORDER` array used to compute which spec files to include, and
it still ended at `'M10'`. `MILESTONE_ORDER.indexOf('M11')` returned
`-1`, so `Math.max(0, -1)` fell back to `0` and only `m1-*.spec.js`
tests ran — silently. This is why the person who built the admin panel
believed they had "errors": their QA run only ever exercised M1 (and
failed there because Playwright's browser binaries weren't installed
yet), while the actual M11 admin-panel tests were never executed at all.

## Evidence

- `e2e/playwright.config.js` now lists `'M11'`; `npm run qa:milestone -- M11` runs 63 tests (M1–M11), all passing.

## Links

- `docs/milestones/M11-admin-panel.md`
- `docs/tasks/M11-tasks.md`
- Issues index: `docs/issues/README.md`
