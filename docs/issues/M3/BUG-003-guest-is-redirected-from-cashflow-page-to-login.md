# BUG-003 - guest is redirected from cashflow page to login

- **Milestone:** M3
- **Status:** closed
- **Resolution:** Same as BUG-001 — URL was `http://127.0.0.1:3000/login` (clean URL). Assertion now matches `/login` and `/login.html`.

## Links

- `e2e/tests/m3-cashflow.spec.js`
