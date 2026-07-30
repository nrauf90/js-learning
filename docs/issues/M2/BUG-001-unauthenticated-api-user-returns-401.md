# BUG-001 - unauthenticated /api/user returns 401

- **Milestone:** M2
- **Found during:** regression up to M2
- **Date:** 2026-07-30
- **Status:** closed
- **Resolution:** API now renders JSON for `api/*` (`shouldRenderJsonWhen`). E2E sends `Accept: application/json`. Without Accept, unauthenticated guests no longer hit a missing web login redirect (was HTTP 500).

## How to reproduce

1. Start API: `npm run dev:api` (port 8000)
2. `curl -i http://127.0.0.1:8000/api/user -H "Accept: application/json"`

## Expected

HTTP 401 JSON

## Actual (before fix)

HTTP 500 when `Accept` header missing

## Links

- `docs/milestones/M2-authentication.md`
- `docs/tasks/M2-tasks.md`
- Issues index: `docs/issues/README.md`
