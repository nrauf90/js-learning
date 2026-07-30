# BUG-002 - logged-in user can add an expense in the UI

- **Milestone:** M3
- **Status:** closed
- **Resolution:** False positive. Playwright treats `<option>` as not visible by default. Fixed with `toHaveCount` + `addInitScript` for auth token.

## Links

- `e2e/tests/m3-cashflow.spec.js`
