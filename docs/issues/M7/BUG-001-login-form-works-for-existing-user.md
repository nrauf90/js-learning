# BUG-001 - login form works for existing user

- **Milestone:** M7
- **Found during:** regression up to M7
- **Date:** 2026-07-30
- **Status:** closed (dashboard no longer calls gated API on load; shows upsell instead — `js/dashboard.js`, `js/api.js`)

## How to reproduce

1. Start API: `npm run dev:api` (port 8000)
2. Start UI: `npm start` (port 3000)
3. Re-run harness: `npm run qa:milestone -- M7`
4. Inspect test: `e2e/tests/m2-auth.spec.js` → "login form works for existing user"

## Expected

See assertions in the Playwright test file.

## Actual

```
Error: page.evaluate: Execution context was destroyed, most likely because of a navigation
```

## Evidence

- Screenshot: `docs/issues/M7/shot-1785419635827.png`
- Test: `e2e/tests/m2-auth.spec.js`

## Links

- `docs/milestones/M7-subscription-gate.md`
- `docs/tasks/M7-tasks.md`
- Issues index: `docs/issues/README.md`
