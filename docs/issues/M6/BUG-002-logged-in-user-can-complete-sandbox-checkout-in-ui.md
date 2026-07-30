# BUG-002 - logged-in user can complete sandbox checkout in UI

- **Milestone:** M6
- **Found during:** regression up to M6
- **Date:** 2026-07-30
- **Status:** closed (same root cause as BUG-001 — register 429 during regression; fixed via local/testing throttle)

## How to reproduce

1. Start API: `npm run dev:api` (port 8000)
2. Start UI: `npm start` (port 3000)
3. Re-run harness: `npm run qa:milestone -- M6`
4. Inspect test: `e2e/tests/m6-billing.spec.js` → "logged-in user can complete sandbox checkout in UI"

## Expected

See assertions in the Playwright test file.

## Actual

```
Error: expect(received).toBe(expected) // Object.is equality

Expected: 201
Received: 429
```

## Evidence

- Screenshot: `docs/issues/M6/shot-1785419331137.png`
- Test: `e2e/tests/m6-billing.spec.js`

## Links

- `docs/milestones/M6-billing.md`
- `docs/tasks/M6-tasks.md`
- Issues index: `docs/issues/README.md`
