# QA issues (offline harness)

Failures from the **token-free** Playwright harness land here.

## How to run

```bash
# After finishing a milestone — also re-runs all earlier ones (regression)
npm run qa:milestone -- M1
npm run qa:milestone -- M2
```

Requires API + DB working (`php artisan migrate`). The harness starts `:8000` and `:3000` if they are not already up.

## Bug files

| Location | Meaning |
|----------|---------|
| `docs/issues/M2/BUG-001-….md` | Bug found while QA’ing through M2 |
| Screenshot `docs/issues/M2/shot-….png` | Optional failure screenshot |

Each bug MD includes: reproduce steps, expected vs actual, test file, links to milestone/tasks.

## Workflow

1. Complete milestone tasks.
2. Run `npm run qa:milestone -- Mx`.
3. If red → fix bugs under `docs/issues/Mx/`, then re-run.
4. Set bug **Status** to `closed` when fixed.
5. Only then start the next milestone.

## Index

| Bug | Milestone | Status | Title |
|-----|-----------|--------|-------|
| [BUG-001](./M2/BUG-001-unauthenticated-api-user-returns-401.md) | M2 | closed | unauthenticated /api/user returns 401 |
