# BUG-001 - sandbox checkout activates subscription via API

- **Milestone:** M6
- **Found during:** regression up to M6
- **Date:** 2026-07-30
- **Status:** closed (auth rate limit hit during full regression; relaxed to 120/min in local/testing — see `backend/routes/api.php`)

## How to reproduce

1. Start API: `npm run dev:api` (port 8000)
2. Start UI: `npm start` (port 3000)
3. Re-run harness: `npm run qa:milestone -- M6`
4. Inspect test: `e2e/tests/m6-billing.spec.js` → "sandbox checkout activates subscription via API"

## Expected

See assertions in the Playwright test file.

## Actual

```
Error: expect(received).toBe(expected) // Object.is equality

Expected: 201
Received: 429
```

## Evidence

- Screenshot: n/a
- Test: `e2e/tests/m6-billing.spec.js`

## Links

- `docs/milestones/M6-billing.md`
- `docs/tasks/M6-tasks.md`
- Issues index: `docs/issues/README.md`
